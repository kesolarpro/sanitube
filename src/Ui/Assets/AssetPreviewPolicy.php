<?php

declare(strict_types=1);

namespace SaniTube\Ui\Assets;

use App\Models\User;
use SaniTube\Assets\Enums\AssetKind;
use SaniTube\Assets\Enums\AssetStatus;
use SaniTube\Assets\Models\Asset;
use SaniTube\Storage\StorageManager;
use Throwable;

/**
 * The one place that decides whether a preview URL may be minted.
 *
 * This exists as a class rather than as a few `if`s in a controller because it
 * is the boundary that protects the masters. A master is the thing SaniTube
 * exists to hold; handing out a URL to one is the most consequential read the
 * interface can perform, and the rules for it should be readable in one place
 * by somebody auditing them.
 *
 * The rules, as arbitrated:
 *
 *   - only previewable kinds — a licence document or a distribution export is
 *     not preview material, and neither is anything this list does not name;
 *   - an audio master must be VERIFIED, because playing an unverified master
 *     hands out bytes nobody has confirmed;
 *   - masters additionally require the ability to write the catalogue. Reading
 *     that a master exists is ordinary catalogue work; *listening to it* is
 *     closer to holding it, and MEMBER is a role that can browse without being
 *     trusted with the material itself;
 *   - if the storage provider cannot issue an expiring URL, the answer is
 *     "preview unavailable". Never a permanent URL, and never making the object
 *     public as a fallback.
 */
final readonly class AssetPreviewPolicy
{
    /**
     * Kinds this interface will ever preview.
     *
     * An allow-list, not a deny-list: a kind added to the domain later is
     * refused until somebody decides it should not be, which is the safe
     * direction for a list that governs access to audio.
     */
    private const PREVIEWABLE = [
        AssetKind::AudioMaster,
        AssetKind::AudioDerivative,
        AssetKind::Preview,
        AssetKind::Stem,
        AssetKind::Artwork,
        AssetKind::Video,
    ];

    /** Kinds that are the material itself rather than a rendering of it. */
    private const RESTRICTED = [
        AssetKind::AudioMaster,
        AssetKind::Stem,
    ];

    public function __construct(private StorageManager $storage) {}

    public function decide(Asset $asset, User $user): AssetPreviewDecision
    {
        if (! in_array($asset->kind, self::PREVIEWABLE, true)) {
            return AssetPreviewDecision::NotPreviewable;
        }

        if (in_array($asset->kind, self::RESTRICTED, true)) {
            if (! $user->role->canWriteCatalogue()) {
                return AssetPreviewDecision::NotPermitted;
            }

            // Compared against the case directly rather than adding an
            // `isVerified()` helper to the domain enum: a screen's needs are
            // not a reason to grow the domain's surface.
            if ($asset->status !== AssetStatus::Verified) {
                return AssetPreviewDecision::NotVerified;
            }
        }

        if (! $this->providerCanIssueTemporaryUrls($asset)) {
            return AssetPreviewDecision::ProviderCannotIssueTemporaryUrls;
        }

        return AssetPreviewDecision::Allowed;
    }

    /**
     * Whether the backend that actually holds this asset can sign for it.
     *
     * `$asset->disk` is the SaniTube provider name recorded when the bytes were
     * written, and it is immutable from that moment. Resolving it — rather than
     * `defaultName()` — is what makes the preview follow the *asset*.
     *
     * The failure path is deliberately total. A provider that has been removed
     * from configuration, or that cannot be reached, means no preview. Falling
     * back to the default would sign a URL against a backend that does not hold
     * these bytes: at best a broken link, at worst a link to a *different*
     * object that happens to share the path.
     */
    private function providerCanIssueTemporaryUrls(Asset $asset): bool
    {
        try {
            return $this->storage->provider($asset->disk)->supportsTemporaryUrls();
        } catch (Throwable) {
            return false;
        }
    }
}
