<?php

declare(strict_types=1);

namespace SaniTube\Api\Http\Requests\V1;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The one request in v1 that can create a Track.
 *
 * Everything here is optional except the decision itself. A candidate already
 * carries a suggested title, and the point of promotion is that a person has
 * looked at it — not that they have retyped it. What they *do* supply
 * overrides every suggestion, because the suggestion was a guess and the
 * person is the source of truth.
 *
 * `override` and `note` travel together and the pairing is enforced in the
 * domain service rather than here: the rule is "overruling the machine
 * requires a stated reason", which is a property of the decision, not of the
 * HTTP request that carried it.
 */
final class PromoteTrackCandidateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'string', 'max:255'],
            'version_title' => ['sometimes', 'nullable', 'string', 'max:255'],
            // ISO 639-1, or one of the ISO 639-2 special codes. Validated
            // properly by ContentLanguage; this only bounds the length.
            'language_code' => ['sometimes', 'string', 'max:8'],
            'is_instrumental' => ['sometimes', 'boolean'],
            'is_explicit' => ['sometimes', 'boolean'],
            'duration_ms' => ['sometimes', 'integer', 'min:1'],
            'bpm' => ['sometimes', 'integer', 'min:1', 'max:400'],
            'musical_key' => ['sometimes', 'nullable', 'string', 'max:16'],
            'genre_primary' => ['sometimes', 'nullable', 'string', 'max:64'],
            'genre_secondary' => ['sometimes', 'nullable', 'string', 'max:64'],
            'recording_year' => ['sometimes', 'integer', 'min:1900', 'max:2200'],
            'p_line' => ['sometimes', 'nullable', 'string', 'max:255'],

            // Says this recording was already distributed elsewhere and
            // therefore already carries an ISRC the platform must preserve
            // rather than mint. Only a person knows this.
            'already_distributed' => ['sometimes', 'boolean'],

            'override' => ['sometimes', 'boolean'],
            'note' => ['sometimes', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function catalogueAttributes(): array
    {
        return $this->safe()->except(['override', 'note']);
    }

    public function override(): bool
    {
        return (bool) $this->boolean('override');
    }

    public function note(): ?string
    {
        $note = $this->input('note');

        return is_string($note) && trim($note) !== '' ? trim($note) : null;
    }
}
