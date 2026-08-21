<?php

declare(strict_types=1);

namespace SaniTube\Media;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use SaniTube\Ingestion\Events\TrackCandidateCreated;
use SaniTube\Ingestion\Events\TrackCandidatePromoted;
use SaniTube\Media\Analyzers\FfprobeAudioAnalyzer;
use SaniTube\Media\Analyzers\RemoteAudioAnalyzer;
use SaniTube\Media\Analyzers\StrategyAudioAnalyzer;
use SaniTube\Media\Analyzers\UnavailableAudioAnalyzer;
use SaniTube\Media\Console\AnalyzeAudioCommand;
use SaniTube\Media\Contracts\AudioAnalyzer;
use SaniTube\Media\Contracts\AudioFingerprinter;
use SaniTube\Media\Execution\MediaExecution;
use SaniTube\Media\Fingerprinters\ChromaprintFingerprinter;
use SaniTube\Media\Fingerprinters\RemoteAudioFingerprinter;
use SaniTube\Media\Fingerprinters\StrategyAudioFingerprinter;
use SaniTube\Media\Fingerprinters\UnavailableAudioFingerprinter;
use SaniTube\Media\Listeners\RecordMeasuredDurationOnTrack;
use SaniTube\Media\Listeners\ScheduleCandidateAnalysis;
use SaniTube\Media\Listeners\ScheduleCandidateFingerprint;
use SaniTube\Media\Worker\AudioFingerprintJobHandler;
use SaniTube\Media\Worker\MediaProbeJobHandler;
use SaniTube\Media\Worker\StagedObject;
use SaniTube\Worker\Enums\WorkerCapability;
use SaniTube\Worker\WorkerRegistry;

final class MediaServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        /*
         * The analyser is chosen by what the server can actually do. An
         * install without FFmpeg gets one that says so, rather than one that
         * throws — the difference between a capability and a dependency.
         */
        // The local tool, still exactly as before -- and still the whole answer
        // on a VPS that has it.
        $this->app->bind('media.analyzer.local', function (Application $app): AudioAnalyzer {
            $ffprobe = $app->make(FfprobeAudioAnalyzer::class);

            return $ffprobe->isAvailable() ? $ffprobe : new UnavailableAudioAnalyzer;
        });

        /*
         * What every caller holds. WRK-002: it decides per call whether the
         * work runs here or on a worker, and then gets out of the way -- no
         * service, listener or job knows which machine ran ffprobe.
         *
         * Local is unchanged and remains valid. A worker is preferred only when
         * one is configured *and* its handshake advertises the capability;
         * configuration alone proves nothing.
         */
        $this->app->bind(AudioAnalyzer::class, fn (Application $app): AudioAnalyzer => new StrategyAudioAnalyzer(
            $app->make(MediaExecution::class),
            $app->make('media.analyzer.local'),
            $app->make(RemoteAudioAnalyzer::class),
        ));

        /*
         * The same reasoning for fingerprinting, and it matters more here:
         * Chromaprint is absent on essentially every shared cPanel account.
         * An install without it can still store, analyse and promote — it
         * simply cannot tell that two differently-encoded files are the same
         * recording, which is a feature it lacks rather than a failure.
         */
        $this->app->bind('media.fingerprinter.local', function (Application $app): AudioFingerprinter {
            $chromaprint = $app->make(ChromaprintFingerprinter::class);

            return $chromaprint->isAvailable() ? $chromaprint : new UnavailableAudioFingerprinter;
        });

        $this->app->bind(
            AudioFingerprinter::class,
            fn (Application $app): AudioFingerprinter => new StrategyAudioFingerprinter(
                $app->make(MediaExecution::class),
                $app->make('media.fingerprinter.local'),
                $app->make(RemoteAudioFingerprinter::class),
            ),
        );
    }

    public function boot(): void
    {
        /*
         * This installation offering itself as a media worker.
         *
         * Registered lazily, and only ever *runnable* when the local tool is
         * actually present -- so a Core-only installation registers the
         * capability and honestly reports that it cannot serve it.
         *
         * The handlers take the **local** implementations deliberately. A
         * worker that resolved the strategy would ask itself, or another
         * worker, and a chain of workers forwarding a probe to each other is
         * not a topology anybody meant to deploy.
         */
        $registry = $this->app->make(WorkerRegistry::class);

        $registry->register(
            WorkerCapability::MediaProbe,
            fn (): MediaProbeJobHandler => new MediaProbeJobHandler(
                $this->app->make('media.analyzer.local'),
                $this->app->make(StagedObject::class),
            ),
        );

        $registry->register(
            WorkerCapability::AudioFingerprint,
            fn (): AudioFingerprintJobHandler => new AudioFingerprintJobHandler(
                $this->app->make('media.fingerprinter.local'),
                $this->app->make(StagedObject::class),
            ),
        );

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
