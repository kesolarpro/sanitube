<?php

declare(strict_types=1);

namespace SaniTube\MusicGeneration\AceStep;

/**
 * Deciding whether a path a supplier handed back is one we may open.
 *
 * ACE-Step's own reference server answers `POST /generate` with an
 * `output_path` — a path on **its** filesystem — and the worker then opens that
 * file. Everything dangerous about this integration is concentrated in that one
 * sentence, so the check is a class of its own rather than three lines inside a
 * service: it is the piece most worth testing, and the piece most likely to be
 * "simplified" by somebody who has not thought about symlinks.
 *
 * **Resolved, then compared. Never compared, then resolved.**
 * `realpath()` collapses `..`, resolves every symlink along the way, and
 * returns false for something that does not exist. A prefix check on the
 * *unresolved* string would accept `<root>/../../etc/passwd` and would accept a
 * symlink sitting innocently inside the root and pointing anywhere at all.
 *
 * The worker chooses the filename it asks for, so a rejection here means one of
 * three things: the engine ignored the path it was given, something replaced the
 * file between the call and the check, or the engine is not what it claims to
 * be. All three are reasons to refuse rather than to open.
 */
final readonly class ContainedPath
{
    /**
     * The resolved path, or null when it may not be opened.
     *
     * Null rather than an exception: the caller has a refusal code to record
     * and a temporary file to clean up, and it needs to do both whatever the
     * reason. {@see reject()} says which reason, for the log.
     */
    public static function within(string $root, string $candidate): ?string
    {
        return self::reject($root, $candidate) === null ? (string) realpath($candidate) : null;
    }

    /**
     * Why this path may not be opened, or null when it may.
     *
     * Machine-readable, because it reaches a log and an operator acts on it.
     * Each is a genuinely different situation:
     *
     *   - `OUTPUT_ROOT_MISSING` — the worker is misconfigured. Nothing to do
     *     with the supplier.
     *   - `OUTPUT_MISSING` — the engine reported success and wrote nothing, or
     *     wrote somewhere else.
     *   - `OUTPUT_ESCAPED_ROOT` — the resolved file is outside the directory
     *     this worker is allowed to read. Traversal, a symlink pointing out, or
     *     an absolute path from elsewhere; the resolution makes all three look
     *     the same, which is the point.
     *   - `OUTPUT_NOT_A_FILE` — a directory, a socket, a device. `/dev/zero`
     *     is inside no root by accident, but a symlink to it can be.
     */
    public static function reject(string $root, string $candidate): ?string
    {
        $resolvedRoot = realpath($root);

        if ($resolvedRoot === false || ! is_dir($resolvedRoot)) {
            return 'OUTPUT_ROOT_MISSING';
        }

        if (trim($candidate) === '') {
            return 'OUTPUT_MISSING';
        }

        $resolved = realpath($candidate);

        if ($resolved === false) {
            // Also the answer for a dangling symlink, which is right: there is
            // nothing to open either way.
            return 'OUTPUT_MISSING';
        }

        // The separator matters. Without it `/var/gen` would contain
        // `/var/generated-elsewhere`, which is a different directory that
        // happens to share a prefix.
        if ($resolved === $resolvedRoot || ! str_starts_with($resolved, $resolvedRoot.DIRECTORY_SEPARATOR)) {
            return 'OUTPUT_ESCAPED_ROOT';
        }

        if (! is_file($resolved)) {
            return 'OUTPUT_NOT_A_FILE';
        }

        return null;
    }
}
