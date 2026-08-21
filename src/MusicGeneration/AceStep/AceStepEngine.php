<?php

declare(strict_types=1);

namespace SaniTube\MusicGeneration\AceStep;

use SaniTube\MusicGeneration\Contracts\GeneratedAudioReader;
use SaniTube\MusicGeneration\GenerationRequest;
use SaniTube\MusicGeneration\Providers\SelfHostedAceStepProvider;

/**
 * The ACE-Step process itself, as the worker sees it.
 *
 * A seam, for the same reason {@see GeneratedAudioReader}
 * is one: the worker's real work — choosing a safe output path, checking what
 * came back, validating the file, staging it, cleaning up — must be testable
 * without a GPU, a model checkpoint and a Python process.
 *
 * **It is not a SaniTube provider.** Nothing in the domain resolves one of
 * these. It exists only inside the worker, behind the authenticated boundary,
 * and the thing SaniTube Core talks to is
 * {@see SelfHostedAceStepProvider}.
 */
interface AceStepEngine
{
    /**
     * Ask the engine to render to a path the **worker** chose.
     *
     * The path is an input rather than an output for a reason: ACE-Step will
     * generate one itself if given none, and a filename this worker did not
     * choose is a filename it cannot reason about afterwards. What comes back
     * is still checked — see {@see ContainedPath} — because an engine that
     * ignores the path it was given is exactly the case worth catching.
     *
     * @throws AceStepUnavailable when the engine cannot be reached or refuses
     */
    public function render(GenerationRequest $request, string $outputPath): AceStepOutput;

    /**
     * Whether the engine answers at all.
     *
     * ACE-Step publishes `GET /health`. Asked on a page load, so it must be
     * cheap and must never throw.
     */
    public function isHealthy(): bool;
}
