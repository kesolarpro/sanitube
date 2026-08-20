<?php

declare(strict_types=1);

namespace SaniTube\Enrichment;

use SaniTube\AI\AiSchema;

/**
 * What a model proposed about one recording.
 *
 * **A suggestion in ADR-0016's sense — the weakest of the three levels.** Every
 * field here is a guess by something that has never heard this catalogue, and
 * none of it is catalogue data until a person says so. The field names carry
 * `suggested` for that reason: a variable called `$title` gets assigned to a
 * track eventually, and one called `$suggestedTitle` gets questioned first.
 *
 * **Read from a schema, never from prose.** {@see schema()} is what the model
 * is actually made to fill in, and {@see fromPayload()} is the only way one of
 * these is built. Anything the schema did not require is absent rather than
 * guessed, and a payload that cannot be read is a refusal rather than a
 * partially filled object — because a half-read suggestion looks exactly like a
 * cautious one.
 */
final readonly class SuggestedMetadata
{
    /**
     * Bumped when the *meaning* of these fields changes, so a later pass can
     * tell which stored suggestions it may still compare.
     */
    public const SCHEMA_VERSION = '1';

    /**
     * @param  list<string>  $genres
     * @param  list<string>  $moods
     * @param  list<string>  $themes
     * @param  int|null  $confidenceBasisPoints  0–10000, the model's own opinion of its
     *                                           answer. Recorded, and never acted on: a
     *                                           confidence a model reports about itself is
     *                                           more output, not evidence about the output.
     */
    public function __construct(
        public ?string $title = null,
        public ?string $language = null,
        public array $genres = [],
        public array $moods = [],
        public array $themes = [],
        public ?string $description = null,
        public ?int $confidenceBasisPoints = null,
        public ?string $rationale = null,
    ) {}

    /**
     * The shape both vendors are made to answer in.
     *
     * Kept to the strict subset both documents describe — an object, named
     * properties, explicit types, arrays of one type. No nested objects, no
     * unions, no `oneOf`: those are exactly where "strict" stops being
     * supported, and a schema that quietly falls back to unstructured output is
     * worse than one that was never asked for.
     *
     * Nothing is `required`. A model that genuinely cannot tell what language a
     * mostly-instrumental recording is in should leave the field out, and
     * requiring it would make it invent one — which is the failure mode this
     * platform can least afford, because an invented language reaches a
     * distributor looking exactly like a real one.
     */
    public static function schema(): AiSchema
    {
        return new AiSchema(
            'track_metadata_suggestion',
            'Editorial suggestions about one music recording, based only on the evidence supplied. Leave a field out entirely rather than guessing at it.',
            [
                'type' => 'object',
                'properties' => [
                    'suggested_title' => [
                        'type' => 'string',
                        'description' => 'A title a listener would recognise. Not a filename, not a description.',
                    ],
                    'suggested_language' => [
                        'type' => 'string',
                        'description' => 'ISO 639-1 two-letter code for the language sung or spoken, e.g. "fr". Omit for instrumental or unclear audio.',
                    ],
                    'suggested_genres' => [
                        'type' => 'array',
                        'items' => ['type' => 'string'],
                        'description' => 'Broad musical genres.',
                    ],
                    'suggested_moods' => [
                        'type' => 'array',
                        'items' => ['type' => 'string'],
                        'description' => 'How the recording feels to a listener.',
                    ],
                    'suggested_themes' => [
                        'type' => 'array',
                        'items' => ['type' => 'string'],
                        'description' => 'What the words are about, where there are words.',
                    ],
                    'suggested_description' => [
                        'type' => 'string',
                        'description' => 'Two or three sentences a person could edit into catalogue copy.',
                    ],
                    'confidence' => [
                        'type' => 'integer',
                        'description' => 'How confident you are, from 0 to 100.',
                    ],
                    'rationale' => [
                        'type' => 'string',
                        'description' => 'What in the supplied evidence led to these suggestions. Quote it where you can.',
                    ],
                ],
            ],
        );
    }

    /**
     * @param  array<array-key, mixed>  $payload
     * @return self|null null when the answer was not usable as a whole
     */
    public static function fromPayload(array $payload): ?self
    {
        $suggestion = new self(
            title: self::text($payload, 'suggested_title'),
            language: self::language($payload),
            genres: self::terms($payload, 'suggested_genres'),
            moods: self::terms($payload, 'suggested_moods'),
            themes: self::terms($payload, 'suggested_themes'),
            description: self::text($payload, 'suggested_description'),
            confidenceBasisPoints: self::confidence($payload),
            rationale: self::text($payload, 'rationale'),
        );

        // A well-formed object with nothing in it is not a cautious answer, it
        // is no answer -- and storing one would put a row in the queue asking a
        // person to review a blank.
        return $suggestion->isEmpty() ? null : $suggestion;
    }

    public function isEmpty(): bool
    {
        return $this->title === null
            && $this->language === null
            && $this->description === null
            && $this->genres === []
            && $this->moods === []
            && $this->themes === [];
    }

    /**
     * @param  array<array-key, mixed>  $payload
     */
    private static function text(array $payload, string $key): ?string
    {
        $value = $payload[$key] ?? null;

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /**
     * @param  array<array-key, mixed>  $payload
     */
    private static function language(array $payload): ?string
    {
        $value = self::text($payload, 'suggested_language');

        // A code, or nothing. A model asked for ISO 639-1 sometimes answers
        // "French", and storing that in a column every other part of the
        // platform reads as a code would put a word where a key belongs.
        return $value !== null && preg_match('/^[A-Za-z]{2,3}$/', $value) === 1
            ? strtolower($value)
            : null;
    }

    /**
     * @param  array<array-key, mixed>  $payload
     * @return list<string>
     */
    private static function terms(array $payload, string $key): array
    {
        $value = $payload[$key] ?? null;

        if (! is_array($value)) {
            return [];
        }

        $terms = [];

        foreach ($value as $term) {
            if (! is_string($term) || trim($term) === '') {
                continue;
            }

            $trimmed = trim($term);

            // Deduplicated case-insensitively, because a model asked for
            // genres will cheerfully return "Ambient" and "ambient" in one
            // answer and a reviewer should not have to read both.
            if (! in_array(mb_strtolower($trimmed), array_map(mb_strtolower(...), $terms), true)) {
                $terms[] = $trimmed;
            }
        }

        return $terms;
    }

    /**
     * @param  array<array-key, mixed>  $payload
     */
    private static function confidence(array $payload): ?int
    {
        $value = $payload['confidence'] ?? null;

        if (! is_int($value) && ! is_float($value)) {
            return null;
        }

        // Asked for out of 100 because that is what a model answers well, and
        // stored in basis points because a percentage in a float column is a
        // rounding argument nobody wins. Clamped rather than refused: a model
        // answering 120 is being enthusiastic, not unreadable.
        return (int) round(max(0, min(100, (float) $value)) * 100);
    }
}
