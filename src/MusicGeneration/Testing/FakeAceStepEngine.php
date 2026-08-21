<?php

declare(strict_types=1);

namespace SaniTube\MusicGeneration\Testing;

use SaniTube\MusicGeneration\AceStep\AceStepEngine;
use SaniTube\MusicGeneration\AceStep\AceStepOutput;
use SaniTube\MusicGeneration\AceStep\AceStepUnavailable;
use SaniTube\MusicGeneration\GenerationRequest;

/**
 * An ACE-Step that writes a file and needs no GPU.
 *
 * What makes the worker's real work testable: choosing a safe output path,
 * checking what came back, validating the file, staging it, cleaning up. None
 * of that needs a model, and a test that needed one would be a test nobody
 * runs.
 *
 * It can also **misbehave on purpose**, which is the point. An engine that
 * writes somewhere other than where it was told is not a hypothetical — it is
 * the case the containment check exists for — and there is no other way to
 * produce it.
 */
final class FakeAceStepEngine implements AceStepEngine
{
    /** Where it will actually write, when told to ignore instructions. */
    private ?string $writeInstead = null;

    /** What it will claim to have written, when told to lie. */
    private ?string $claimInstead = null;

    private bool $healthy = true;

    private bool $reachable = true;

    private string $status = 'success';

    private string $contents = 'RIFF....WAVEfmt ';

    public int $calls = 0;

    /** @var list<array<string, mixed>> */
    public array $requests = [];

    public function render(GenerationRequest $request, string $outputPath): AceStepOutput
    {
        $this->calls++;
        $this->requests[] = [
            'prompt' => $request->prompt,
            'lyrics' => $request->lyrics,
            'instrumental' => $request->instrumental,
            'output_path' => $outputPath,
        ];

        if (! $this->reachable) {
            throw AceStepUnavailable::unreachable();
        }

        $written = $this->writeInstead ?? $outputPath;

        if ($this->status === 'success') {
            @mkdir(dirname($written), 0o755, true);
            file_put_contents($written, $this->contents);
        }

        return new AceStepOutput(
            status: $this->status,
            outputPath: $this->claimInstead ?? $written,
            message: 'fake',
        );
    }

    public function isHealthy(): bool
    {
        return $this->healthy;
    }

    /** Write somewhere other than where it was told. */
    public function writingInstead(string $path): self
    {
        $this->writeInstead = $path;

        return $this;
    }

    /** Report a path other than the one it wrote. */
    public function claiming(string $path): self
    {
        $this->claimInstead = $path;

        return $this;
    }

    public function failing(): self
    {
        $this->status = 'error';

        return $this;
    }

    public function unreachable(): self
    {
        $this->reachable = false;
        $this->healthy = false;

        return $this;
    }

    public function producing(string $contents): self
    {
        $this->contents = $contents;

        return $this;
    }
}
