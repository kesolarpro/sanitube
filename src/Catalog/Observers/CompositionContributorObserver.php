<?php

declare(strict_types=1);

namespace SaniTube\Catalog\Observers;

use Illuminate\Database\Eloquent\Builder;
use SaniTube\Catalog\Exceptions\CompositionShareException;
use SaniTube\Catalog\Models\CompositionContributor;

/**
 * Enforces I7 — credited shares per role may not exceed 100%.
 *
 * Checked per role rather than across the whole composition: writers and
 * publishers each account to 100% of their own side, so summing them together
 * would reject perfectly ordinary paperwork.
 */
final class CompositionContributorObserver
{
    public function saving(CompositionContributor $credit): void
    {
        if ($credit->share === null) {
            return;
        }

        $existing = CompositionContributor::query()
            ->where('composition_id', $credit->composition_id)
            ->where('role', $credit->role->value)
            ->when($credit->exists, fn (Builder $query): Builder => $query->whereKeyNot($credit->getKey()))
            ->sum('share');

        $total = (float) $existing + (float) $credit->share;

        if (round($total, 6) > CompositionContributor::MAX_SHARE_PER_ROLE) {
            throw CompositionShareException::exceedsHundred(
                $credit->role->value,
                rtrim(rtrim(number_format($total, 6, '.', ''), '0'), '.'),
            );
        }
    }
}
