<?php

declare(strict_types=1);

namespace Tests\Feature\Media;

use PHPUnit\Framework\Attributes\Test;
use SaniTube\Media\Analyzers\FfprobeAudioAnalyzer;
use SaniTube\Media\Analyzers\ProbeRunner;
use SaniTube\Media\Analyzers\UnavailableAudioAnalyzer;
use Symfony\Component\Process\ExecutableFinder;
use Tests\TestCase;

/**
 * Reading ffprobe's output, on a machine that may not have ffprobe.
 *
 * The parsing is where the mistakes are, and a parser tested only where the
 * binary happens to be installed is a parser tested almost nowhere — three of
 * the ten CI jobs would silently skip it. So the fixtures below are captured
 * output, and `parse()` is public for exactly this reason.
 *
 * One test does run the real binary, and skips when it is absent. It is there
 * to catch the day ffprobe changes its JSON; it is not what proves the parser.
 */
final class FfprobeOutputTest extends TestCase
{
    // -------------------------------------------------------- real captures

    #[Test]
    public function it_reads_a_24_bit_wav_master(): void
    {
        $result = $this->analyzer()->parse(<<<'JSON'
        {
            "streams": [
                {
                    "index": 0,
                    "codec_name": "pcm_s24le",
                    "codec_long_name": "PCM signed 24-bit little-endian",
                    "codec_type": "audio",
                    "sample_fmt": "s32",
                    "sample_rate": "48000",
                    "channels": 2,
                    "channel_layout": "stereo",
                    "bits_per_sample": 24,
                    "bits_per_raw_sample": "24",
                    "duration": "213.546667",
                    "bit_rate": "2304000"
                }
            ],
            "format": {
                "filename": "master.wav",
                "nb_streams": 1,
                "format_name": "wav",
                "format_long_name": "WAV / WAVE (Waveform Audio)",
                "duration": "213.546667",
                "size": "61501440",
                "bit_rate": "2304000"
            }
        }
        JSON);

        $this->assertTrue($result->succeeded);
        $this->assertSame(213_547, $result->durationMs);
        $this->assertSame('pcm_s24le', $result->codec);
        $this->assertSame('wav', $result->container);
        $this->assertSame(48_000, $result->sampleRate);
        $this->assertSame(24, $result->bitDepth);
        $this->assertSame(2, $result->channels);
        $this->assertSame(2_304_000, $result->bitrate);
    }

    #[Test]
    public function it_reads_an_mp3_and_leaves_bit_depth_absent(): void
    {
        // A missing field is a fact about the format, not a failure. MP3 has
        // no bit depth; substituting zero would put a number in the catalogue
        // that nothing measured.
        $result = $this->analyzer()->parse(<<<'JSON'
        {
            "streams": [
                {
                    "codec_name": "mp3",
                    "codec_type": "audio",
                    "sample_rate": "44100",
                    "channels": 2,
                    "duration": "185.234000",
                    "bit_rate": "320000"
                }
            ],
            "format": {"format_name": "mp3", "duration": "185.273469", "bit_rate": "320044"}
        }
        JSON);

        $this->assertTrue($result->succeeded);
        $this->assertNull($result->bitDepth);
        $this->assertSame('mp3', $result->codec);
        $this->assertSame(320_000, $result->bitrate);
        // The stream's duration wins over the container's: they disagree by
        // 40 ms here, and the stream is the thing that will be played.
        $this->assertSame(185_234, $result->durationMs);
    }

    #[Test]
    public function it_settles_on_one_container_when_the_demuxer_lists_several(): void
    {
        // ffprobe reports every format the demuxer matched. Storing the whole
        // list would make `container` unsearchable.
        $result = $this->analyzer()->parse(<<<'JSON'
        {
            "streams": [{"codec_name": "alac", "codec_type": "audio", "sample_rate": "44100", "channels": 2}],
            "format": {"format_name": "mov,mp4,m4a,3gp,3g2,mj2", "duration": "240.1"}
        }
        JSON);

        $this->assertSame('mov', $result->container);
    }

    #[Test]
    public function it_falls_back_to_the_containers_duration_when_the_stream_has_none(): void
    {
        $result = $this->analyzer()->parse(<<<'JSON'
        {
            "streams": [{"codec_name": "flac", "codec_type": "audio", "sample_rate": "96000", "channels": 2}],
            "format": {"format_name": "flac", "duration": "12.5"}
        }
        JSON);

        $this->assertSame(12_500, $result->durationMs);
    }

    #[Test]
    public function it_prefers_the_raw_sample_depth_over_the_stored_one(): void
    {
        // 24-bit audio in a 32-bit container. `bits_per_sample` says 32 and is
        // true of the file; `bits_per_raw_sample` says 24 and is true of the
        // recording, which is what a mastering engineer is asking about.
        $result = $this->analyzer()->parse(<<<'JSON'
        {
            "streams": [
                {
                    "codec_name": "pcm_s32le", "codec_type": "audio", "sample_rate": "96000",
                    "channels": 2, "bits_per_sample": 32, "bits_per_raw_sample": "24", "duration": "10.0"
                }
            ],
            "format": {"format_name": "wav", "duration": "10.0"}
        }
        JSON);

        $this->assertSame(24, $result->bitDepth);
    }

    // ------------------------------------------------------ what is refused

    #[Test]
    public function a_file_with_no_audio_stream_is_a_failure_not_an_empty_success(): void
    {
        $result = $this->analyzer()->parse('{"streams": [], "format": {"format_name": "mp4"}}');

        $this->assertFalse($result->succeeded);
        $this->assertNotNull($result->failureMessage);
    }

    #[Test]
    public function a_container_holding_nothing_measurable_is_a_failure(): void
    {
        // The subtle one. ffprobe exits 0 and reports a stream, but there is
        // nothing in it. Recording that as a successful analysis with every
        // column null would make "analysed" mean nothing at all.
        $result = $this->analyzer()->parse(<<<'JSON'
        {
            "streams": [{"codec_type": "audio", "channels": 2}],
            "format": {"format_name": "wav"}
        }
        JSON);

        $this->assertFalse($result->succeeded);
    }

    #[Test]
    public function output_that_is_not_json_is_a_failure_rather_than_an_exception(): void
    {
        // An analyser that throws takes the worker down with it. 900 files
        // deserve better than stopping at the first odd one.
        $result = $this->analyzer()->parse('ffprobe version 6.1.1 Copyright (c) 2007-2023');

        $this->assertFalse($result->succeeded);
    }

    #[Test]
    public function a_video_stream_is_skipped_in_favour_of_the_audio_one(): void
    {
        $result = $this->analyzer()->parse(<<<'JSON'
        {
            "streams": [
                {"codec_name": "h264", "codec_type": "video", "width": 1920},
                {"codec_name": "aac", "codec_type": "audio", "sample_rate": "48000", "channels": 2, "duration": "60.0"}
            ],
            "format": {"format_name": "mov,mp4", "duration": "60.0"}
        }
        JSON);

        $this->assertTrue($result->succeeded);
        $this->assertSame('aac', $result->codec);
    }

    // ----------------------------------------------------- absence, handled

    #[Test]
    public function an_absent_binary_is_reported_as_unavailable_not_as_a_bad_file(): void
    {
        $analyzer = new FfprobeAudioAnalyzer(new ProbeRunner, new ExecutableFinder);

        config(['media.ffprobe.path' => '/nonexistent/path/to/ffprobe']);

        $this->assertFalse($analyzer->isAvailable());

        $result = $analyzer->analyze(__FILE__);

        $this->assertFalse($result->succeeded);
        $this->assertStringContainsString('not available', (string) $result->failureMessage);
    }

    #[Test]
    public function the_fallback_analyser_never_blames_the_file(): void
    {
        $result = (new UnavailableAudioAnalyzer)->analyze('/anything.wav');

        $this->assertFalse($result->succeeded);
        $this->assertFalse((new UnavailableAudioAnalyzer)->isAvailable());
        $this->assertStringContainsString(
            'nothing about the file is in question',
            (string) $result->failureMessage,
        );
    }

    #[Test]
    public function a_file_that_cannot_be_read_is_refused_before_the_probe_runs(): void
    {
        $result = $this->analyzer()->analyze('/nonexistent/master.wav');

        $this->assertFalse($result->succeeded);
    }

    // ------------------------------------------------------- the real thing

    #[Test]
    public function the_real_binary_reads_a_wav_this_test_generated(): void
    {
        $analyzer = new FfprobeAudioAnalyzer(new ProbeRunner, new ExecutableFinder);

        config(['media.ffprobe.path' => 'ffprobe']);

        if (! $analyzer->isAvailable()) {
            $this->markTestSkipped('ffprobe is not installed here, which is a supported configuration.');
        }

        $path = tempnam(sys_get_temp_dir(), 'sanitube-probe-').'.wav';
        file_put_contents($path, $this->silentWav(seconds: 1, sampleRate: 8_000));

        try {
            $result = $analyzer->analyze($path);
        } finally {
            @unlink($path);
        }

        $this->assertTrue($result->succeeded, (string) $result->failureMessage);
        $this->assertSame(8_000, $result->sampleRate);
        $this->assertSame(1, $result->channels);
        $this->assertSame(1_000, $result->durationMs);
        $this->assertSame('wav', $result->container);
    }

    // --------------------------------------------------------------- helpers

    private function analyzer(): FfprobeAudioAnalyzer
    {
        return new FfprobeAudioAnalyzer(new ProbeRunner, new ExecutableFinder);
    }

    /**
     * A real, minimal 16-bit mono WAV — header and silence.
     *
     * Generated rather than committed. A binary fixture in Git is a file
     * nobody can review and every clone has to carry for ever.
     */
    private function silentWav(int $seconds, int $sampleRate): string
    {
        $samples = $seconds * $sampleRate;
        $dataSize = $samples * 2;

        return 'RIFF'
            .pack('V', 36 + $dataSize)
            .'WAVEfmt '
            .pack('V', 16)          // subchunk size
            .pack('v', 1)           // PCM
            .pack('v', 1)           // mono
            .pack('V', $sampleRate)
            .pack('V', $sampleRate * 2)  // byte rate
            .pack('v', 2)           // block align
            .pack('v', 16)          // bits per sample
            .'data'
            .pack('V', $dataSize)
            .str_repeat("\0", $dataSize);
    }
}
