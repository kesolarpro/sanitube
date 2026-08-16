<?php

declare(strict_types=1);

namespace SaniTube\Installer\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Throwable;

/**
 * Who is allowed to install this, and the fact that it is done.
 *
 * A web installer has one problem no other screen has: **before installation
 * there are no accounts, so there is nobody to authenticate.** Every guard the
 * platform has — roles, the active check, the session — depends on the very
 * thing being set up. An `/install` page that runs on "only available while
 * uninstalled" is a page where whoever reaches the URL first becomes the owner
 * of somebody else's catalogue, and on shared hosting the window between
 * uploading files and finishing setup is measured in minutes.
 *
 * **The token is the answer.** A random value is written to a file inside
 * `storage/` on the first visit and shown nowhere. To proceed, the operator
 * copies it out of that file. That converts "anybody who found the URL" into
 * "somebody who can read a file on this server", which is exactly the person
 * who should be installing it — and on a cPanel account, File Manager makes
 * that a thirty-second job while `php artisan` is often not available at all.
 *
 * It is not a password and is not treated as one: it is single-purpose,
 * regenerated on every fresh installation, and irrelevant the moment the
 * installer locks. Comparison is `hash_equals`, because the difference between
 * "wrong" and "wrong in the first character" is not a thing an attacker should
 * be able to measure.
 *
 * **The lock is permanent and is not the only guard.** The file records that
 * the installer finished; the middleware also asks the *installation* whether
 * it is complete. Both, because a lock file can be deleted by anybody with the
 * access the token requires — and an installation that already has an owner
 * must refuse to be installed again regardless of what any file says.
 */
final readonly class InstallationLock
{
    /** Where the two files live, relative to the storage path. */
    private const TOKEN_FILE = 'installer/token';

    private const LOCK_FILE = 'installer/installed.lock';

    public function __construct(private string $storagePath) {}

    /**
     * The token for this installation, minting one if there is none.
     *
     * Called from the GET that renders the form, so the file exists by the
     * time the operator goes looking for it.
     */
    public function token(): ?string
    {
        $existing = $this->read(self::TOKEN_FILE);

        if ($existing !== null) {
            return $existing;
        }

        // 64 hex characters. Long enough that guessing is not a strategy, and
        // no shorter merely because somebody has to copy it once.
        return $this->write(self::TOKEN_FILE, bin2hex(random_bytes(32))) ? $this->read(self::TOKEN_FILE) : null;
    }

    /**
     * Whether the value offered matches the one on disk.
     *
     * False when no token exists at all: the absence of a token is not a
     * reason to let somebody in.
     */
    public function accepts(string $offered): bool
    {
        $token = $this->read(self::TOKEN_FILE);

        if ($token === null || $offered === '') {
            return false;
        }

        return hash_equals($token, $offered);
    }

    public function isLocked(): bool
    {
        return is_file($this->path(self::LOCK_FILE));
    }

    /**
     * Close the installer for good.
     *
     * The token is removed in the same act. Leaving it behind would keep a
     * usable credential on disk for a door that is already shut, and its only
     * remaining function would be to be found.
     */
    public function lock(): bool
    {
        $written = $this->write(
            self::LOCK_FILE,
            'Installed '.Carbon::now()->toAtomString().'. Delete this file only if you intend to install again.',
        );

        if ($written) {
            @unlink($this->path(self::TOKEN_FILE));
        }

        return $written;
    }

    /**
     * The path an operator has to open, said in a way they can act on.
     *
     * Relative to the project root, because "storage/installer/token" is a
     * thing somebody can paste into File Manager and an absolute server path
     * is a thing they have to translate.
     */
    public function tokenLocation(): string
    {
        return 'storage/'.self::TOKEN_FILE;
    }

    private function read(string $relative): ?string
    {
        $path = $this->path($relative);

        if (! is_file($path)) {
            return null;
        }

        $contents = @file_get_contents($path);

        if ($contents === false) {
            return null;
        }

        $contents = trim($contents);

        return $contents === '' ? null : $contents;
    }

    private function write(string $relative, string $contents): bool
    {
        $path = $this->path($relative);

        try {
            if (! is_dir($directory = dirname($path)) && ! @mkdir($directory, 0755, true) && ! is_dir($directory)) {
                return false;
            }

            if (@file_put_contents($path, $contents) === false) {
                return false;
            }

            // 0600. On a shared host the account next door can often read
            // anything world-readable, and this file is the only thing
            // standing between them and an install.
            @chmod($path, 0600);

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    private function path(string $relative): string
    {
        return rtrim($this->storagePath, '/').'/'.Str::of($relative)->ltrim('/')->value();
    }
}
