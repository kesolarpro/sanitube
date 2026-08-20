<?php

declare(strict_types=1);

namespace SaniTube\Assets\Services;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use SaniTube\Assets\Exceptions\AssetTrashException;
use SaniTube\Assets\Models\Asset;
use SaniTube\Audit\Enums\AuditAction;
use SaniTube\Audit\Services\RecordAuditEvent;
use SaniTube\Catalog\Models\Track;
use SaniTube\Releases\Models\Release;

/**
 * Setting a master aside, without losing it.
 *
 * **Nothing here deletes anything, and nothing in this platform does.** The
 * object stays where it is, the checksum still describes it, the fingerprint
 * still matches it, and coming back is a matter of clearing three columns.
 * That is the entire design: deduplication produces proposals, a person
 * answers them, and the worst thing a wrong answer can do is hide a file until
 * somebody notices.
 *
 * The rule that makes this safe to build at all is that **no similarity score
 * reaches this class.** Trashing is an act by a named person with a reason,
 * and the only thing a duplicate finding contributes is that somebody was
 * shown a screen. There is no threshold, however high, that causes an asset to
 * be set aside on its own.
 *
 * Permanent deletion is not implemented and is not an oversight. It would need
 * its own policy — a minimum age, a dry run, an explicit confirmation naming
 * what is about to go, and a guarantee that the object being addressed is the
 * one that was reviewed. None of that is cheaper than leaving the bytes alone,
 * and disk is the cheapest thing in this system to be wrong about.
 */
final readonly class TrashAsset
{
    public function __construct(private RecordAuditEvent $audit) {}

    /**
     * @param  string  $reason  a machine-readable code chosen by the reviewer, never a sentence
     */
    public function trash(Asset $asset, User $by, string $reason): Asset
    {
        if ($asset->isTrashed()) {
            throw AssetTrashException::alreadyTrashed();
        }

        // An upload still in flight is the staging sweep's business, not a
        // reviewer's: trashing one would race the write that is still
        // happening.
        if (! $asset->status->isImmutable()) {
            throw AssetTrashException::notSettled();
        }

        $this->refuseIfTheCatalogueNeedsIt($asset);

        DB::transaction(function () use ($asset, $by, $reason): void {
            $asset->forceFill([
                'trashed_at' => Carbon::now(),
                'trashed_by_user_id' => $by->id,
                'trash_reason' => mb_substr($reason, 0, 64),
            ])->save();
        });

        $this->audit->record(
            AuditAction::AssetTrashed,
            subjectUuid: $asset->uuid,
            reason: mb_substr($reason, 0, 64),
        );

        return $asset->refresh();
    }

    public function restore(Asset $asset, User $by): Asset
    {
        if (! $asset->isTrashed()) {
            throw AssetTrashException::notTrashed();
        }

        DB::transaction(function () use ($asset): void {
            $asset->forceFill([
                'trashed_at' => null,
                'trashed_by_user_id' => null,
                'trash_reason' => null,
            ])->save();
        });

        // `$by` is not written to the row -- the columns describe the trashing,
        // and there is no trashing any more. Who restored it is the audit
        // log's job, which is where a reviewer would look for it anyway.
        $this->audit->record(AuditAction::AssetRestored, subjectUuid: $asset->uuid);

        return $asset->refresh();
    }

    /**
     * Whether anything the catalogue treats as truth still points at it.
     *
     * Only the two references that would leave the catalogue broken: a track
     * without its master, a release without its cover. An ingestion item or a
     * candidate pointing at a trashed asset is a historical record of an
     * import, and history is supposed to mention things that were later set
     * aside.
     */
    private function refuseIfTheCatalogueNeedsIt(Asset $asset): void
    {
        if (Track::query()->where('master_asset_id', $asset->getKey())->exists()) {
            throw AssetTrashException::inUse('a track master');
        }

        if (Release::query()->where('cover_asset_id', $asset->getKey())->exists()) {
            throw AssetTrashException::inUse('a release cover');
        }
    }

    /**
     * Assets a listing should show, unless it is the trash listing.
     *
     * @param  Builder<Asset>  $query
     * @return Builder<Asset>
     */
    public static function excludeTrashed(Builder $query): Builder
    {
        return $query->whereNull('trashed_at');
    }
}
