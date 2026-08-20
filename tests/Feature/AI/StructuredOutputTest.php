<?php

declare(strict_types=1);

namespace Tests\Feature\AI;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use SaniTube\AI\AiCompletion;
use SaniTube\AI\AiManager;
use SaniTube\AI\AiPrompt;
use SaniTube\AI\AiSchema;
use SaniTube\AI\Models\AiInvocation;
use SaniTube\AI\Services\AiCircuitBreaker;
use SaniTube\AI\Services\AiSpendGuard;
use SaniTube\AI\Services\RunPrompt;
use SaniTube\AI\Testing\FakeAiProvider;
use Tests\TestCase;

/**
 * ENR-001 — asking a model for a shape instead of hoping for one.
 *
 * **Never parse free-form prose when a schema can be required.** A model asked
 * for JSON in an instruction returns JSON most of the time; the rest of the
 * time it returns a fenced code block, an apology, or a paragraph starting
 * "Sure!". Both vendors this platform speaks to can be *made* to answer in a
 * schema, and these tests are the proof that both are actually asked to.
 *
 * The two do it completely differently, which is the point of the abstraction:
 *
 *   - **OpenAI** — `response_format: {type: "json_schema", json_schema: {name,
 *     schema, strict}}`, verified against the official OpenAPI specification's
 *     `ResponseFormatJsonSchema`.
 *   - **Anthropic** — a tool whose `input_schema` is the shape, forced with
 *     `tool_choice: {type: "tool", name}`, answered in a `tool_use` block whose
 *     `input` is the object. Verified against the official tool-use
 *     documentation.
 *
 * A caller writes one `AiSchema` and never learns which of those it got.
 */
final class StructuredOutputTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'sk-structured-0123456789abcdefghij';

    private const OPENAI = 'https://api.openai.example/v1';

    private const CLAUDE = 'https://api.anthropic.example/v1';

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();

        config([
            'ai.providers.openai.key' => self::KEY,
            'ai.providers.openai.base_url' => self::OPENAI,
            'ai.providers.openai.model' => 'gpt-4o-mini',
            'ai.providers.claude.key' => self::KEY,
            'ai.providers.claude.base_url' => self::CLAUDE,
            'ai.providers.claude.model' => 'claude-sonnet-4-5',
        ]);

        $this->app->forgetInstance(AiManager::class);
    }

    // ------------------------------------------------------------- the shape

    #[Test]
    public function a_schema_name_both_vendors_reject_is_refused_here_first(): void
    {
        // Anthropic publishes the regex outright; OpenAI's specification says
        // a-z, A-Z, 0-9, underscores and dashes to 64. Same constraint, stated
        // twice, so it is checked once -- here -- rather than discovered as a
        // 400 from whichever vendor happens to be configured.
        foreach (['has spaces', 'has.dots', '', str_repeat('a', 65), 'héllo'] as $name) {
            try {
                new AiSchema($name, 'a description', ['type' => 'object']);
                $this->fail(sprintf('[%s] is not a name both vendors accept.', $name));
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }

        foreach (['track_metadata', 'Track-Metadata-2', str_repeat('a', 64)] as $name) {
            $this->assertSame($name, (new AiSchema($name, 'a description', []))->name);
        }
    }

    #[Test]
    public function a_schema_without_a_description_is_refused(): void
    {
        // Not pedantry. Anthropic's guidance is blunt that the description is
        // the single largest factor in whether a schema gets filled in
        // correctly, and a schema named `track_metadata` with nothing else is
        // one the model has to guess the purpose of.
        $this->expectException(InvalidArgumentException::class);

        new AiSchema('track_metadata', '   ', ['type' => 'object']);
    }

    #[Test]
    public function overriding_the_model_does_not_quietly_drop_the_shape(): void
    {
        // `withModel()` copies the prompt field by field, which is the kind of
        // constructor that silently loses whatever was added to it last. A
        // dropped schema is not a visible break: the call still succeeds, the
        // model answers in prose, and the answer is refused somewhere else
        // entirely.
        $schema = $this->schema();
        $prompt = new AiPrompt(instruction: 'Describe this.', input: 'a transcript', schema: $schema);

        $rerouted = $prompt->withModel('gpt-4o');

        $this->assertSame('gpt-4o', $rerouted->model);
        $this->assertSame($schema, $rerouted->schema);
        $this->assertTrue($rerouted->requiresStructuredOutput());
    }

    // ------------------------------------------------------------- OpenAI

    #[Test]
    public function openai_is_asked_for_a_strict_json_schema(): void
    {
        Http::fake([self::OPENAI.'/chat/completions' => Http::response($this->openAiPayload('{"title":"Une chanson"}'))]);

        $completion = $this->ask('openai', $this->schema());

        $this->assertSame(['title' => 'Une chanson'], $completion->json());

        Http::assertSent(function (Request $request): bool {
            $format = $request->data()['response_format'] ?? null;

            $this->assertIsArray($format);
            $this->assertSame('json_schema', $format['type']);
            $this->assertSame('track_metadata', $format['json_schema']['name']);
            $this->assertTrue($format['json_schema']['strict']);
            $this->assertSame(
                ['type' => 'object', 'properties' => ['title' => ['type' => 'string']], 'required' => ['title']],
                $format['json_schema']['schema'],
            );

            return true;
        });
    }

    #[Test]
    public function openai_without_a_schema_still_gets_the_weaker_json_request(): void
    {
        // `json_object` is not removed, it is demoted. It guarantees the answer
        // parses and nothing about what is in it, which is worth having when
        // no shape is known and worth replacing when one is.
        Http::fake([self::OPENAI.'/chat/completions' => Http::response($this->openAiPayload('{"anything":1}'))]);

        $this->ask('openai', null, expectsJson: true);

        Http::assertSent(fn (Request $request): bool => ($request->data()['response_format'] ?? null) === ['type' => 'json_object']);
    }

    #[Test]
    public function an_openai_refusal_is_not_read_as_an_answer(): void
    {
        // A strict schema request the model declined comes back with a null
        // content. Reading that as an empty answer would store a suggestion
        // nobody made.
        // Three shapes, and each must say *why* rather than merely being
        // unusable. An empty answer carrying no explanation is the state an
        // operator asks "why did nothing get suggested last night" about, and
        // a silent one has no answer for them.
        //
        // **A sequence, not three `Http::fake()` calls.** Re-faking the same
        // URL does not replace the earlier stub -- the first registered one
        // keeps answering -- so a loop that re-fakes tests its first case
        // three times and reports three passes. That is how this test was
        // written first, and mutation is what caught it.
        Http::fakeSequence(self::OPENAI.'/chat/completions')
            ->push($this->refusal(null))
            ->push($this->refusal(''))
            ->push($this->refusal('   '));

        foreach ([null, '', '   '] as $shape) {
            $completion = $this->ask('openai', $this->schema());

            $this->assertFalse($completion->isUsable(), sprintf('content %s is not an answer.', var_export($shape, true)));
            $this->assertNotNull(
                $completion->failureMessage,
                'An answer this adapter could not read must say so, not merely be empty.',
            );
        }

        Http::assertSentCount(3);
    }

    /**
     * @return array<string, mixed>
     */
    private function refusal(?string $content): array
    {
        return [
            'model' => 'gpt-4o-mini',
            'choices' => [['message' => ['content' => $content, 'refusal' => 'I cannot help with that.']]],
        ];
    }

    // ------------------------------------------------------------- Anthropic

    #[Test]
    public function anthropic_is_asked_with_a_forced_tool(): void
    {
        Http::fake([self::CLAUDE.'/messages' => Http::response($this->claudePayload(['title' => 'Une chanson']))]);

        $completion = $this->ask('claude', $this->schema());

        // The caller sees the same thing it saw from OpenAI. That is the whole
        // abstraction: one schema in, one decoded object out, and the vendor
        // difference never leaves the adapter.
        $this->assertSame(['title' => 'Une chanson'], $completion->json());

        Http::assertSent(function (Request $request): bool {
            $body = $request->data();

            $this->assertSame([[
                'name' => 'track_metadata',
                'description' => 'What a listener would say this recording is.',
                'input_schema' => ['type' => 'object', 'properties' => ['title' => ['type' => 'string']], 'required' => ['title']],
                'strict' => true,
            ]], $body['tools']);

            // Forced. With `auto` the model may answer in prose and the schema
            // becomes advice.
            $this->assertSame(['type' => 'tool', 'name' => 'track_metadata'], $body['tool_choice']);

            $this->assertArrayNotHasKey('response_format', $body, 'Anthropic has no response_format; sending one is a 400.');

            return true;
        });
    }

    #[Test]
    public function anthropic_sends_no_tool_when_there_is_no_schema(): void
    {
        Http::fake([self::CLAUDE.'/messages' => Http::response([
            'model' => 'claude-sonnet-4-5',
            'content' => [['type' => 'text', 'text' => 'a paragraph']],
        ])]);

        $completion = $this->ask('claude', null);

        $this->assertSame('a paragraph', $completion->text);

        Http::assertSent(function (Request $request): bool {
            $this->assertArrayNotHasKey('tools', $request->data());
            $this->assertArrayNotHasKey('tool_choice', $request->data());

            return true;
        });
    }

    #[Test]
    public function a_tool_block_for_something_else_is_not_read_as_the_answer(): void
    {
        // An account with a server tool enabled can produce a tool block this
        // request never asked for. Taking the first one would read an
        // unrelated call's arguments as a suggestion about a recording.
        Http::fake([self::CLAUDE.'/messages' => Http::response([
            'model' => 'claude-sonnet-4-5',
            'content' => [
                ['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'web_search', 'input' => ['query' => 'unrelated']],
                ['type' => 'tool_use', 'id' => 'toolu_2', 'name' => 'track_metadata', 'input' => ['title' => 'Une chanson']],
            ],
        ])]);

        $this->assertSame(['title' => 'Une chanson'], $this->ask('claude', $this->schema())->json());
    }

    #[Test]
    public function anthropic_answering_in_prose_to_a_schema_is_a_failure(): void
    {
        // Nothing to salvage and nothing salvaged. A schema request answered
        // in text is a model that ignored the contract.
        Http::fake([self::CLAUDE.'/messages' => Http::response([
            'model' => 'claude-sonnet-4-5',
            'content' => [['type' => 'text', 'text' => '{"title":"Une chanson"}']],
        ])]);

        $this->assertFalse($this->ask('claude', $this->schema())->isUsable());
    }

    // -------------------------------------------------- malformed is a failure

    #[Test]
    public function an_answer_that_is_not_in_the_shape_is_a_failure_and_not_partial_data(): void
    {
        // The rule the whole ticket exists for. The tempting alternative is to
        // salvage -- pull the object out of a fenced block, take the first `{`
        // -- and every one of those turns a model that ignored the contract
        // into a suggestion somebody accepts without knowing it was
        // reconstructed.
        $fake = (new FakeAiProvider)->willReturn(new AiCompletion(
            provider: 'fake',
            text: "Sure! Here you go:\n```json\n{\"title\":\"Une chanson\"}\n```",
            model: 'fake-1',
        ));

        $completion = $this->runThroughFake($fake, $this->schema());

        $this->assertFalse($completion->isUsable());
        $this->assertNull($completion->json());

        // And the model's text is not carried into the failure: it is output
        // over this installation's catalogue data, and this message reaches a
        // screen.
        $this->assertStringNotContainsString('Une chanson', (string) $completion->failureMessage);
    }

    #[Test]
    public function a_malformed_structured_answer_is_recorded_as_a_failure_in_the_ledger(): void
    {
        // "Why did nothing get suggested last night" only has an answer if the
        // ledger says these failed rather than succeeded.
        $fake = (new FakeAiProvider)->willReturn(new AiCompletion(provider: 'fake', text: 'not json at all', model: 'fake-1'));

        $this->runThroughFake($fake, $this->schema());

        $invocation = AiInvocation::query()->latest('id')->first();

        $this->assertInstanceOf(AiInvocation::class, $invocation);
        $this->assertFalse((bool) $invocation->succeeded);
    }

    #[Test]
    public function a_well_shaped_answer_is_left_alone(): void
    {
        $fake = (new FakeAiProvider)->willReturn(new AiCompletion(
            provider: 'fake',
            text: '{"title":"Une chanson"}',
            model: 'fake-1',
        ));

        $completion = $this->runThroughFake($fake, $this->schema());

        $this->assertTrue($completion->isUsable());
        $this->assertSame(['title' => 'Une chanson'], $completion->json());
    }

    #[Test]
    public function an_unstructured_prompt_may_still_answer_in_prose(): void
    {
        // The enforcement is scoped to callers that asked for a shape. A
        // prompt that wanted a sentence gets its sentence.
        $fake = (new FakeAiProvider)->willReturn(new AiCompletion(provider: 'fake', text: 'a paragraph', model: 'fake-1'));

        $this->assertTrue($this->runThroughFake($fake, null)->isUsable());
    }

    // ------------------------------------------------------------ the body

    #[Test]
    public function a_vendor_error_body_never_reaches_the_caller(): void
    {
        // The same rule TRN-002 established for transcription, applied to the
        // adapters that had not got it yet. A vendor's error quotes the
        // request back -- and this request carried both a key and catalogue
        // data. Redaction masks the key; it cannot mask the prompt.
        // Re-faking in a loop is safe *here*, and only here, because the two
        // stubs match different endpoints: an unmatched stub falls through to
        // the next one. Two same-URL fakes would not -- the first would answer
        // both. Do not turn this into a sequence, and do not copy the shape to
        // a same-endpoint loop.
        foreach ([
            ['claude', self::CLAUDE.'/messages'],
            ['openai', self::OPENAI.'/chat/completions'],
        ] as [$provider, $endpoint]) {
            Http::fake([$endpoint => Http::response([
                'error' => ['message' => 'Invalid key '.self::KEY.' for prompt about Une chanson'],
            ], 401)]);

            $completion = $this->ask($provider, $this->schema());
            $message = (string) $completion->failureMessage;

            $this->assertFalse($completion->isUsable());
            $this->assertStringContainsString('401', $message);
            $this->assertStringNotContainsString(self::KEY, $message);
            $this->assertStringNotContainsString('Une chanson', $message);
            $this->assertStringNotContainsString('Invalid key', $message);
        }
    }

    // ------------------------------------------------------------ fixtures

    private function schema(): AiSchema
    {
        return new AiSchema(
            'track_metadata',
            'What a listener would say this recording is.',
            [
                'type' => 'object',
                'properties' => ['title' => ['type' => 'string']],
                'required' => ['title'],
            ],
        );
    }

    private function ask(string $provider, ?AiSchema $schema, bool $expectsJson = false): AiCompletion
    {
        return $this->app->make(RunPrompt::class)->handle(
            'suggest_title',
            new AiPrompt(
                instruction: 'Describe this recording.',
                input: 'a transcript',
                expectsJson: $expectsJson,
                schema: $schema,
            ),
            $provider,
        );
    }

    private function runThroughFake(FakeAiProvider $fake, ?AiSchema $schema): AiCompletion
    {
        $manager = new AiManager([], AiManager::DISABLED);
        $manager->register('fake', $fake);

        return (new RunPrompt(
            $manager,
            $this->app->make(AiSpendGuard::class),
            $this->app->make(AiCircuitBreaker::class),
        ))->handle(
            'suggest_title',
            new AiPrompt(instruction: 'Describe this recording.', input: 'a transcript', schema: $schema),
            'fake',
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function openAiPayload(string $content): array
    {
        return [
            'model' => 'gpt-4o-mini',
            'choices' => [['message' => ['role' => 'assistant', 'content' => $content]]],
            'usage' => ['prompt_tokens' => 40, 'completion_tokens' => 9],
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function claudePayload(array $input): array
    {
        return [
            'model' => 'claude-sonnet-4-5',
            'stop_reason' => 'tool_use',
            'content' => [[
                'type' => 'tool_use',
                'id' => 'toolu_01A09q90qw90lq917835lq9',
                'name' => 'track_metadata',
                'input' => $input,
            ]],
            'usage' => ['input_tokens' => 40, 'output_tokens' => 9],
        ];
    }
}
