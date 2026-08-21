<?php

declare(strict_types=1);

namespace SaniTube\Installer\Services;

use Illuminate\Support\Carbon;
use SaniTube\Deployment\Profiles\InstallationProfile;
use SaniTube\Installer\StageResult;
use SaniTube\Storage\CredentialRedactor;

/**
 * What has already happened to this installation.
 *
 * An install that fails at stage four is resumed, not restarted, and resuming
 * needs a memory: which stages ran, how each ended, under which profile. This
 * is that memory — one JSON file under storage, written atomically, readable
 * by `sanitube:install --status` and by whoever is debugging at 2am.
 *
 * Three rules, each load-bearing:
 *
 * **It records; it never decides.** Whether a stage may be skipped is the
 * stage's own question, answered by re-checking the machine — an `.env` is
 * "already present" because the file exists, not because the journal says a
 * previous run created one. A journal that authorised skipping would turn a
 * stale file into a lie about the machine.
 *
 * **No secret enters, belt and braces.** Stage details are prose about
 * outcomes and should never carry a credential — and they are scrubbed
 * through the same {@see CredentialRedactor} rule as every failure message
 * anyway, because "should never" is not a control. The file is still written
 * 0600: it names stages and timing, which is reconnaissance enough.
 *
 * **A corrupt journal is preserved, not replaced.** Unparseable JSON is moved
 * aside with its content intact and a fresh journal starts. Deleting it would
 * destroy the one artefact that could explain how it got that way.
 */
final readonly class InstallationJournal
{
    private const FILE = 'installer/journal.json';

    private const CORRUPT = 'installer/journal.corrupt.json';

    public function __construct(private string $storagePath) {}

    public function record(StageResult $result): void
    {
        $journal = $this->load();

        $journal['started_at'] ??= Carbon::now()->toAtomString();
        $journal['updated_at'] = Carbon::now()->toAtomString();

        $stages = $journal['stages'] ?? [];
        $stages = is_array($stages) ? $stages : [];

        $stages[$result->stage] = [
            'outcome' => $result->outcome,
            'detail' => CredentialRedactor::scrub($result->detail) ?? '',
            'at' => Carbon::now()->toAtomString(),
        ];

        $journal['stages'] = $stages;

        $this->write($journal);
    }

    public function rememberProfile(InstallationProfile $profile): void
    {
        $journal = $this->load();

        $journal['started_at'] ??= Carbon::now()->toAtomString();
        $journal['updated_at'] = Carbon::now()->toAtomString();
        $journal['profile'] = $profile->value;

        $this->write($journal);
    }

    /**
     * The profile a previous run recorded, if any — and null rather than a
     * guess when the value no longer names a profile this build knows.
     */
    public function recordedProfile(): ?InstallationProfile
    {
        $value = $this->load()['profile'] ?? null;

        return is_string($value) ? InstallationProfile::tryFrom($value) : null;
    }

    /**
     * @return array<string, array{outcome: string, detail: string, at: string}>
     */
    public function stages(): array
    {
        $stages = $this->load()['stages'] ?? [];

        if (! is_array($stages)) {
            return [];
        }

        $clean = [];

        foreach ($stages as $name => $entry) {
            if (! is_string($name) || ! is_array($entry)) {
                continue;
            }

            $clean[$name] = [
                'outcome' => is_string($entry['outcome'] ?? null) ? $entry['outcome'] : '?',
                'detail' => is_string($entry['detail'] ?? null) ? $entry['detail'] : '',
                'at' => is_string($entry['at'] ?? null) ? $entry['at'] : '',
            ];
        }

        return $clean;
    }

    public function startedAt(): ?string
    {
        $value = $this->load()['started_at'] ?? null;

        return is_string($value) ? $value : null;
    }

    public function isEmpty(): bool
    {
        return $this->load() === [];
    }

    /**
     * @return array<string, mixed>
     */
    private function load(): array
    {
        $path = $this->path(self::FILE);

        if (! is_file($path)) {
            return [];
        }

        $raw = @file_get_contents($path);

        if ($raw === false) {
            return [];
        }

        $decoded = json_decode($raw, true);

        if (! is_array($decoded)) {
            // Preserved, never replaced in place: the corrupt file is the one
            // artefact that can explain what happened to it. rename() rather
            // than copy+delete so no moment exists with two writable copies.
            @rename($path, $this->path(self::CORRUPT));

            return [];
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * @param  array<string, mixed>  $journal
     */
    private function write(array $journal): void
    {
        $path = $this->path(self::FILE);
        $directory = dirname($path);

        if (! is_dir($directory)) {
            @mkdir($directory, 0700, true);
        }

        $encoded = json_encode($journal, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if ($encoded === false) {
            return;
        }

        // Write-then-rename so a crash mid-write leaves the previous journal
        // whole instead of half a file that the next load calls corrupt.
        $temporary = $path.'.tmp';

        if (@file_put_contents($temporary, $encoded."\n") === false) {
            return;
        }

        @chmod($temporary, 0600);
        @rename($temporary, $path);
    }

    private function path(string $relative): string
    {
        return rtrim($this->storagePath, '/').'/'.$relative;
    }
}
