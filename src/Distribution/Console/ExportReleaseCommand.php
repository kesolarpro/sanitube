<?php

declare(strict_types=1);

namespace SaniTube\Distribution\Console;

use Illuminate\Console\Command;
use SaniTube\Distribution\Exceptions\DistributionException;
use SaniTube\Distribution\Export\BuildDeliveryPackage;
use SaniTube\Distribution\Export\DeliveryPackage;
use SaniTube\Distribution\Export\PackagedTrack;
use SaniTube\Releases\Models\Release;
use SaniTube\Storage\StorageManager;

/**
 * Getting a release out of the platform without an API.
 *
 * **The delivery path that works everywhere.** Almost no distributor publishes
 * an API, ADR-0018 forbids inventing one from a reverse-engineered wrapper, and
 * every one of them has a web portal. So the package is a metadata sheet and a
 * checked list of exactly which masters belong to this release, and a person
 * uploads them.
 *
 * Nothing is submitted. No delivery row is written and nothing becomes
 * irreversible — building a package is repeatable, and conflating it with the
 * one act in this platform that cannot be undone would weaken the guards that
 * protect that act.
 *
 * **No signed URL is printed unless asked for.** The signature *is* the
 * permission to read a master, and a terminal is scrollback, a screen share and
 * a support thread. `--links` is how somebody says they want them now.
 */
final class ExportReleaseCommand extends Command
{
    protected $signature = 'sanitube:distribution:export
        {release : the release uuid}
        {--links : also mint and print a short-lived link per master}
        {--json : output the package as JSON}';

    protected $description = 'Build a manual delivery package for a release: a metadata sheet and a checked file list.';

    public function handle(BuildDeliveryPackage $builder): int
    {
        $release = Release::query()->where('uuid', (string) $this->argument('release'))->first();

        if (! $release instanceof Release) {
            $this->error('No release with that uuid.');

            return self::FAILURE;
        }

        try {
            $package = $builder->handle($release);
        } catch (DistributionException $exception) {
            // A code, not a sentence translated three times. The reason a
            // release cannot be packaged is the same vocabulary the screens
            // use.
            $this->error(sprintf('Refused: %s', $exception->reason));

            return self::FAILURE;
        }

        if ($this->option('json')) {
            $this->line((string) json_encode($package->manifest(now()->toAtomString()), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->report($package);

        return self::SUCCESS;
    }

    private function report(DeliveryPackage $package): void
    {
        $this->newLine();
        $this->info(sprintf('Package built for release %s.', $package->releaseUuid));
        $this->line('  '.$package->metadataKey);
        $this->line('  '.$package->manifestKey);

        $this->newLine();
        $this->table(
            ['Disc', 'Track', 'Title in package', 'SHA-256'],
            array_map(static fn (PackagedTrack $track): array => [
                (string) $track->discNumber,
                (string) $track->trackNumber,
                $track->fileName,
                // Enough to compare against a portal's own display, short
                // enough to read. The manifest carries the whole digest.
                substr((string) $track->checksum, 0, 16),
            ], $package->tracks),
        );

        foreach ($package->warnings as $warning) {
            // Warnings, not refusals: some distributors assign ISRCs on
            // intake, and refusing to export until every code exists would
            // make the platform unusable for the labels that rely on it.
            $this->warn('  '.$warning);
        }

        if ($this->option('links')) {
            $this->mintLinks($package);
        }

        $this->newLine();
        $this->line(
            '  Nothing has been submitted. This is a package to upload by hand; recording that a '
                .'distributor received it is a separate, deliberate act.',
        );
        $this->newLine();
    }

    /**
     * One expiring link per master, on request.
     *
     * On a local disk there are none to mint — and there is no need, because
     * anybody who can run this command can read the file. The storage key is
     * printed instead, which is the honest answer rather than an empty column.
     */
    private function mintLinks(DeliveryPackage $package): void
    {
        $store = app(StorageManager::class)->default();

        $this->newLine();

        if (! $store->supportsTemporaryUrls()) {
            $this->line('  This storage provider cannot sign URLs. The masters are at:');

            foreach ($package->tracks as $track) {
                $this->line('    '.$track->storageKey);
            }

            return;
        }

        $expiresAt = now()->addSeconds((int) config('storage.temporary_url_ttl', 900));

        $this->line(sprintf('  Links expire at %s. They are the permission itself — do not paste them anywhere.', $expiresAt->toAtomString()));

        foreach ($package->tracks as $track) {
            $this->line('    '.$track->fileName);
            $this->line('      '.$store->temporaryUrl($track->storageKey, $expiresAt));
        }
    }
}
