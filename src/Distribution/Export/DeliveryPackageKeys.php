<?php

declare(strict_types=1);

namespace SaniTube\Distribution\Export;

use SaniTube\Assets\Enums\AssetKind;
use SaniTube\Assets\Storage\AssetObjectKeys;
use SaniTube\Releases\Models\Release;

/**
 * Where a release's delivery package lives.
 *
 * One definition, because two things need it: the builder that writes the
 * package and the screen that asks whether one exists. Two spellings of *where*
 * would drift into a screen that reports no package beside a bucket that holds
 * one — and an operator who rebuilds it, every time, for ever.
 *
 * **The prefix is not configurable.** It is the one the platform already owns
 * for distribution output, which means the importer already refuses to point
 * "import everything under X" at it. A settable prefix would be a way to put
 * the platform's own output somewhere a later import would read back in as new
 * material, and each pass would produce another generation of duplicates.
 *
 * Deterministic rather than timestamped: a release exported four times while
 * its credits were being fixed should leave one package, not four, and an
 * operator opening the folder should not have to work out which is current.
 */
final readonly class DeliveryPackageKeys
{
    public function __construct(private AssetObjectKeys $keys) {}

    public function prefix(Release $release): string
    {
        return $this->keys->prefixFor(AssetKind::DistributionExport).'/'.$release->uuid;
    }

    public function metadata(Release $release): string
    {
        return $this->prefix($release).'/metadata.csv';
    }

    public function manifest(Release $release): string
    {
        return $this->prefix($release).'/manifest.json';
    }
}
