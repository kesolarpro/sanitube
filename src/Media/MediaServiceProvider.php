<?php

declare(strict_types=1);

namespace SaniTube\Media;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use SaniTube\Ingestion\Events\TrackCandidateCreated;
use SaniTube\Ingestion\Events\TrackCandidatePromoted;
use SaniTube\Media\Analyzers\FfprobeAudioAnalyzer;
use SaniTube\Media\Analyzers\UnavailableAudioAnalyzer;
use SaniTube\Media\Console\AnalyzeAudioCommand;
use SaniTube\Media\Contracts\AudioAnalyzer;
use SaniTube\Media\Contracts\AudioFingerprinter;
use SaniTube\Media\Fingerprinters\ChromaprintFingerprinter;
use SaniTube\Media\Fingerprinters\UnavailableAudioFingerprinter;
use SaniTube\Media\Listeners\RecordMeasuredDurationOnTrack;
use SaniTube\Media\Listeners\ScheduleCandidateAnalysis;
use SaniTube\Media\Listeners\ScheduleCandidateFingerprint;

final class MediaServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        /*
         * The analyser is chosen by what the server can actually do. An
         * install without FFmpeg gets one that says so, rather than one that
         * throws — the difference between a capability and a dependency.
         */
        $this->app->bind(AudioAnalyzer::class, function (Application $app): AudioAnalyzer {
            $ffprobe = $app->make(FfprobeAudioAnalyzer::class);

            return $ffprobe->isAvailable() ? $ffprobe : new UnavailableAudioAnalyzer;
        });

        /*
         * The same reasoning for fingerprinting, and it matters more here:
         * Chromaprint is absent on essentially every shared cPanel account.
         * An install without it can still store, analyse and promote — it
         * simply cannot tell that two differently-encoded files are the same
         * recording, which is a feature it lacks rather than a failure.
         */
        $this->app->bind(AudioFingerprinter::class, function (Application $app): AudioFingerprinter {
            $chromaprint = $app->make(ChromaprintFingerprinter::class);

            return $chromaprint->isAvailable() ? $chromaprint : new UnavailableAudioFingerprinter;
        });
    }

    public function boot(): void
    {
        // Ingestion does not know whether this server can analyse anything,
        // and should not have to. It announces that a candidate exists; Media
        // decides what to do about it.
        $this->app['events']->listen(TrackCandidateCreated::class, ScheduleCandidateAnalysis::class);

        // Beside analysis rather than inside it. FFmpeg and Chromaprint are
        // installed independently, and a server with one and not the other
        // should get the half it can do -- folding the two together would make
        // a missing `fpcalc` look like a failed analysis.
        $this->app['events']->listen(TrackCandidateCreated::class, ScheduleCandidateFingerprint::class);

        // The catalogue takes the measurement Media made, rather than Media
        // being asked for it — which would point Ingestion at Media and close
        // a cycle between two modules that already reference each other.
        $this->app['events']->listen(TrackCandidatePromoted::class, RecordMeasuredDurationOnTrack::class);

        if ($this->app->runningInConsole()) {
            $this->commands([AnalyzeAudioCommand::class]);
        }
    }
}
