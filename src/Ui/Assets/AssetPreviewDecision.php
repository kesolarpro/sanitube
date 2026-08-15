<?php

declare(strict_types=1);

namespace SaniTube\Ui\Assets;

/**
 * Whether an asset may be previewed, and if not, why.
 *
 * A refusal carries a *reason code* rather than a sentence, so the interface can
 * translate it and the caller cannot accidentally render an internal
 * explanation. The reasons are deliberately coarse: a refusal that explained
 * exactly which condition failed would be a probe for finding assets in
 * interesting states.
 */
enum AssetPreviewDecision: string
{
    case Allowed = 'ALLOWED';

    /** The kind of asset is not something this interface previews at all. */
    case NotPreviewable = 'NOT_PREVIEWABLE';

    /** A master that has not been verified. Playing an unverified master would
     *  be handing out bytes nobody has confirmed are the bytes we meant. */
    case NotVerified = 'NOT_VERIFIED';

    /** The signed-in person may see the asset exists but not hear or see it. */
    case NotPermitted = 'NOT_PERMITTED';

    /** The storage provider cannot issue an expiring URL. Never a reason to
     *  make the object public instead. */
    case ProviderCannotIssueTemporaryUrls = 'PREVIEW_UNAVAILABLE';

    public function isAllowed(): bool
    {
        return $this === self::Allowed;
    }
}
