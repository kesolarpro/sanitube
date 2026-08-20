<?php

declare(strict_types=1);

namespace SaniTube\Transcription\Testing;

use SaniTube\Transcription\Contracts\TranscriptionProvider;
use SaniTube\Transcription\Exceptions\TranscriptionFailed;
use SaniTube\Transcription\TranscriptionResult;
use SaniTube\Transcription\TranscriptSegmentResult;

/**
 * A transcription supplier that needs no network and no key.
 *
 * It is deliberately **not** a speech model: it cannot hear anything, and every
 * answer it gives was put there by the test. That is the point — a double that
 * invented plausible text would let tests assert behaviour that depends on a
 * real model being right, which is the one thing no test can guarantee.
 *
 * It does record what it was asked, because the two facts most worth asserting
 * about this integration are that the supplier received a **local path** rather
 * than a URL, and that the operator's language hint was passed through rather
 * than being silently replaced by a default.
 */
final class FakeTranscriptionProvider implements TranscriptionProvider
{
    public ?string $receivedPath = null;

    public ?string $receivedHint = null;

    public int $calls = 0;

    private bool $available = true;

    private bool $fails = false;

    private string $version = '1';

    private ?TranscriptionResult $forced = null;

    public function willReturn(TranscriptionResult $result): self
    {
        $this->forced = $result;

        return $this;
    }

    /**
     * @param  list<array{int, int, string}>  $segments
     */
    public function willReturnText(string $text, array $segments = [], ?string $language = null): self
    {
        return $this->willReturn(new TranscriptionResult(
            text: $text,
            segments: array_map(
                static fn (array $s): TranscriptSegmentResult => new TranscriptSegmentResult($s[0], $s[1], $s[2]),
                $segments,
            ),
            languageCode: $language,
            model: 'fake-model',
        ));
    }

    public function unavailable(): self
    {
        $this->available = false;

        return $this;
    }

    public function failing(): self
    {
        $this->fails = true;

        return $this;
    }

    public function atVersion(string $version): self
    {
        $this->version = $version;

        return $this;
    }

    public function name(): string
    {
        return 'fake';
    }

    public function version(): string
    {
        return $this->version;
    }

    public function isAvailable(): bool
    {
        return $this->available;
    }

    public function transcribe(string $localPath, ?string $languageHint = null): TranscriptionResult
    {
        $this->calls++;
        $this->receivedPath = $localPath;
        $this->receivedHint = $languageHint;

        if ($this->fails) {
            throw TranscriptionFailed::callFailed($this->name());
        }

        return $this->forced ?? new TranscriptionResult(text: '', model: 'fake-model');
    }
}
