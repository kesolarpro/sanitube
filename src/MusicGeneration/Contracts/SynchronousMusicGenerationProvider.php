<?php

declare(strict_types=1);

namespace SaniTube\MusicGeneration\Contracts;

use SaniTube\MusicGeneration\GenerationExecution;
use SaniTube\MusicGeneration\GenerationRequest;
use SaniTube\MusicGeneration\Jobs\SubmitMusicGenerationJob;

/**
 * A provider that answers with the audio.
 *
 * GEN-006's research found exactly one music provider whose contract could be
 * verified from this environment, and it turned out to work this way: ACE-Step's
 * own reference server exposes `POST /generate`, which blocks and returns. No
 * job identifier, no polling, no webhook, no cancellation — because there is
 * nothing to poll for and nothing left running to cancel.
 *
 * **This interface exists so that such a provider never has to lie.** Before
 * GEN-007 the only contract was {@see MusicGenerationProvider}, and an adapter
 * for a synchronous supplier would have had to mint an identifier for a job
 * that had already finished, store it, and then answer polls about it from
 * memory. Every part of that is fiction, and fiction in an adapter is how a
 * platform acquires behaviour nobody designed.
 *
 * The trade is real and worth naming: a synchronous call holds a worker for as
 * long as the generation takes, which for music is minutes. That is why nothing
 * calls one from a web request — {@see SubmitMusicGenerationJob}
 * is a queued job, and it was already queued for the asynchronous case.
 */
interface SynchronousMusicGenerationProvider extends GenerationProvider
{
    /**
     * Produce the audio, and return when it exists.
     *
     * Returns a **completed** execution carrying its results, or throws. It
     * never returns something in flight: a provider that might not be finished
     * when it answers is asynchronous, whatever its documentation calls it, and
     * belongs behind {@see MusicGenerationProvider} instead.
     *
     * Implementations must bound their own wait. A supplier that never answers
     * would otherwise hold a queue worker until the process is killed, and a
     * killed worker is exactly the case PROD-005 has to clean up afterwards.
     */
    public function generate(GenerationRequest $request): GenerationExecution;
}
