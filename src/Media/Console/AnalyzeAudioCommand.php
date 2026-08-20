<?php

declare(strict_types=1);

namespace SaniTube\Media\Console;

use Illuminate\Console\Command;
use SaniTube\Ingestion\Enums\TrackCandidateStatus;
use SaniTube\Ingestion\Models\TrackCandidate;
use SaniTube\Media\Models\AudioAnalysis;
use SaniTube\Media\Services\AnalyzeAsset;
use SaniTube\Media\Services\ApplyEmbeddedTags;
use SaniTube\Media\Services\SettleCandidateAfterAnalysis;

/**
 * Analyses the candidates that have not been analysed yet.
 *
 * This is what makes `WAITING_CAPABILITY` a waiting room rather than a dead
 * end. A host that had no FFmpeg when 900 files were imported, and has one now,
 * needs a way to go back over them — and without this command that way is
 * "re-import everything", which would duplicate the entire catalogue.
 *
 * It is bounded by default so it finishes inside a shared host's cron window
 * instead of being killed halfway through, and it says what it left behind. A
 * truncated pass that reads like a complete one is worse than no pass.
 */
final class AnalyzeAudioCommand extends Command
{
    protected $signature = 'sanitube:media:analyze
                            {--limit=200 : Maximum candidates to analyse in this pass}
                            {--force : Re-analyse candidates that already have an analysis at this version}
                            {--json : Output the outcome as JSON}';

    protected $description = 'Run technical analysis over candidates that are still waiting for it';

    public function handle(AnalyzeAsset $analyzer, SettleCandidateAfterAnalysis $settler, ApplyEmbeddedTags $tags): int
    {
        if (! $analyzer->isAvailable()) {
            $this->warn(
                'No audio analyser is available on this server. Install FFmpeg, or point '
                    .'SANITUBE_FFPROBE_PATH at an uploaded static build. Nothing was changed.',
            );

            // Not a failure exit code: an install without FFmpeg is a
            // supported configuration, and a nightly cron that mails an error
            // every night trains its reader to ignore it.
            return self::SUCCESS;
        }

        $limit = max(1, (int) $this->option('limit'));
        $force = (bool) $this->option('force');

        $candidates = TrackCandidate::query()
            ->whereIn('status', [
                TrackCandidateStatus::Pending->value,
                TrackCandidateStatus::Processing->value,
                TrackCandidateStatus::WaitingCapability->value,
            ])
            ->orderBy('id')
            ->limit($limit)
            ->get();

        $analysed = 0;
        $settled = [];

        foreach ($candidates as $candidate) {
            if (AnalyzeAsset::appliesTo($candidate->asset)) {
                $analyzer->handle($candidate->asset, force: $force);
                $analysed++;
            }

            // TAG-001. The same reading the queued job does, so a catalogue
            // brought in by command arrives at review with the same
            // information as one brought in by the import screen.
            $tags->handle($candidate->refresh(), AudioAnalysis::query()
                ->where('asset_id', $candidate->asset_id)
                ->orderByDesc('id')
                ->first());

            $status = $settler->handle($candidate->refresh())->status;
            $settled[$status->value] = ($settled[$status->value] ?? 0) + 1;
        }

        $remaining = TrackCandidate::query()
            ->whereIn('status', [
                TrackCandidateStatus::Pending->value,
                TrackCandidateStatus::WaitingCapability->value,
            ])
            ->count();

        return $this->report($candidates->count(), $analysed, $settled, $remaining);
    }

    /**
     * @param  array<string, int>  $settled
     */
    private function report(int $considered, int $analysed, array $settled, int $remaining): int
    {
        ksort($settled);

        if ($this->option('json')) {
            $this->line((string) json_encode([
                'considered' => $considered,
                'analysed' => $analysed,
                'settled' => $settled,
                'remaining' => $remaining,
            ], JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $this->info(sprintf('Considered %d candidate(s); analysed %d.', $considered, $analysed));

        foreach ($settled as $status => $count) {
            $this->line(sprintf('  %-20s %d', $status, $count));
        }

        if ($remaining > 0) {
            // Said out loud, because the limit is the whole reason a pass can
            // look finished while it is not.
            $this->warn(sprintf('%d candidate(s) still awaiting analysis. Run again to continue.', $remaining));
        }

        return self::SUCCESS;
    }
}
