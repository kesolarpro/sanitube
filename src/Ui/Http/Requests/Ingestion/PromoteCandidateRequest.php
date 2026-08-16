<?php

declare(strict_types=1);

namespace SaniTube\Ui\Http\Requests\Ingestion;

use Illuminate\Foundation\Http\FormRequest;
use SaniTube\Ingestion\Services\PromoteTrackCandidate;

/**
 * The one request the interface can make that creates a Track.
 *
 * Everything except the decision is optional. A candidate already carries a
 * suggested title and a reviewer has already looked at it; the point of
 * promotion is the looking, not the retyping. What a reviewer *does* supply
 * overrides every suggestion, because the suggestion was a guess.
 *
 * `override` and `note` travel together and the pairing is **not** enforced
 * here. "Overruling the machine requires a stated reason" is a property of the
 * decision, and it lives in {@see PromoteTrackCandidate}
 * where the API and this form both reach it. A second copy in a form request
 * is a second copy that can drift.
 */
final class PromoteCandidateRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The route's `auth`, `active` and `can.role:catalogue` middleware own
        // this. A permission decided in two places is one that disagrees.
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
            'language_code' => ['sometimes', 'string', 'max:8'],
            'is_instrumental' => ['sometimes', 'boolean'],
            'is_explicit' => ['sometimes', 'boolean'],
            'genre_primary' => ['sometimes', 'nullable', 'string', 'max:64'],
            'recording_year' => ['sometimes', 'nullable', 'integer', 'min:1900', 'max:2200'],
            'p_line' => ['sometimes', 'nullable', 'string', 'max:255'],

            'override' => ['sometimes', 'boolean'],
            'note' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function catalogueAttributes(): array
    {
        /** @var array<string, mixed> $attributes */
        $attributes = $this->safe()->except(['override', 'note']);

        // An empty text input is "nothing supplied", not "set this to the
        // empty string". Leaving it in would overwrite a perfectly good
        // suggestion with nothing.
        return array_filter(
            $attributes,
            static fn (mixed $value): bool => ! (is_string($value) && trim($value) === ''),
        );
    }

    public function override(): bool
    {
        return $this->boolean('override');
    }

    public function note(): ?string
    {
        $note = $this->input('note');

        return is_string($note) && trim($note) !== '' ? trim($note) : null;
    }
}
