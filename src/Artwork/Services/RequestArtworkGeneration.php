<?php

declare(strict_types=1);

namespace SaniTube\Artwork\Services;

use SaniTube\Artwork\Contracts\ImageProvider;
use SaniTube\Artwork\Enums\ArtworkGenerationStatus;
use SaniTube\Artwork\Exceptions\ArtworkGenerationException;
use SaniTube\Artwork\Models\ArtworkGeneration;
use SaniTube\Operations\Services\BackgroundWork;
use SaniTube\Releases\Models\Release;

/**
 * Recording the intent to make a cover, before anything is sent.
 *
 * ART-004. `GenerateArtwork` has always been able to make an image and had no
 * safety around it: no record that a request happened, nothing to count, and
 * therefore no ceiling that could exist. ART-003 stopped short of wiring a
 * button for exactly that reason.
 *
 * **Two moments, deliberately separated.** This one records an intent and costs
 * nothing. {@see SubmitArtworkGeneration} is where a request leaves the server,
 * and it re-asks every question this asked — because a queue can run it hours
 * later with a hundred siblings ahead of it, and the ceiling that matters is
 * the one read at the moment the request would actually leave.
 *
 * Asking here as well is not redundant: it is what lets a person clicking a
 * button be told "no" immediately, in their own language, instead of watching a
 * row sit in a queue and fail silently.
 *
 * **A refusal here writes nothing.** No row, no attempt, no failure record —
 * there was no request. A refusal recorded as a failed generation would make
 * the circuit breaker count this platform's own caution as provider outages.
 */
final readonly class RequestArtworkGeneration
{
    public function __construct(
        private ImageProvider $provider,
        private ArtworkFeasibility $feasibility,
        private ArtworkSpendGuard $spend,
        private ArtworkCircuitBreaker $breaker,
        private BackgroundWork $work,
    ) {}

    /**
     * @throws ArtworkGenerationException
     */
    public function handle(
        string $prompt,
        ?Release $release = null,
        ?string $quality = null,
        ?int $requestedBy = null,
    ): ArtworkGeneration {
        $prompt = trim($prompt);

        if ($prompt === '') {
            throw ArtworkGenerationException::emptyPrompt();
        }

        $this->assertAllowed();

        $size = $this->feasibility->bestUsableSize();

        if ($size === null) {
            // Before the request, not after it. ART-002's whole finding.
            throw ArtworkGenerationException::requirementsUnreachable($this->feasibility->explain());
        }

        return ArtworkGeneration::query()->create([
            'release_id' => $release?->getKey(),
            'provider' => $this->provider->name(),
            'status' => ArtworkGenerationStatus::Pending,
            'prompt' => $prompt,
            'quality' => $quality,
            'requested_width' => $size->width,
            'requested_height' => $size->height,
            'requested_by' => $requestedBy,
        ]);
    }

    /**
     * Every reason this installation would decline, in the order that costs
     * least to discover.
     *
     * Shared with the submitter, so the two cannot drift into disagreeing about
     * what "allowed" means — which would show up as a button that accepts a
     * request the worker then refuses.
     *
     * @throws ArtworkGenerationException
     */
    public function assertAllowed(): void
    {
        // The switch first: it is a boolean in configuration and answering it
        // costs nothing.
        if (! (bool) config('artwork.generation_enabled', true)) {
            throw ArtworkGenerationException::paused();
        }

        // OPS-002's global stop. Separate from the switch above and both are
        // honoured: "this installation is doing nothing right now" and "this
        // installation does not generate covers" are different statements.
        if ($this->work->isPaused()) {
            throw ArtworkGenerationException::paused();
        }

        if (! $this->provider->isAvailable()) {
            throw ArtworkGenerationException::noProvider();
        }

        if ($this->breaker->isOpenFor($this->provider->name())) {
            throw ArtworkGenerationException::circuitOpen(
                $this->provider->name(),
                $this->breaker->cooldownMinutes(),
            );
        }

        $exhausted = $this->spend->exhaustedWindow();

        if ($exhausted !== null) {
            throw ArtworkGenerationException::ceilingReached($exhausted);
        }
    }
}
