<?php

declare(strict_types=1);

namespace SaniTube\Ui\Http\Controllers\Assets;

use Illuminate\Http\JsonResponse;
use SaniTube\Assets\Exceptions\AssetStorageException;
use SaniTube\Assets\Models\Asset;
use SaniTube\Assets\Services\AssetStorageService;
use SaniTube\Assets\Services\AssetVerificationService;
use SaniTube\Assets\Services\UploadAdmission;
use SaniTube\Audit\Enums\AuditAction;
use SaniTube\Audit\Services\RecordAuditEvent;
use SaniTube\Ui\Http\Requests\Assets\RelayedUploadRequest;
use Throwable;

/**
 * Uploading a file *through* the application.
 *
 * The companion to {@see DirectUploadController}, and deliberately not a
 * replacement for it. Object storage gets the direct path, because a shared
 * cPanel account cannot receive a two-gigabyte body and raising four PHP limits
 * to pretend otherwise is not a deployment story.
 *
 * **But `LocalStorageProvider` does not implement `SupportsDirectUpload`**, and
 * local storage is the shipped default and the only option on a host with no
 * object storage. Without this controller, such an installation cannot accept a
 * single file from a browser — which is exactly the state the product-path
 * audit found the application in.
 *
 * So: one screen, two paths, and the interface says which one it is using and
 * what the ceiling is. The alternative — making local storage mint a signed
 * PUT — would look like the direct path while still traversing PHP, and would
 * report a two-gigabyte ceiling to a host that will refuse at eight megabytes.
 *
 * **The media type is read from the bytes**, never from the extension and never
 * from the `Content-Type` the browser claimed.
 */
final class RelayedUploadController
{
    public function __invoke(
        RelayedUploadRequest $request,
        UploadAdmission $admission,
        AssetStorageService $assets,
        AssetVerificationService $verification,
        RecordAuditEvent $audit,
    ): JsonResponse {
        $kind = $request->kind();
        $file = $request->upload();

        if (! $admission->allows($kind)) {
            // A derivative, a preview and a stem are produced by this platform
            // from a master. Accepting one directly would create audio whose
            // lineage is a claim rather than a record.
            return $this->refuse($audit, 'KIND_NOT_UPLOADABLE');
        }

        // From the bytes. `getMimeType()` reads the file's own content; the
        // client-supplied type is available separately and is not consulted.
        if (! $admission->acceptsType($kind, $file->getMimeType())) {
            return $this->refuse($audit, 'MEDIA_TYPE_NOT_ACCEPTED');
        }

        $maximum = $admission->maximumBytes($kind);

        if ($maximum > 0 && $file->getSize() > $maximum) {
            // Refused before anything is registered. The streaming cap in
            // `AssetStorageService` would also catch it, but only after an
            // asset row exists for a file that was never going to be kept.
            return $this->refuse($audit, 'FILE_TOO_LARGE');
        }

        $asset = $assets->register($kind, (string) $file->getClientOriginalName());

        $audit->record(AuditAction::AssetUploadStarted, subjectUuid: $asset->uuid, context: [
            'kind' => $kind->value,
            // The provider name, which is configuration. Never the key, never
            // the bucket, never a path.
            'storage_provider' => $asset->disk,
            'relayed' => 'true',
        ]);

        try {
            $stored = $assets->storeFile($asset, (string) $file->getRealPath());
        } catch (AssetStorageException) {
            return $this->refuse($audit, 'UPLOAD_NOT_ACCEPTABLE', $asset->uuid);
        } catch (Throwable) {
            // The message names a storage key, and a storage key is not
            // something a browser gets to be told.
            return $this->refuse($audit, 'STORAGE_UNAVAILABLE', $asset->uuid, 503);
        }

        // Verified here, as every other path that stores bytes does. Without
        // it the asset sits at STORED and nothing downstream — analysis,
        // artwork measurement, deduplication — ever looks at it.
        $verified = $verification->verify($stored->refresh());

        $audit->record(AuditAction::AssetUploadFinalized, subjectUuid: $verified->uuid, context: [
            'byte_size' => $verified->byte_size,
            'duplicate' => $verified->duplicate_of_asset_id !== null ? 'true' : 'false',
        ]);

        return response()->json([
            'asset' => $verified->uuid,
            'status' => $verified->status->value,
            // Whether these bytes were already held, so the interface can say
            // so before somebody waits on the analysis of a file the platform
            // already has.
            'duplicate' => $verified->duplicate_of_asset_id !== null,
            'filename' => $verified->original_filename,
        ]);
    }

    private function refuse(RecordAuditEvent $audit, string $code, ?string $uuid = null, int $status = 422): JsonResponse
    {
        $audit->refused(AuditAction::AssetUploadFinalized, $code, $uuid);

        return response()->json(['code' => $code], $status);
    }
}
