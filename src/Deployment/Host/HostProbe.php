<?php

declare(strict_types=1);

namespace SaniTube\Deployment\Host;

/**
 * What the inspector is allowed to ask the machine.
 *
 * Every question is primitive on purpose: does this path exist, what does this
 * file say, where is this binary. The *meaning* of the answers — "this is
 * cPanel", "this host can run services" — lives in {@see HostInspector}, where
 * a test can exercise it against any machine by faking these nine answers.
 * A probe that answered "is this cPanel?" directly would put the one part
 * worth testing on the wrong side of the seam.
 */
interface HostProbe
{
    /**
     * PHP_OS_FAMILY: `Linux`, `Darwin`, `Windows`, `BSD`, `Solaris`, `Unknown`.
     */
    public function osFamily(): string;

    public function phpVersion(): string;

    /**
     * The CLI binary running right now, or null when PHP cannot say.
     */
    public function phpBinary(): ?string;

    public function isDirectory(string $path): bool;

    /**
     * The file's contents, or null when it does not exist or cannot be read.
     * Never throws: an unreadable /etc/os-release is an answer, not an error.
     */
    public function read(string $path): ?string;

    /**
     * Absolute path of a binary findable in PATH, or null.
     */
    public function binary(string $name): ?string;

    /**
     * The effective user id, or null where the posix extension is absent —
     * which is itself common on the shared hosts this exists to describe.
     */
    public function effectiveUserId(): ?int;

    public function iniValue(string $name): ?string;

    /**
     * Whether a function both exists and is not listed in `disable_functions`.
     * Shared hosts disable `proc_open` far more often than they remove it.
     */
    public function functionUsable(string $name): bool;
}
