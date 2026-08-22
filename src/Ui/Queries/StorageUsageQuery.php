<?php

declare(strict_types=1);

namespace SaniTube\Ui\Queries;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use SaniTube\Assets\Enums\AssetKind;
use SaniTube\Assets\Enums\AssetStatus;

/**
 * How many bytes this installation is holding, and where.
 *
 * STO-005. The platform recorded `byte_size` on every asset from the first
 * upload and asked the question nowhere: an operator could not find out how
 * much they were storing without a database client, which on a metered object
 * store is the number that turns into a bill. The dashboard reported an asset
 * *count*, which says nothing — a thousand previews and a thousand masters are
 * the same number and three orders of magnitude apart.
 *
 * **This is what the catalogue says it stored, never what a provider bills.**
 * The distinction is the whole honesty of this screen. The two disagree for
 * real reasons — a multipart upload abandoned halfway leaves parts the
 * platform never registered, a bucket may hold objects from before this
 * installation, versioning keeps what a delete removed — and reporting a
 * database sum as "your storage usage" would be a number somebody reconciles
 * against an invoice and cannot. The screen says which one it is.
 *
 * **No capacity, and no percentage.** A percentage needs a denominator, and
 * this platform has none it can honestly obtain: an object store publishes no
 * quota it can read, a plan's limit is on the provider's own dashboard, and
 * inventing one from a free tier would be a guess with a progress bar drawn
 * around it. Free space on a local volume is a different question, knowable,
 * and already answered by the doctor.
 *
 * **Trashed bytes are counted and shown apart.** They still cost, which is the
 * entire reason to show them: an operator looking at a number that will not go
 * down needs to know how much of it is waiting on a decision they have not
 * made. TRASH-001 kept the objects deliberately — a trashed master is
 * recoverable — and this is where that decision becomes visible rather than
 * merely correct.
 */
final readonly class StorageUsageQuery
{
    /**
     * The states in which the platform believes the bytes are actually there.
     *
     * `PENDING` is not among them: an upload that never finished has a row and
     * a declared size, and counting it would report bytes nobody has stored.
     * `MISSING` is not either — the row says the object is gone. Both are
     * reported separately, because "the platform is unsure about these" is
     * itself a thing to see.
     *
     * @var list<string>
     */
    private const HELD = [AssetStatus::Stored->value, AssetStatus::Verified->value];

    /**
     * @return array<string, mixed>
     */
    public function overview(): array
    {
        $rows = $this->rows();

        if ($rows === null) {
            // The assets table is not there or would not answer. Null rather
            // than zero: an installation storing nothing and one that could
            // not be asked are different, and a zero would report the second
            // as the first.
            return [
                'measured' => false,
                'held' => ['bytes' => null, 'assets' => null],
                'trashed' => ['bytes' => null, 'assets' => null],
                'unsure' => ['bytes' => null, 'assets' => null],
                'by_kind' => [],
                'by_disk' => [],
            ];
        }

        return [
            'measured' => true,
            'held' => $this->totalOf($rows, held: true, trashed: false),
            'trashed' => $this->totalOf($rows, held: true, trashed: true),
            // Pending and missing together. Both mean the platform cannot say
            // the bytes are there, and an operator chasing a discrepancy
            // against an invoice wants the size of the uncertainty rather than
            // its two causes.
            'unsure' => $this->totalOf($rows, held: false, trashed: null),
            'by_kind' => $this->byKind($rows),
            'by_disk' => $this->byDisk($rows),
        ];
    }

    /**
     * Every combination that matters, in one grouped query.
     *
     * One query rather than six sums: the assets table is the one that grows
     * with the catalogue, and a dashboard panel issuing a query per kind is
     * the shape PERF-001 spent a ticket removing.
     *
     * @return list<array{disk: string, kind: string, status: string, trashed: bool, bytes: int, assets: int}>|null
     */
    private function rows(): ?array
    {
        try {
            $grouped = DB::table('assets')
                ->selectRaw('disk, kind, status, (trashed_at is null) as untrashed, '
                    .'sum(byte_size) as bytes, count(*) as assets')
                ->groupBy('disk', 'kind', 'status', 'untrashed')
                ->get();
        } catch (QueryException) {
            return null;
        }

        $rows = [];

        foreach ($grouped as $row) {
            $rows[] = [
                'disk' => (string) $row->disk,
                'kind' => (string) $row->kind,
                'status' => (string) $row->status,
                // Cast rather than compared: SQLite answers a boolean
                // expression with 1 and 0, MySQL with 1 and 0, and Postgres
                // would answer with true and false. `(bool)` is the only
                // reading that is right on all three.
                'trashed' => ! (bool) $row->untrashed,
                'bytes' => (int) $row->bytes,
                'assets' => (int) $row->assets,
            ];
        }

        return $rows;
    }

    /**
     * @param  list<array{disk: string, kind: string, status: string, trashed: bool, bytes: int, assets: int}>  $rows
     * @param  bool|null  $trashed  null counts both
     * @return array{bytes: int, assets: int}
     */
    private function totalOf(array $rows, bool $held, ?bool $trashed): array
    {
        $bytes = 0;
        $assets = 0;

        foreach ($rows as $row) {
            if (in_array($row['status'], self::HELD, true) !== $held) {
                continue;
            }

            if ($trashed !== null && $row['trashed'] !== $trashed) {
                continue;
            }

            $bytes += $row['bytes'];
            $assets += $row['assets'];
        }

        return ['bytes' => $bytes, 'assets' => $assets];
    }

    /**
     * What the bytes are, kind by kind.
     *
     * Every kind is present including the zeros, and trashed bytes are
     * excluded. A catalogue with no video looks identical to one whose video
     * count went missing if absent keys are rendered as absent — and the
     * useful reading here is which kind is worth doing something about.
     *
     * @param  list<array{disk: string, kind: string, status: string, trashed: bool, bytes: int, assets: int}>  $rows
     * @return array<string, array{bytes: int, assets: int}>
     */
    private function byKind(array $rows): array
    {
        $totals = [];

        foreach (AssetKind::cases() as $case) {
            $totals[$case->value] = ['bytes' => 0, 'assets' => 0];
        }

        foreach ($rows as $row) {
            if ($row['trashed'] || ! in_array($row['status'], self::HELD, true)) {
                continue;
            }

            if (! array_key_exists($row['kind'], $totals)) {
                // A kind this build has no case for. Kept rather than dropped:
                // it is bytes somebody is paying for, and a row silently
                // omitted is a total that does not add up.
                $totals[$row['kind']] = ['bytes' => 0, 'assets' => 0];
            }

            $totals[$row['kind']]['bytes'] += $row['bytes'];
            $totals[$row['kind']]['assets'] += $row['assets'];
        }

        return $totals;
    }

    /**
     * Where the bytes are.
     *
     * More than one disk on the same installation is the ordinary outcome of
     * moving to object storage: the new provider takes what arrives next and
     * what came before stays where it was. An operator paying two bills at
     * once is the person this row exists for.
     *
     * Trashed bytes included here, because the question this answers is which
     * disk holds what — and the object in the trash is on a disk.
     *
     * @param  list<array{disk: string, kind: string, status: string, trashed: bool, bytes: int, assets: int}>  $rows
     * @return list<array{disk: string, bytes: int, assets: int, trashed_bytes: int}>
     */
    private function byDisk(array $rows): array
    {
        $totals = [];

        foreach ($rows as $row) {
            if (! in_array($row['status'], self::HELD, true)) {
                continue;
            }

            $disk = $row['disk'];
            $totals[$disk] ??= ['disk' => $disk, 'bytes' => 0, 'assets' => 0, 'trashed_bytes' => 0];

            $totals[$disk]['bytes'] += $row['bytes'];
            $totals[$disk]['assets'] += $row['assets'];

            if ($row['trashed']) {
                $totals[$disk]['trashed_bytes'] += $row['bytes'];
            }
        }

        ksort($totals);

        return array_values($totals);
    }
}
