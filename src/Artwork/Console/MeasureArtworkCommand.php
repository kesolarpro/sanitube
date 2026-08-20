<?php

declare(strict_types=1);

namespace SaniTube\Artwork\Console;

use Illuminate\Console\Command;
use SaniTube\Artwork\Contracts\ImageMeasurer;
use SaniTube\Artwork\Models\ArtworkAnalysis;
use SaniTube\Artwork\Services\MeasureArtwork;
use SaniTube\Assets\Enums\AssetKind;
use SaniTube\Assets\Enums\AssetStatus;
use SaniTube\Assets\Models\Asset;

/**
 * Measures the artwork nobody has measured yet.
 *
 * This is what makes `COVER_NOT_MEASURED` a waiting room rather than a dead
 * end. Artwork verified before this module existed has no measurement and never
 * will, because the listener only fires on verification — and without this
 * command the way to get one is "re-upload every cover".
 *
 * Bounded by default so it finishes inside a shared host's cron window instead
 * of being killed halfway through, and it **says what it left behind**: a
 * truncated pass that reads like a complete one is worse than no pass.
 */
final class MeasureArtworkCommand extends Command
{
    protected $signature = 'sanitube:artwork:measure
                            {--limit=200 : Maximum covers to measure in this pass}
                            {--force : Re-measure artwork that already has a measurement at this version}';

    protected $description = 'Measure artwork assets that have no measurement yet';

    public function handle(MeasureArtwork $measurer, ImageMeasurer $image): int
    {
        if (! $image->isAvailable()) {
            $this->warn(
                'This installation cannot measure images, so nothing was changed. Release validation '
                    .'will keep reporting covers as unmeasured, which is honest rather than broken.',
            );

            // Not a failure exit code. An installation that cannot measure is
            // a supported configuration, and a nightly cron that mails an
            // error every night trains its reader to ignore it.
            return self::SUCCESS;
        }

        $limit = max(1, (int) $this->option('limit'));
        $force = (bool) $this->option('force');

        $query = Asset::query()
            ->where('kind', AssetKind::Artwork)
            ->where('status', AssetStatus::Verified);

        if (! $force) {
            $query->whereNotIn(
                'id',
                ArtworkAnalysis::query()
                    ->where('measurer', $image->name())
                    ->where('measurer_version', $image->version())
                    ->select('asset_id'),
            );
        }

        $remaining = (clone $query)->count();

        // Ordered, so a second pass continues rather than re-drawing from the
        // same unordered set and measuring the same twenty covers for ever.
        $assets = $query->orderBy('id')->limit($limit)->get();

        $measured = 0;
        $unreadable = 0;

        foreach ($assets as $asset) {
            $analysis = $measurer->handle($asset, $force);

            if ($analysis === null) {
                continue;
            }

            $analysis->succeeded ? $measured++ : $unreadable++;
        }

        $this->info(sprintf('Measured %d cover(s).', $measured));

        if ($unreadable > 0) {
            // Reported separately: a file nobody could read is a finding, not
            // a failure of the pass, and it is what a release validator will
            // raise as an error later.
            $this->warn(sprintf('%d file(s) could not be read as an image.', $unreadable));
        }

        $left = max(0, $remaining - $assets->count());

        if ($left > 0) {
            $this->warn(sprintf('%d cover(s) still unmeasured. Run this again to continue.', $left));
        }

        return self::SUCCESS;
    }
}
