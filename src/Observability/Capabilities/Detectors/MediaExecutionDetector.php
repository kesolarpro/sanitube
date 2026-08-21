<?php

declare(strict_types=1);

namespace SaniTube\Observability\Capabilities\Detectors;

use SaniTube\Media\Analyzers\StrategyAudioAnalyzer;
use SaniTube\Media\Contracts\AudioAnalyzer;
use SaniTube\Media\Contracts\AudioFingerprinter;
use SaniTube\Media\Execution\ExecutionPlace;
use SaniTube\Media\Execution\ExecutionStrategy;
use SaniTube\Media\Execution\MediaExecution;
use SaniTube\Media\Fingerprinters\StrategyAudioFingerprinter;
use SaniTube\Observability\Capabilities\Capability;
use SaniTube\Observability\Capabilities\CapabilityDetector;

/**
 * Where media work would actually run, and whether it can run at all.
 *
 * **Not "is a worker configured".** A URL and a token prove somebody typed two
 * settings. This reports what the platform would genuinely do with the next
 * asset, which is the only version of the answer worth putting on a screen —
 * a green tick that means "configured" becomes a support ticket the first time
 * a worker is down.
 *
 * It asks, and asking is cheap: the handshake is cached and the local check is
 * an executable lookup. **No probe is run and no audio is touched.** A doctor
 * screen that measured a file to prove it could would be one nobody opens
 * during an incident, which is exactly when it is needed.
 *
 * The interesting state is the third one. A worker selected and unreachable
 * while the local tool is present is *degraded*, not green and not red: the
 * work is happening, on the machine the operator did not choose, and they
 * should know before they wonder why a shared host is slow.
 */
final readonly class MediaExecutionDetector implements CapabilityDetector
{
    public function __construct(
        private MediaExecution $execution,
        private AudioAnalyzer $analyzer,
        private AudioFingerprinter $fingerprinter,
    ) {}

    public function detect(): Capability
    {
        $strategy = $this->execution->strategy();
        $probe = $this->placeOf($this->analyzer);
        $fingerprint = $this->placeOf($this->fingerprinter);

        if ($probe === ExecutionPlace::Nowhere && $fingerprint === ExecutionPlace::Nowhere) {
            return $this->nothingAvailable($strategy);
        }

        // A worker was wanted and something is running locally instead. Real
        // work is happening; the operator's choice is not being honoured.
        if ($strategy === ExecutionStrategy::Auto
            && $this->execution->workerConfigured()
            && ($probe === ExecutionPlace::Local || $fingerprint === ExecutionPlace::Local)) {
            return Capability::degraded(
                key: 'media_execution',
                label: 'Media processing',
                detail: sprintf(
                    'REMOTE_WORKER_UNAVAILABLE_LOCAL_AVAILABLE — probe: %s, fingerprint: %s.',
                    $probe->value,
                    $fingerprint->value,
                ),
                remediation: $this->execution->workerReachable()
                    ? 'The worker answered but does not advertise this work. Check its tools and its own '
                        .'configuration, then re-run the doctor.'
                    : 'The worker did not answer its handshake. Check SANITUBE_WORKER_URL, the token, and '
                        .'whether the worker host is reachable from here.',
            );
        }

        return Capability::available(
            key: 'media_execution',
            label: 'Media processing',
            detail: sprintf(
                '%s — probe: %s, fingerprint: %s.',
                $probe === ExecutionPlace::RemoteWorker || $fingerprint === ExecutionPlace::RemoteWorker
                    ? 'REMOTE_WORKER_AVAILABLE'
                    : 'LOCAL_AVAILABLE',
                $probe->value,
                $fingerprint->value,
            ),
        );
    }

    /**
     * Neither road works.
     *
     * **Optional, not blocking.** A cPanel account with no FFmpeg and no worker
     * still ingests, catalogues and releases; it simply cannot measure. Marking
     * this required would make a supported deployment look broken.
     */
    private function nothingAvailable(ExecutionStrategy $strategy): Capability
    {
        if ($strategy === ExecutionStrategy::RemoteWorker) {
            return Capability::optional(
                key: 'media_execution',
                label: 'Media processing',
                detail: 'UNAVAILABLE — a worker is selected and cannot serve this work. Local tools are not '
                    .'used, deliberately: `remote_worker` means what it says.',
                remediation: 'Start the worker, or set SANITUBE_MEDIA_EXECUTION=auto to let this machine do '
                    .'the work when the worker is unavailable.',
            );
        }

        return Capability::optional(
            key: 'media_execution',
            label: 'Media processing',
            detail: 'UNAVAILABLE — no local tools and no worker advertising this work. Assets are still '
                .'stored and catalogued; they are not measured.',
            remediation: 'Install FFmpeg and Chromaprint on this server, or configure a worker — see '
                .'docs/deployment/worker.md.',
        );
    }

    private function placeOf(object $tool): ExecutionPlace
    {
        // The dispatchers know where they would run. Anything else is a plain
        // local implementation, which is available or it is not.
        if ($tool instanceof StrategyAudioAnalyzer || $tool instanceof StrategyAudioFingerprinter) {
            return $tool->place();
        }

        /** @var AudioAnalyzer|AudioFingerprinter $tool */
        return $tool->isAvailable() ? ExecutionPlace::Local : ExecutionPlace::Nowhere;
    }
}
