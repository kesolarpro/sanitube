<?php

declare(strict_types=1);

namespace SaniTube\Assets\Console;

use Illuminate\Console\Command;
use SaniTube\Assets\Enums\AssetStatus;
use SaniTube\Assets\Models\Asset;
use SaniTube\Assets\Services\AssetVerificationService;
use SaniTube\Storage\Exceptions\StorageOperationFailed;

/**
 * Re-reads assets out of storage and confirms they are what the catalogue
 * says.
 *
 * Verification is not a one-off gate at upload time. Stores acquire lifecycle
 * rules, credentials get pointed somewhere else, someone restores a
 * backup over the top. This command is how those are found before a
 * distributor finds them.
 *
 * It is bounded by default so it finishes inside a shared host's cron window
 * rather than being killed halfway through, and what it skipped is printed —
 * a truncated pass that looks like a complete one is worse than no pass.
 */
final class VerifyAssetsCommand extends Command
{
    protected $signature = 'sanitube:assets:verify
                            {--status=STORED : Only verify assets in this status; "all" for every stored asset}
                            {--kind= : Restrict to one asset kind}
                            {--limit= : Maximum assets to check; defaults to assets.verification.batch_size}
                            {--json : Output the outcome as JSON}';

    protected $description = 'Read assets back out of storage and confirm their checksum and size';

    public function handle(AssetVerificationService $verification): int
    {
        $limit = $this->limit();
        $query = Asset::query()->orderBy('id');

        $status = (string) $this->option('status');

        if (strtolower($status) === 'all') {
            $query->whereIn('status', [AssetStatus::Stored->value, AssetStatus::Verified->value]);
        } else {
            $parsed = AssetStatus::tryFrom(strtoupper($status));

            if ($parsed === null || ! $parsed->isImmutable()) {
                $this->error(sprintf(
                    'Cannot verify status [%s]. Verification confirms what is in storage, so it '
                        .'applies to STORED and VERIFIED assets only.',
                    $status,
                ));

                return self::INVALID;
            }

            $query->where('status', $parsed->value);
        }

        if (is_string($kind = $this->option('kind')) && $kind !== '') {
            $query->where('kind', strtoupper($kind));
        }

        $total = (clone $query)->count();
        $assets = $query->limit($limit)->get();

        $outcomes = [];
        $unreachable = [];

        foreach ($assets as $asset) {
            try {
                $outcomes[$asset->uuid] = $verification->verify($asset)->status->value;
            } catch (StorageOperationFailed $exception) {
                // Storage being unreachable is not evidence about the asset,
                // so its status is left exactly as it was.
                $unreachable[$asset->uuid] = $exception->getMessage();
            }
        }

        $skipped = max(0, $total - $assets->count());

        if ($this->option('json')) {
            $this->line((string) json_encode([
                'checked' => count($outcomes),
                'unreachable' => $unreachable,
                'skipped' => $skipped,
                'outcomes' => $outcomes,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->report($outcomes, $unreachable, $skipped);
        }

        $failed = array_filter(
            $outcomes,
            static fn (string $status): bool => $status !== AssetStatus::Verified->value,
        );

        return $failed === [] && $unreachable === [] ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param  array<string, string>  $outcomes
     * @param  array<string, string>  $unreachable
     */
    private function report(array $outcomes, array $unreachable, int $skipped): void
    {
        $counts = array_count_values($outcomes);

        $this->newLine();

        foreach ($counts as $status => $count) {
            $this->line(sprintf(
                '  %s  %d',
                $status === AssetStatus::Verified->value
                    ? '<fg=green>'.$status.'</>'
                    : '<fg=red>'.$status.'</>',
                $count,
            ));
        }

        foreach ($outcomes as $uuid => $status) {
            if ($status !== AssetStatus::Verified->value) {
                $this->line(sprintf('  <fg=red>%s</> %s', $status, $uuid));
            }
        }

        if ($unreachable !== []) {
            $this->newLine();
            $this->line(sprintf(
                '  <fg=yellow>%d asset(s) could not be reached.</> Their status was left unchanged; '
                    .'run sanitube:storage:check.',
                count($unreachable),
            ));
        }

        if ($skipped > 0) {
            $this->newLine();
            $this->line(sprintf(
                '  <fg=yellow>%d asset(s) were not checked</> because of --limit. Raise it, or run '
                    .'this again to continue.',
                $skipped,
            ));
        }

        $this->newLine();
    }

    private function limit(): int
    {
        $option = $this->option('limit');

        if (is_string($option) && ctype_digit($option) && (int) $option > 0) {
            return (int) $option;
        }

        return max(1, (int) config('assets.verification.batch_size', 100));
    }
}
