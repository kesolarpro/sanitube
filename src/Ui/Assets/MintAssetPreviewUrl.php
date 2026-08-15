<?php

declare(strict_types=1);

namespace SaniTube\Ui\Assets;

use App\Models\User;
use DateTimeImmutable;
use Illuminate\Support\Facades\Log;
use SaniTube\Assets\Models\Asset;
use SaniTube\Storage\StorageManager;
use Throwable;

/**
 * Mints a short-lived URL for an asset the policy has already allowed.
 *
 * A signed URL is a **bearer credential**: whoever holds it can fetch the
 * object until it expires, with no further check. Three consequences shape this
 * class.
 *
 * **It is minted on demand, never on a page load.** Rendering an asset's detail
 * screen must not create a credential the reader did not ask for — that widens
 * both the number of live URLs and the window in which each is useful, for
 * every asset anybody merely looked at.
 *
 * **It is never persisted.** Storing one in the database would turn a
 * fifteen-minute credential into a permanent one sitting in every backup.
 *
 * **It is never logged in full.** A URL in a log file is a URL in whatever
 * reads log files. What is recorded is that a preview was minted, for which
 * asset, by whom — the audit fact, without the credential.
 */
final readonly class MintAssetPreviewUrl
{
    public function __construct(
        private StorageManager $storage,
        private AssetPreviewPolicy $policy,
    ) {}

    /**
     * @return array{decision: AssetPreviewDecision, url: string|null, expires_at: string|null}
     */
    public function for(Asset $asset, User $user, ?DateTimeImmutable $now = null): array
    {
        $decision = $this->policy->decide($asset, $user);

        if (! $decision->isAllowed()) {
            return ['decision' => $decision, 'url' => null, 'expires_at' => null];
        }

        $expiresAt = ($now ?? new DateTimeImmutable)->modify('+'.$this->ttlSeconds().' seconds');

        try {
            // The asset's own recorded provider. Never the default: two
            // backends can hold the same path, and signing the wrong one would
            // hand out a link to somebody else's object.
            $url = $this->storage
                ->provider($asset->disk)
                ->temporaryUrl($asset->path, $expiresAt);
        } catch (Throwable) {
            // A provider that accepted the capability check but then failed is
            // reported as unavailable, not as a permission problem — and the
            // exception is not surfaced, because a storage error message quotes
            // the request it failed on.
            return [
                'decision' => AssetPreviewDecision::ProviderCannotIssueTemporaryUrls,
                'url' => null,
                'expires_at' => null,
            ];
        }

        // The audit fact, without the credential. Note what is absent: the URL,
        // the path, the disk.
        Log::info('Asset preview minted.', [
            'asset_uuid' => $asset->uuid,
            'asset_kind' => $asset->kind->value,
            // The provider *name*, which is configuration rather than location.
            // Not the path, not the disk's bucket, not the URL.
            'storage_provider' => $asset->disk,
            'user_uuid' => $user->uuid,
            'expires_at' => $expiresAt->format(DATE_ATOM),
        ]);

        return [
            'decision' => AssetPreviewDecision::Allowed,
            'url' => $url,
            'expires_at' => $expiresAt->format(DATE_ATOM),
        ];
    }

    /**
     * Clamped rather than trusted.
     *
     * A misconfigured environment setting a day-long TTL would quietly turn
     * every preview into a day-long credential, and nothing would look wrong.
     */
    public function ttlSeconds(): int
    {
        $configured = (int) config('assets.preview.ttl_seconds', 900);

        return max(300, min(900, $configured));
    }
}
