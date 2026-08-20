<?php

declare(strict_types=1);

namespace SaniTube\Ui\Http\Requests\Ingestion;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A decision about several candidates at once.
 *
 * Bounded in the rules rather than only in the controller, because the bound is
 * the difference between "a selection" and "everything, submitted by a script".
 * The ceiling is configuration, so an installation with a fast queue can raise
 * it without a code change.
 *
 * A rejection reason is required and a promotion takes none. That asymmetry is
 * the domain's: rejecting is the decision somebody will later ask about, and
 * "rejected, no reason given" is a row nobody can act on.
 */
final class BulkReviewRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'candidates' => ['required', 'array', 'min:1', 'max:'.$this->ceiling()],
            'candidates.*' => ['string', 'size:36'],
            'decision' => ['required', Rule::in(['promote', 'reject'])],
            'reason' => ['required_if:decision,reject', 'nullable', 'string', 'min:5', 'max:2000'],
        ];
    }

    /**
     * @return list<string>
     */
    public function candidates(): array
    {
        $uuids = $this->input('candidates');

        if (! is_array($uuids)) {
            return [];
        }

        $clean = [];

        foreach ($uuids as $uuid) {
            if (is_string($uuid)) {
                $clean[] = $uuid;
            }
        }

        // Not deduplicated here. The controller resolves these against the
        // candidates that exist, and `whereIn(...)->pluck('uuid')` returns each
        // row once — so a second `array_unique` was a line that could not
        // change an outcome. BULK-002's mutation pass proved it: removing it
        // broke no test, because the guarantee lives in the query.
        return $clean;
    }

    public function promoting(): bool
    {
        return $this->input('decision') === 'promote';
    }

    public function reason(): string
    {
        $reason = $this->input('reason');

        return is_string($reason) ? trim($reason) : '';
    }

    public function ceiling(): int
    {
        return max(1, (int) config('ingestion.max_bulk_review', 200));
    }
}
