<?php

declare(strict_types=1);

namespace SaniTube\Installer\Services;

use Illuminate\Support\Carbon;

/**
 * One deploy at a time.
 *
 * Two `sanitube:deploy` runs interleaving their migrations is the failure
 * nobody can clean up afterwards — half of one schema change committed inside
 * half of another. The lock is a file because the deploy is per-installation
 * and per-machine: a cache-backed lock would make deploying depend on the
 * very services a deploy restarts.
 *
 * **Stale is decided by age and said out loud.** A deploy that crashed hard
 * leaves its lock behind; refusing forever would turn one crash into a
 * permanently undeployable installation. After an hour the lock may be taken
 * over — with the previous holder's age in the message, so an operator
 * watching two terminals knows which one just lost.
 */
final readonly class DeploymentLock
{
    private const FILE = 'framework/deploy.lock';

    /**
     * Longer than any honest deploy by an order of magnitude, shorter than
     * "somebody gave up and went home".
     */
    private const STALE_SECONDS = 3600;

    public function __construct(private string $storagePath) {}

    /**
     * The age in seconds of a lock somebody else holds, or null when the
     * lock was acquired.
     */
    public function acquire(): ?int
    {
        $path = $this->path();

        $held = @file_get_contents($path);

        if ($held !== false) {
            $age = $this->age($held);

            if ($age !== null && $age < self::STALE_SECONDS) {
                return $age;
            }

            // Stale or unreadable: taking over is the safe direction, but
            // never silently — the caller announces it.
            @unlink($path);
        }

        // O_EXCL via the 'x' mode: two processes racing for the lock cannot
        // both win, which is the entire point of having one.
        $handle = @fopen($path, 'x');

        if ($handle === false) {
            $reread = @file_get_contents($path);

            return $reread === false ? 0 : ($this->age($reread) ?? 0);
        }

        fwrite($handle, (string) json_encode([
            'pid' => getmypid(),
            'acquired_at' => Carbon::now()->toAtomString(),
        ]));
        fclose($handle);

        return null;
    }

    public function release(): void
    {
        @unlink($this->path());
    }

    public function isHeld(): bool
    {
        return is_file($this->path());
    }

    private function age(string $contents): ?int
    {
        $decoded = json_decode($contents, true);

        if (! is_array($decoded) || ! is_string($decoded['acquired_at'] ?? null)) {
            return null;
        }

        try {
            return (int) abs(Carbon::parse($decoded['acquired_at'])->diffInSeconds(Carbon::now()));
        } catch (\Throwable) {
            return null;
        }
    }

    private function path(): string
    {
        return rtrim($this->storagePath, '/').'/'.self::FILE;
    }
}
