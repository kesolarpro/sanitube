<?php

declare(strict_types=1);

namespace SaniTube\Ui\Http\Requests\Enrichment;

use Illuminate\Foundation\Http\FormRequest;
use SaniTube\Catalog\Services\SetTrackMetadata;

/**
 * The reviewer's version of what a model said.
 *
 * **Edit-before-accept is the ordinary case, not a special one.** A model's
 * title is right about half the time and *nearly* right rather often, and a
 * queue offering only accept-or-reject would train people to accept
 * nearly-right. So every field is optional: sending none accepts what the model
 * proposed, and sending one replaces it.
 *
 * The rules here are deliberately loose. This is the interface's boundary —
 * enough to refuse a payload of the wrong *shape* — and the catalogue's own
 * rules, which are the ones that matter, are enforced by
 * {@see SetTrackMetadata} regardless of what arrives. A frontend cannot get
 * past those by sending a different payload, and that is the point: this class
 * is convenience, never authorisation.
 */
final class AcceptSuggestionRequest extends FormRequest
{
    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'nullable', 'string', 'max:255'],
            'language_code' => ['sometimes', 'nullable', 'string', 'max:16'],
            'genres' => ['sometimes', 'array', 'max:8'],
            'genres.*' => ['string', 'max:128'],
        ];
    }

    /**
     * Only the keys the reviewer actually sent.
     *
     * The distinction matters: an absent `title` means "use what the model
     * said", and a present empty one means "do not write a title". Collapsing
     * the two would either make clearing a field impossible or make every
     * acceptance silently blank the fields the form did not show.
     *
     * @return array<string, mixed>
     */
    public function edited(): array
    {
        $sent = [];

        foreach (['title', 'language_code', 'genres'] as $field) {
            if ($this->has($field)) {
                $sent[$field] = $this->input($field);
            }
        }

        return $sent;
    }
}
