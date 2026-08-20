<?php

declare(strict_types=1);

namespace SaniTube\Ui\Http\Controllers\Ingestion;

use DateTimeInterface;
use Illuminate\Http\JsonResponse;
use SaniTube\Assets\Services\BeginDirectUpload;
use SaniTube\Ingestion\Services\InboxDeposit;
use SaniTube\Storage\Contracts\StorageProvider;
use SaniTube\Storage\Contracts\SupportsDirectUpload;
use SaniTube\Storage\Exceptions\StorageOperationFailed;
use SaniTube\Storage\StorageManager;
use SaniTube\Ui\Http\Requests\Ingestion\DepositCapabilitiesRequest;
use SaniTube\Ui\Http\Requests\Ingestion\RelayedDepositRequest;
use Throwable;

/**
 * Getting a catalogue's worth of files into the inbox.
 *
 * Two paths, one outcome. **Direct** mints a short-lived capability per file
 * and the bytes never enter PHP — which is the only way nine hundred masters,
 * routinely hundreds of megabytes each, get in without the memory limit, the
 * execution timeout and the request body limit all having to be wrong at once.
 * **Relayed** streams one file through the application, and exists because a
 * shared cPanel account cannot mint an upload URL and must still be able to
 * import a catalogue.
 *
 * After either, the object sits in the inbox under a key the server chose, and
 * `POST /ingestion/batches` refers to it. Everything after that — fetching,
 * hashing, verifying, de-duplicating, making a candidate, retrying — is
 * ING-001's, unchanged, and runs in a queue that survives the tab closing.
 *
 * **Nothing here is authoritative about a file.** The declared media type
 * refuses an obviously wrong file before a capability is spent; the relayed
 * path reads the type from the bytes. Both are courtesies. What decides is
 * measured from the stored object by the import job, from the object itself.
 */
final class InboxDepositController
{
    /**
     * Capabilities for a round of files, for a store that can take them
     * directly.
     */
    public function capabilities(
        DepositCapabilitiesRequest $request,
        StorageManager $storage,
        InboxDeposit $inbox,
    ): JsonResponse {
        $store = $storage->default();

        if (! $store instanceof SupportsDirectUpload) {
            // Not an error the browser can fix, and not a failure: it is the
            // shape of this installation. The screen reads the same fact from
            // its own payload and uses the relayed path.
            return response()->json(['code' => 'DIRECT_DEPOSIT_UNSUPPORTED'], 422);
        }

        $files = $request->files();

        if (count($files) > $inbox->perRequestLimit()) {
            return response()->json(['code' => 'TOO_MANY_FILES'], 422);
        }

        $expiry = $this->expiry();
        $minted = [];

        foreach ($files as $index => $file) {
            $key = $inbox->keyFor($file['name']);

            if ($key === null) {
                $minted[] = ['index' => $index, 'code' => 'FILENAME_UNUSABLE'];

                continue;
            }

            if (! $inbox->acceptsType($file['type'])) {
                // Refused before the capability is minted rather than after it
                // has been used: a presigned PUT cannot be taken back, so a URL
                // handed out for a file the platform will refuse costs somebody
                // an upload before it costs them a refusal.
                $minted[] = ['index' => $index, 'code' => 'MEDIA_TYPE_NOT_ACCEPTED'];

                continue;
            }

            try {
                $upload = $store->temporaryUploadUrl($key, $expiry);
            } catch (StorageOperationFailed) {
                $minted[] = ['index' => $index, 'code' => 'STORAGE_UNAVAILABLE'];

                continue;
            }

            $minted[] = [
                'index' => $index,
                'reference' => $key,
                'upload' => $upload->toArray(),
            ];
        }

        return response()
            ->json(['files' => $minted])
            // A capability, however short-lived, is not a thing to sit in a
            // shared cache.
            ->header('Cache-Control', 'no-store, max-age=0');
    }

    /**
     * One file, through the application, into the inbox.
     */
    public function relay(
        RelayedDepositRequest $request,
        StorageManager $storage,
        InboxDeposit $inbox,
    ): JsonResponse {
        $file = $request->upload();

        // Read from the bytes, never from the extension and never from what
        // the browser declared. `UploadedFile::getMimeType()` runs finfo over
        // the file; `getClientMimeType()` repeats whatever was sent.
        if (! $inbox->acceptsType($file->getMimeType())) {
            return response()->json(['code' => 'MEDIA_TYPE_NOT_ACCEPTED'], 422);
        }

        $size = $file->getSize();

        if ($size === false || $size > $inbox->maximumBytes()) {
            return response()->json(['code' => 'FILE_TOO_LARGE'], 422);
        }

        $key = $inbox->keyFor((string) $file->getClientOriginalName());

        if ($key === null) {
            return response()->json(['code' => 'FILENAME_UNUSABLE'], 422);
        }

        try {
            $this->stream($storage->default(), $key, $file->getRealPath());
        } catch (StorageOperationFailed) {
            return response()->json(['code' => 'STORAGE_UNAVAILABLE'], 503);
        } catch (Throwable) {
            return response()->json(['code' => 'DEPOSIT_FAILED'], 422);
        }

        return response()->json(['reference' => $key]);
    }

    /**
     * Streamed rather than read into a string.
     *
     * A master is routinely hundreds of megabytes. `put()` with the file's
     * contents would need all of it in memory at once, on the host least able
     * to spare it — which is the same host that has no object storage and
     * therefore the only one that ever takes this path.
     */
    private function stream(StorageProvider $store, string $key, string|false $path): void
    {
        if ($path === false) {
            throw new StorageOperationFailed('The uploaded file has no readable path.');
        }

        $store->putFile($key, $path);
    }

    /**
     * The same window a single-file direct upload gets.
     *
     * Read from `BeginDirectUpload` rather than restated: two constants for
     * one rule is one constant that eventually disagrees, and the disagreement
     * would show up as capabilities that expire sooner on one screen than on
     * another for no reason anybody could explain.
     */
    private function expiry(): DateTimeInterface
    {
        return now()->addSeconds(BeginDirectUpload::TTL_SECONDS);
    }
}
