<?php

declare(strict_types=1);

namespace SaniTube\AI;

use InvalidArgumentException;

/**
 * The shape an answer has to arrive in.
 *
 * **Never parse free-form prose when a schema can be required.** A model asked
 * for JSON in an instruction returns JSON most of the time, and the remaining
 * fraction is a fenced code block, an apology, or a paragraph beginning "Sure!"
 * — and every one of those is a parser somebody has to write and a failure mode
 * somebody has to discover in production. Both vendors this platform speaks to
 * can be *made* to answer in a schema, so they are.
 *
 * The two do it differently and the adapters absorb that difference, which is
 * the entire reason this class describes a shape rather than a request body:
 *
 *   - **OpenAI** takes `response_format: {type: "json_schema", json_schema:
 *     {name, schema, strict}}`, per the official OpenAPI specification.
 *   - **Anthropic** takes a tool whose `input_schema` is the shape, forced with
 *     `tool_choice: {type: "tool", name}`, and answers in a `tool_use` block
 *     whose `input` is the object — per the official tool-use documentation.
 *
 * Neither of those belongs in a domain service, and a caller that had to know
 * which one it was talking to would be a caller that breaks when the
 * configuration changes.
 *
 * **`strict` is asked for in both cases.** OpenAI documents that only a subset
 * of JSON Schema is supported when strict is true; Anthropic documents `strict`
 * as the way to guarantee a tool call matches the schema exactly. Keep schemas
 * to that subset — objects, named properties, explicit types, enums, arrays of
 * one type — and both vendors will hold to them.
 */
final readonly class AiSchema
{
    /**
     * Both vendors publish the same constraint on the name, independently:
     * Anthropic states the regex outright, and OpenAI's specification says
     * a-z, A-Z, 0-9, underscores and dashes to a maximum of 64. Enforced here
     * rather than discovered as a 400 from whichever one is configured.
     */
    private const NAME = '/^[a-zA-Z0-9_-]{1,64}$/';

    /**
     * @param  array<string, mixed>  $schema  a JSON Schema object
     *
     * @throws InvalidArgumentException when the name is not one both vendors accept
     */
    public function __construct(
        public string $name,
        public string $description,
        public array $schema,
    ) {
        if (preg_match(self::NAME, $name) !== 1) {
            throw new InvalidArgumentException(sprintf(
                'An AI schema name must match %s; [%s] does not.',
                self::NAME,
                $name,
            ));
        }

        // A description is required rather than optional, and not for the
        // vendor's sake. Anthropic's guidance is blunt about it -- the
        // description is the single largest factor in whether a schema is
        // filled in correctly -- and a schema named `track_metadata` with no
        // explanation is one the model has to guess the purpose of.
        if (trim($description) === '') {
            throw new InvalidArgumentException('An AI schema needs a description; it is what the model reads to decide what to put in it.');
        }
    }
}
