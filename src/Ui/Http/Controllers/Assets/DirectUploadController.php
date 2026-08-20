<?php

declare(strict_types=1);

namespace SaniTube\Ui\Http\Controllers\Assets;

use Illuminate\Http\JsonResponse;
use SaniTube\Assets\Enums\AssetKind;
use SaniTube\Assets\Exceptions\AssetStorageException;
use SaniTube\Assets\Models\Asset;
use SaniTube\Assets\Services\AssetStorageService;
use SaniTube\Assets\Services\BeginDirectUpload;
use SaniTube\Audit\Enums\AuditAction;
use SaniTube\Audit\Services\RecordAuditEvent;
use SaniTube\Ui\Http\Requests\Assets\BeginUploadRequest;
use Throwable;

/**
 * Uploading a master without it passing through this application.
 *
 * Two POSTs. The first authorises and hands back a short-lived capability to
 * write one object; the second says the write finished and asks the server to
 * look. Nothing in between reaches PHP, which is the point — a shared cPanel
 * account cannot receive a two-gigabyte body, and raising four separate PHP
 * limits to pretend otherwise is not a deployment story.
 *
 * **Both are POSTs, and the first one especially.** It mints a credential; a
 * GET that did so would be prefetched, cached, followed by a link checker and
 * replayed from history — the same reasoning `AssetPreviewController` records
 * for previews, and the same conclusion.
 *
 * The completion call is **not** trusted. It carries no size, no checksum and
 * no media type, because anything it carried would be the browser's word for
 * it. What it does is tell the server when to go and measure.
 */
final class DirectUploadController
{
    public function begin(BeginUploadRequest $request, BeginDirectUpload $uploads, RecordAuditEvent $audit): JsonResponse
    {
        try {
            $started = $uploads->for(AssetKind::from($request->kind()), $request->filename());
        } catch (AssetStorageException) {
            $audit->refused(AuditAction::AssetUploadStarted, 'DIRECT_UPLOAD_UNSUPPORTED');

            return response()->json(['code' => 'DIRECT_UPLOAD_UNSUPPORTED'], 422);
        }

        /** @var Asset $asset */
        $asset = $started['asset'];

        $audit->record(AuditAction::AssetUploadStarted, subjectUuid: $asset->uuid, context: [
            'kind' => $asset->kind->value,
            // The provider name, which is configuration. Never the key, never
            // the bucket, never the signed URL.
            'storage_provider' => $asset->disk,
        ]);

        // Uncacheable: the signature in that URL is the permission itself, and
        // a permission written into a proxy cache outlives the tab that asked.
        return response()
            ->json(['asset' => $asset->uuid, 'upload' => $started['upload']->toArray()])
            ->header('Cache-Control', 'no-store, max-age=0')
            ->header('Pragma', 'no-cache');
    }

    public function complete(Asset $asset, AssetStorageService $assets, RecordAuditEvent $audit): JsonResponse
    {
        try {
            $stored = $assets->finalizeDirectUpload($asset);
        } catch (AssetStorageException $exception) {
            // The refusal reason is a code. The exception's sentence names the
            // staging key, and a storage key is not something a browser gets
            // to be told.
            $audit->refused(AuditAction::AssetUploadFinalized, 'UPLOAD_NOT_ACCEPTABLE', $asset->uuid);

            return response()->json(['code' => 'UPLOAD_NOT_ACCEPTABLE'], 422);
        } catch (Throwable) {
            $audit->refused(AuditAction::AssetUploadFinalized, 'STORAGE_UNAVAILABLE', $asset->uuid);

            return response()->json(['code' => 'STORAGE_UNAVAILABLE'], 503);
        }

        $audit->record(AuditAction::AssetUploadFinalized, subjectUuid: $stored->uuid, context: [
            'byte_size' => $stored->byte_size,
            'duplicate' => $stored->duplicate_of_asset_id !== null,
        ]);

        return response()->json([
            'asset' => $stored->uuid,
            'status' => $stored->status->value,
            // Whether these bytes were already held. The interface uses it to
            // say so before somebody waits for an analysis of a file the
            // platform already has.
            'duplicate' => $stored->duplicate_of_asset_id !== null,
        ]);
    }
}
