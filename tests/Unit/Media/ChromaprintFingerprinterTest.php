<?php

declare(strict_types=1);

namespace Tests\Unit\Media;

use PHPUnit\Framework\Attributes\Test;
use SaniTube\Media\Exceptions\FingerprintFailed;
use SaniTube\Media\Fingerprinters\ChromaprintFingerprinter;
use SaniTube\Media\Fingerprints\FingerprintPoints;
use SaniTube\Media\Testing\FakeAudioFingerprinter;
use Tests\Support\Media\RecordingProbeRunner;
use Tests\Support\Media\StubExecutableFinder;
use Tests\TestCase;

/**
 * DEDUP-001 — reading what `fpcalc` actually says.
 *
 * Tested against captured output rather than a real Chromaprint, because the
 * parser is where the bugs live and CI mostly runs on machines with no
 * Chromaprint at all. A parser only exercised where the tool happens to be
 * installed is a parser that is not exercised.
 *
 * This matters more than it looks. Every other test in this feature runs
 * against {@see FakeAudioFingerprinter}, which emits
 * well-formed points by construction. If the real reader misread `fpcalc`,
 * every one of those tests would stay green while every fingerprint in a
 * production catalogue was nonsense.
 */
final class ChromaprintFingerprinterTest extends TestCase
{
    #[Test]
    public function the_raw_words_are_read_and_stored_in_comparable_form(): void
    {
        $runner = $this->runnerReturning('{"duration": 217.32, "fingerprint": [1929553874, 1929557970, 1933748305]}');

        $result = $this->fingerprinter($runner)->fingerprint('/tmp/master.wav');

        $this->assertSame('1929553874,1929557970,1933748305', $result->fingerprint);
        $this->assertSame([1929553874, 1929557970, 1933748305], FingerprintPoints::decode($result->fingerprint));

        // Rounded from the tool's own float, not from the analysis row.
        $this->assertSame(217, $result->durationSeconds);
        $this->assertSame('chromaprint:2', $result->algorithm);
    }

    #[Test]
    public function the_raw_flag_is_passed_and_the_filename_stays_a_separate_argument(): void
    {
        $runner = $this->runnerReturning('{"duration": 10.0, "fingerprint": [1, 2, 3]}');

        $this->fingerprinter($runner)->fingerprint('/tmp/; rm -rf ~.wav');

        // Without `-raw` the tool prints the compressed base64 form, which can
        // be compared for equality and nothing else -- and equality was never
        // the question this feature exists to answer.
        $this->assertContains('-raw', $runner->arguments);
        $this->assertContains('-json', $runner->arguments);

        // A filename is caller-controlled. It reaches the process as one
        // element of an argument list, so no shell ever sees it.
        $this->assertContains('/tmp/; rm -rf ~.wav', $runner->arguments);
    }

    #[Test]
    public function the_compressed_form_is_refused_rather_than_stored(): void
    {
        // What `fpcalc` prints when `-raw` is dropped. Storing that string
        // under a version promising raw words would not fail -- it would make
        // every later comparison quietly meaningless, which is worse.
        $runner = $this->runnerReturning('{"duration": 217.32, "fingerprint": "AQADtEmkRUmSJXjw4cOHDx8-fPjw4cOHDw"}');

        $this->expectException(FingerprintFailed::class);

        $this->fingerprinter($runner)->fingerprint('/tmp/master.wav');
    }

    #[Test]
    public function output_with_no_usable_words_is_refused(): void
    {
        $runner = $this->runnerReturning('{"duration": 217.32, "fingerprint": []}');

        $this->expectException(FingerprintFailed::class);

        $this->fingerprinter($runner)->fingerprint('/tmp/master.wav');
    }

    #[Test]
    public function output_that_is_not_json_at_all_is_refused(): void
    {
        $runner = $this->runnerReturning('ERROR: unable to calculate fingerprint');

        $this->expectException(FingerprintFailed::class);

        $this->fingerprinter($runner)->fingerprint('/tmp/master.wav');
    }

    #[Test]
    public function an_absent_tool_is_reported_rather_than_guessed_around(): void
    {
        $fingerprinter = new ChromaprintFingerprinter(
            $this->runnerReturning('{}'),
            new StubExecutableFinder(null),
        );

        $this->assertFalse($fingerprinter->isAvailable());
        $this->expectException(FingerprintFailed::class);
        $fingerprinter->fingerprint('/tmp/master.wav');
    }

    private function fingerprinter(RecordingProbeRunner $runner): ChromaprintFingerprinter
    {
        return new ChromaprintFingerprinter($runner, new StubExecutableFinder('/usr/bin/fpcalc'));
    }

    private function runnerReturning(string $output): RecordingProbeRunner
    {
        return new RecordingProbeRunner($output);
    }
}
