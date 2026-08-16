<?php

declare(strict_types=1);

namespace SaniTube\Installer\Services;

use Illuminate\Support\Carbon;
use Throwable;

/**
 * Reads and rewrites `.env`, and never loses what was there.
 *
 * **A backup is taken before the first modification of a run, always.** The
 * `.env` of a working installation holds database credentials, provider keys
 * and an APP_KEY that decrypts existing sessions — and the operator running
 * the installer is frequently doing so *because* something is already wrong.
 * Rewriting that file without a copy beside it is how an install turns a
 * broken deployment into an unrecoverable one.
 *
 * The rewrite is deliberately line-based rather than a parse-and-serialise
 * round trip. A round trip would normalise the file: comments dropped,
 * ordering changed, quoting rewritten. An operator who opens `.env` after the
 * installer ran and finds their own annotations gone learns not to trust the
 * installer with the file — and their comments are frequently the only record
 * of why a value is what it is.
 *
 * Nothing here logs a value. Keys are named in output; what they hold is not.
 */
final class EnvironmentFile
{
    private bool $backedUp = false;

    public function __construct(private readonly string $path) {}

    public function exists(): bool
    {
        return is_file($this->path);
    }

    public function path(): string
    {
        return $this->path;
    }

    /**
     * Copy `.env.example` into place.
     *
     * Refuses when a `.env` already exists: overwriting it is precisely the
     * destruction this class exists to prevent, and "the installer replaced my
     * configuration" is not recoverable from a message.
     */
    public function createFromExample(string $examplePath): bool
    {
        if ($this->exists() || ! is_file($examplePath)) {
            return false;
        }

        return copy($examplePath, $this->path);
    }

    /**
     * The value of one key, or null when it is absent or empty.
     *
     * Used to decide whether a stage has anything to do — never to display.
     */
    public function get(string $key): ?string
    {
        foreach ($this->lines() as $line) {
            if (! str_starts_with(ltrim($line), $key.'=')) {
                continue;
            }

            $value = trim(substr(ltrim($line), strlen($key) + 1));
            $value = trim($value, "\"'");

            return $value === '' ? null : $value;
        }

        return null;
    }

    public function has(string $key): bool
    {
        return $this->get($key) !== null;
    }

    /**
     * Set one key, preserving everything else in the file exactly.
     *
     * @return bool whether the file was written
     */
    public function set(string $key, string $value): bool
    {
        if (! $this->exists()) {
            return false;
        }

        if (! $this->backup()) {
            // No backup, no write. The whole point of this class.
            return false;
        }

        $lines = $this->lines();
        $written = false;
        $encoded = $this->encode($value);

        foreach ($lines as $index => $line) {
            if (str_starts_with(ltrim($line), $key.'=')) {
                $lines[$index] = $key.'='.$encoded;
                $written = true;
            }
        }

        if (! $written) {
            $lines[] = $key.'='.$encoded;
        }

        return file_put_contents($this->path, implode("\n", $lines)."\n") !== false;
    }

    /**
     * Copy the file aside, once per run.
     *
     * Once, not per write: a stage that sets four keys would otherwise leave
     * four backups, and the useful one is the state before the installer
     * touched anything.
     */
    public function backup(): bool
    {
        if ($this->backedUp) {
            return true;
        }

        if (! $this->exists()) {
            // Nothing to lose. A fresh file is created by
            // createFromExample(), which refuses to overwrite.
            $this->backedUp = true;

            return true;
        }

        try {
            $destination = $this->path.'.backup-'.Carbon::now()->format('Ymd-His');

            if (! copy($this->path, $destination)) {
                return false;
            }

            // The backup holds every credential the original does. World- and
            // group-readable is how a shared host leaks it to the account next
            // door.
            @chmod($destination, 0600);

            $this->backedUp = true;

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @return list<string>
     */
    private function lines(): array
    {
        if (! $this->exists()) {
            return [];
        }

        $contents = file_get_contents($this->path);

        if ($contents === false) {
            return [];
        }

        return explode("\n", rtrim($contents, "\n"));
    }

    /**
     * Quote only when the value needs it.
     *
     * An unquoted value containing a space or a `#` is silently truncated by
     * every dotenv parser, and database passwords contain both.
     */
    private function encode(string $value): string
    {
        if ($value === '') {
            return '';
        }

        if (preg_match('/[\s#"\'=]/', $value) === 1) {
            return '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $value).'"';
        }

        return $value;
    }
}
