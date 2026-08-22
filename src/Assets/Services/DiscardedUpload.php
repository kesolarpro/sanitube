<?php

declare(strict_types=1);

namespace SaniTube\Assets\Services;

use Illuminate\Http\Request;

/**
 * Did PHP quietly throw the upload away before this application saw it?
 *
 * When a request body exceeds `post_max_size`, PHP does not fail the request.
 * It empties `$_POST` and `$_FILES` and carries on, so the application
 * receives a request that looks exactly like one where the browser sent
 * nothing — and answers "the file field is required", which is true and
 * useless. UPL-004: that is the message a real 4.7 MB MP3 got in production,
 * on a host whose limit was smaller.
 *
 * The tell is `Content-Length`: the browser said it was sending megabytes and
 * the superglobals are empty. Nothing else produces that combination, so it
 * is safe to name the cause rather than guess at it.
 *
 * **Reported, never enforced.** This only decides which *sentence* an already
 * failing request gets. A request PHP did not truncate is none of its
 * business, and it can never turn a good request into a refused one.
 */
final readonly class DiscardedUpload
{
    public function __construct(private UploadAdmission $admission) {}

    /**
     * Whether this request arrived carrying a body that PHP discarded.
     */
    public function happened(Request $request): bool
    {
        if ($request->files->count() > 0 || $request->request->count() > 0) {
            // Something survived, so nothing was truncated: PHP empties both
            // or neither.
            return false;
        }

        $declared = (int) $request->server('CONTENT_LENGTH', 0);
        $limit = $this->admission->phpPostLimitBytes();

        // A body larger than the limit is the whole hypothesis, and it is the
        // only test needed: anything small enough to be an ordinary empty
        // request is by definition below the limit too. A separate
        // "plausibly large" floor was written here first and removed — the
        // mutation pass showed no test could tell it apart from this line,
        // because there is nothing for it to catch. When PHP reports no limit
        // at all there is nothing to have exceeded, and this stays silent
        // rather than inventing a cause.
        return $limit > 0 && $declared > $limit;
    }

    /**
     * The limit that did it, for a message that names a number somebody can
     * act on. Never a path, never a configuration key the operator does not
     * have — `post_max_size` and `upload_max_filesize` are theirs.
     */
    public function hostLimitBytes(): int
    {
        return $this->admission->phpPostLimitBytes();
    }
}
