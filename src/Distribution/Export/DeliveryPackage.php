<?php

declare(strict_types=1);

namespace SaniTube\Distribution\Export;

/**
 * What a label hands to a distributor that has no API.
 *
 * **The gap this fills is the largest one the platform had.** A catalogue could
 * be imported, reviewed, credited, validated and marked READY — and then it
 * stopped. The only distributors that existed were `none` and a fake, so a real
 * label using SaniTube had no way to get audio and metadata *out*, in any form.
 * Every distributor worth delivering to has a web portal; almost none publish
 * an API, and ADR-0018 forbids inventing one from a reverse-engineered wrapper.
 * So the delivery path that works everywhere is a person with a spreadsheet and
 * a set of files.
 *
 * **What this is not.** It is not a submission. Nothing is sent, nothing is
 * irreversible, and no `DistributionDelivery` row is written — building a
 * package is repeatable and undoable, and conflating it with the one act in
 * this platform that cannot be undone would weaken the guards that protect
 * that act. Recording *that a human delivered it* is a separate statement about
 * the outside world, and it deserves its own ticket rather than being inferred
 * from a file having been written.
 *
 * **No master is copied.** The package is a metadata sheet and a checked list
 * of exactly which stored objects belong to this release. Copying a set of
 * masters would double the storage for every release and, on object storage,
 * pull two gigabytes down through PHP and push them back up to sit beside
 * themselves. The audio is fetched when the operator fetches it — through the
 * same expiring signed link every other read uses.
 */
final readonly class DeliveryPackage
{
    /**
     * @param  list<PackagedTrack>  $tracks
     * @param  list<string>  $warnings  things worth seeing before uploading, never refusals
     * @param  list<string>  $headings  the metadata columns, in the configured order
     */
    public function __construct(
        public string $releaseUuid,
        public string $prefix,
        public string $metadataKey,
        public string $manifestKey,
        public array $tracks,
        public array $warnings,
        public array $headings,
    ) {}

    /**
     * The manifest, as it is written into storage.
     *
     * **No URL, signed or otherwise.** A signed link is a capability that
     * expires; writing one into a file that lives in a bucket would leave a
     * working credential lying around until it did not work, which is the worst
     * of both. Links are minted when somebody asks for them.
     *
     * @return array<string, mixed>
     */
    public function manifest(string $builtAt): array
    {
        return [
            'release_uuid' => $this->releaseUuid,
            'built_at' => $builtAt,
            'track_count' => count($this->tracks),
            'warnings' => $this->warnings,
            'tracks' => array_map(
                static fn (PackagedTrack $track): array => $track->toArray(),
                $this->tracks,
            ),
        ];
    }

    /**
     * The metadata sheet, headings first.
     *
     * @return list<list<string>>
     */
    public function rows(): array
    {
        $rows = [$this->headings];

        foreach ($this->tracks as $track) {
            $row = [];

            foreach ($this->headings as $heading) {
                $row[] = $track->columns[$heading] ?? '';
            }

            $rows[] = $row;
        }

        return $rows;
    }
}
