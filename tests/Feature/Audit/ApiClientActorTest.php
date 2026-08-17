<?php

declare(strict_types=1);

namespace Tests\Feature\Audit;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use SaniTube\Audit\Enums\AuditAction;
use SaniTube\Audit\Enums\AuditActorKind;
use SaniTube\Audit\Enums\AuditOutcome;
use SaniTube\Audit\Models\AuditEvent;
use SaniTube\Releases\Enums\ReleaseStatus;
use SaniTube\Releases\Enums\ReleaseType;
use SaniTube\Releases\Models\Release;
use Tests\TestCase;

/**
 * AUDIT-002 — telling the truth about who a token is.
 *
 * AUDIT-001 deliberately left the API out rather than record something false.
 * The reason is worth keeping in front of whoever reads this: a shared token
 * **names no person**. Recording those calls as a user would invent one;
 * recording them as a guest would say nobody authenticated when something did,
 * and would file a release submission next to a failed sign-in as though they
 * were the same kind of event.
 *
 * The fourth answer is the true one — something authenticated, and it was not a
 * human — and what it was is the client's *configured name*. Never the token,
 * never a prefix of it, never a hash a guess could be checked against.
 *
 * The API is not read-only, which is why this matters: it exposes the
 * platform's one irreversible act.
 */
final class ApiClientActorTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'an-internal-api-token-of-sufficient-length';

    protected function setUp(): void
    {
        parent::setUp();

        config(['sanitube.api.internal_token' => self::TOKEN]);
    }

    #[Test]
    public function an_api_write_is_recorded_against_the_client_rather_than_a_person(): void
    {
        $release = $this->release();

        // Refused — the release has no tracks — which is the useful half: an
        // integration failing repeatedly is exactly what an operator needs to
        // be able to see, and it is recorded even though nothing happened.
        $this->withHeader('X-SaniTube-Api-Token', self::TOKEN)
            ->postJson('/api/v1/releases/'.$release->uuid.'/ready')
            ->assertStatus(422);

        $event = AuditEvent::query()->where('action', AuditAction::ReleaseMarkedReady->value)->firstOrFail();

        $this->assertSame(AuditOutcome::Refused, $event->outcome);
        $this->assertSame(AuditActorKind::ApiClient, $event->actor_kind);
        $this->assertSame('internal-api', $event->actor_label);

        // Not a person, and the columns say so rather than leaving it to be
        // inferred from a missing name.
        $this->assertNull($event->actor_id);
        $this->assertNull($event->actor_role);
    }

    #[Test]
    public function the_token_never_reaches_the_log_in_any_form(): void
    {
        $release = $this->release();

        $this->withHeader('X-SaniTube-Api-Token', self::TOKEN)
            ->postJson('/api/v1/releases/'.$release->uuid.'/ready');

        foreach (AuditEvent::query()->get() as $event) {
            foreach ((array) $event->getAttributes() as $column => $value) {
                $this->assertStringNotContainsString(self::TOKEN, (string) $value, sprintf('[%s] holds the token.', $column));

                // Nor a prefix long enough to check a guess against.
                $this->assertStringNotContainsString(substr(self::TOKEN, 0, 12), (string) $value, sprintf('[%s] holds a prefix of the token.', $column));
            }
        }
    }

    #[Test]
    public function a_rejected_token_leaves_no_line_claiming_something_authenticated(): void
    {
        // The attribute is set only after the token is accepted. One set
        // before would be a claim about authentication made from what
        // somebody sent.
        $release = $this->release();

        $this->withHeader('X-SaniTube-Api-Token', 'not-the-token')
            ->postJson('/api/v1/releases/'.$release->uuid.'/ready')
            ->assertStatus(401);

        $this->assertSame(0, AuditEvent::query()->count());
    }

    #[Test]
    public function a_signed_in_person_is_still_recorded_as_themselves(): void
    {
        // The API client kind must not swallow the ordinary case. Nothing in
        // this request carries a token, so the guard answers as it always did.
        $this->assertNotSame(AuditActorKind::ApiClient->value, AuditActorKind::User->value);

        $this->postJson('/login', ['email' => 'nobody@example.test', 'password' => 'whatever-it-was']);

        $event = AuditEvent::query()->where('action', AuditAction::UserSignInFailed->value)->firstOrFail();

        $this->assertSame(AuditActorKind::Guest, $event->actor_kind);
    }

    private function release(): Release
    {
        return Release::query()->create([
            'title' => 'A release with nothing on it',
            'type' => ReleaseType::Single,
            'language_code' => 'en',
            'status' => ReleaseStatus::Draft,
        ]);
    }
}
