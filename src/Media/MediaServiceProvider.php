<?php

declare(strict_types=1);

namespace SaniTube\Media;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use SaniTube\Ingestion\Events\TrackCandidateCreated;
use SaniTube\Media\Analyzers\FfprobeAudioAnalyzer;
use SaniTube\Media\Analyzers\UnavailableAudioAnalyzer;
use SaniTube\Media\Console\AnalyzeAudioCommand;
use SaniTube\Media\Contracts\AudioAnalyzer;
use SaniTube\Media\Listeners\ScheduleCandidateAnalysis;

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
    }

    public function boot(): void
    {
        // Ingestion does not know whether this server can analyse anything,
        // and should not have to. It announces that a candidate exists; Media
        // decides what to do about it.
        $this->app['events']->listen(TrackCandidateCreated::class, ScheduleCandidateAnalysis::class);

        if ($this->app->runningInConsole()) {
            $this->commands([AnalyzeAudioCommand::class]);
        }
    }
}
