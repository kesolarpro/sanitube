<?php

declare(strict_types=1);

namespace SaniTube\Observability\Capabilities\Detectors;

use SaniTube\Observability\Capabilities\Capability;
use SaniTube\Observability\Capabilities\CapabilityDetector;
use Symfony\Component\Process\ExecutableFinder;

/**
 * FFmpeg drives audio analysis and derivative generation.
 *
 * It is frequently absent on shared hosting, so it is reported as a missing
 * *optional* capability: ingestion still registers assets, it simply cannot
 * analyse them until FFmpeg appears.
 */
final readonly class FfmpegDetector implements CapabilityDetector
{
    public function __construct(private ExecutableFinder $finder = new ExecutableFinder) {}

    public function detect(): Capability
    {
        $configured = (string) config('media.ffmpeg.path', 'ffmpeg');

        $resolved = $this->resolve($configured);

        if ($resolved === null) {
            return Capability::optional(
                key: 'ffmpeg',
                label: 'FFmpeg',
                detail: sprintf('[%s] was not found on this server.', $configured),
                remediation: 'Audio analysis and derivative generation stay disabled until FFmpeg is available. '
                    .'Debian/Ubuntu: apt install ffmpeg; AlmaLinux/Rocky: dnf install ffmpeg; '
                    .'shared hosting: upload a static build and set SANITUBE_FFMPEG_PATH to it.',
            );
        }

        return Capability::available('ffmpeg', 'FFmpeg', sprintf('Found at %s.', $resolved));
    }

    private function resolve(string $configured): ?string
    {
        // An absolute path is used as-is: on shared hosting FFmpeg is often a
        // static binary uploaded into the account, outside any PATH directory.
        if (str_contains($configured, '/')) {
            return is_file($configured) && is_executable($configured) ? $configured : null;
        }

        return $this->finder->find($configured);
    }
}
