<?php

declare(strict_types=1);

namespace SaniTube\Ui\Http\Controllers\Deduplication;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use SaniTube\Assets\Exceptions\AssetTrashException;
use SaniTube\Assets\Models\Asset;
use SaniTube\Assets\Services\TrashAsset;
use SaniTube\Deduplication\Exceptions\DuplicateDecisionException;
use SaniTube\Deduplication\Models\DuplicateRelation;
use SaniTube\Deduplication\Services\DecideDuplicate;
use SaniTube\Ui\Http\Requests\Deduplication\TrashAssetRequest;

/**
 * The four things a reviewer can do with a finding.
 *
 * **Nothing here writes a column.** Every action calls the service that owns
 * it, because `$relation->update(['decision' => ...])` would look like the same
 * thing and would skip the refusal that stops a resubmitted form rewriting who
 * decided and when — and `$asset->update(['trashed_at' => now()])` would skip
 * the check that a track still needs its master. A controller that assigns
 * state is a second, weaker copy of a domain service.
 *
 * **Deciding and trashing are separate routes on purpose.** Confirming a
 * duplicate is not a decision to lose a file, and an interface that did both in
 * one request would make it one. A reviewer confirms, and then — as a distinct
 * act, with its own reason and its own audit line — sets a copy aside.
 *
 * Refusals come back as `withErrors` carrying the domain's reason code. The
 * English in an exception is written for a developer reading a log; the
 * interface translates the code.
 */
final class DuplicateActionController
{
    public function confirm(Request $request, DuplicateRelation $relation, DecideDuplicate $decider): RedirectResponse
    {
        /** @var User $reviewer */
        $reviewer = $request->user();

        try {
            $decider->confirm($relation, $reviewer);
        } catch (DuplicateDecisionException $exception) {
            return back()->withErrors(['decision' => $exception->reason]);
        }

        return back();
    }

    public function reject(Request $request, DuplicateRelation $relation, DecideDuplicate $decider): RedirectResponse
    {
        /** @var User $reviewer */
        $reviewer = $request->user();

        try {
            $decider->reject($relation, $reviewer);
        } catch (DuplicateDecisionException $exception) {
            return back()->withErrors(['decision' => $exception->reason]);
        }

        return back();
    }

    public function trash(TrashAssetRequest $request, Asset $asset, TrashAsset $trash): RedirectResponse
    {
        /** @var User $reviewer */
        $reviewer = $request->user();

        try {
            $trash->trash($asset, $reviewer, $request->reason());
        } catch (AssetTrashException $exception) {
            return back()->withErrors(['asset' => $exception->reason]);
        }

        return back();
    }

    public function restore(Request $request, Asset $asset, TrashAsset $trash): RedirectResponse
    {
        /** @var User $reviewer */
        $reviewer = $request->user();

        try {
            $trash->restore($asset, $reviewer);
        } catch (AssetTrashException $exception) {
            return back()->withErrors(['asset' => $exception->reason]);
        }

        return back();
    }
}
