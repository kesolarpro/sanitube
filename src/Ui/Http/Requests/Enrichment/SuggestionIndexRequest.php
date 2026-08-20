<?php

declare(strict_types=1);

namespace SaniTube\Ui\Http\Requests\Enrichment;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use SaniTube\Enrichment\Enums\SuggestionStatus;

/**
 * What a reviewer may narrow the queue by.
 *
 * A closed list fed from the enum itself, so the picker and the rule cannot
 * drift apart — the same shape DEDUP-003 settled on for trash reasons.
 */
final class SuggestionIndexRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'status' => ['sometimes', 'nullable', 'string', Rule::in(array_map(
                static fn (SuggestionStatus $case): string => $case->value,
                SuggestionStatus::cases(),
            ))],
            'cursor' => ['sometimes', 'nullable', 'string', 'max:512'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function filters(): array
    {
        $status = $this->query('status');

        return ['status' => is_string($status) && $status !== '' ? $status : null];
    }

    public function cursor(): ?string
    {
        $cursor = $this->query('cursor');

        return is_string($cursor) && $cursor !== '' ? $cursor : null;
    }
}
