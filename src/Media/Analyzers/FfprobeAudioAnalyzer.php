<?php

declare(strict_types=1);

namespace SaniTube\Media\Analyzers;

use SaniTube\Media\AudioAnalysisResult;
use SaniTube\Media\Contracts\AudioAnalyzer;
use Symfony\Component\Process\ExecutableFinder;
use Throwable;

/**
 * Technical analysis via `ffprobe`.
 *
 * `ffprobe` rather than `ffmpeg`: it reads headers and reports, where `ffmpeg`
 * decodes. For duration, codec, sample rate and channel count the decode is
 * wasted work, and on a shared host with 900 files to get through, wasted work
 * is the difference between a nightly job that finishes and one that is killed.
 *
 * Everything it returns is treated as possibly absent. Containers disagree
 * about what they record — a WAV has bit depth and no bitrate, an MP3 the
 * reverse — and a missing field is a fact about the format, not a failure.
 * Substituting zero would put a number in the catalogue that nothing measured.
 */
final readonly class FfprobeAudioAnalyzer implements AudioAnalyzer
{
    /**
     * Bumped when the *meaning* of the output changes, not when the code is
     * edited. Stored against every analysis, so a later, better version can be
     * re-run over exactly the rows that need it.
     */
    public const VERSION = '1';

    public function __construct(
        private ProbeRunner $runner = new ProbeRunner,
        private ExecutableFinder $finder = new ExecutableFinder,
    ) {}

    public function name(): string
    {
        return 'ffprobe';
    }

    public function version(): string
    {
        return self::VERSION;
    }

    public function isAvailable(): bool
    {
        return $this->binary() !== null;
    }

    public function analyze(string $localPath): AudioAnalysisResult
    {
        $binary = $this->binary();

        if ($binary === null) {
            return AudioAnalysisResult::failed('ffprobe is not available on this server.');
        }

        if (! is_file($localPath) || ! is_readable($localPath)) {
            return AudioAnalysisResult::failed(sprintf('[%s] could not be read.', $localPath));
        }

        try {
            $output = $this->runner->run($binary, [
                '-v', 'error',
                '-print_format', 'json',
                '-show_format',
                '-show_streams',
                '-select_streams', 'a:0',
                $localPath,
            ], max(1, (int) config('media.ffprobe.timeout', 120)));
        } catch (Throwable $exception) {
            // A probe that refuses a file is telling us the file is not what
            // it claimed. That is a real finding, and distinct from the probe
            // being absent.
            return AudioAnalysisResult::failed($exception->getMessage());
        }

        return $this->parse($output);
    }

    /**
     * Turn ffprobe's JSON into measurements.
     *
     * Public so it can be exercised against captured output on a machine with
     * no FFmpeg. The parsing is where the mistakes are, and a parser only
     * tested where the binary happens to be installed is a parser tested
     * almost nowhere.
     */
    public function parse(string $json): AudioAnalysisResult
    {
        /** @var array<string, mixed>|null $decoded */
        $decoded = json_decode($json, true);

        if (! is_array($decoded)) {
            return AudioAnalysisResult::failed('ffprobe returned output that is not JSON.');
        }

        $stream = $this->firstAudioStream($decoded);
        $format = is_array($decoded['format'] ?? null) ? $decoded['format'] : [];

        if ($stream === null) {
            return AudioAnalysisResult::failed('The file contains no audio stream.');
        }

        $duration = $this->float($stream['duration'] ?? null) ?? $this->float($format['duration'] ?? null);

        $result = AudioAnalysisResult::measured(
            durationMs: $duration === null ? null : (int) round($duration * 1000),
            codec: $this->string($stream['codec_name'] ?? null),
            container: $this->container($format),
            sampleRate: $this->int($stream['sample_rate'] ?? null),
            // Reported under two names depending on the codec, and absent for
            // most compressed formats.
            bitDepth: $this->int($stream['bits_per_raw_sample'] ?? null)
                ?? $this->int($stream['bits_per_sample'] ?? null),
            channels: $this->int($stream['channels'] ?? null),
            bitrate: $this->int($stream['bit_rate'] ?? null) ?? $this->int($format['bit_rate'] ?? null),
            raw: $decoded,
        );

        // A probe can succeed against a container that holds nothing usable.
        // Recording that as a successful analysis with every field null would
        // make "analysed" mean nothing.
        return $result->isEmpty()
            ? AudioAnalysisResult::failed('ffprobe reported no measurable audio properties.')
            : $result;
    }

    /**
     * @param  array<string, mixed>  $decoded
     * @return array<string, mixed>|null
     */
    private function firstAudioStream(array $decoded): ?array
    {
        $streams = $decoded['streams'] ?? null;

        if (! is_array($streams)) {
            return null;
        }

        foreach ($streams as $stream) {
            if (! is_array($stream)) {
                continue;
            }

            // `-select_streams a:0` should have done this already; asking
            // again costs nothing and covers output captured without it.
            if (($stream['codec_type'] ?? 'audio') === 'audio') {
                /** @var array<string, mixed> $stream */
                return $stream;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $format
     */
    private function container(array $format): ?string
    {
        $name = $this->string($format['format_name'] ?? null);

        if ($name === null) {
            return null;
        }

        // ffprobe reports a comma-separated list of every format the
        // demuxer matched ("mov,mp4,m4a,3gp,3g2,mj2"). The first is the one
        // it settled on.
        return explode(',', $name)[0];
    }

    private function binary(): ?string
    {
        $configured = (string) config('media.ffprobe.path', 'ffprobe');

        // An absolute path is used as-is: on shared hosting the binary is
        // usually uploaded into the account, outside any PATH directory.
        if (str_contains($configured, '/')) {
            return is_file($configured) && is_executable($configured) ? $configured : null;
        }

        return $this->finder->find($configured);
    }

    private function string(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private function int(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        return is_string($value) && ctype_digit($value) ? (int) $value : null;
    }

    private function float(mixed $value): ?float
    {
        if (is_float($value) || is_int($value)) {
            return (float) $value;
        }

        return is_string($value) && is_numeric($value) ? (float) $value : null;
    }
}
