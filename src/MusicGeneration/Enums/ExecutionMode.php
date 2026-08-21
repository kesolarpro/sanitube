<?php

declare(strict_types=1);

namespace SaniTube\MusicGeneration\Enums;

use SaniTube\MusicGeneration\Contracts\MusicGenerationProvider;
use SaniTube\MusicGeneration\Contracts\SynchronousMusicGenerationProvider;

/**
 * How a supplier hands back what it made.
 *
 * Recorded on the generation itself, not merely used to pick a code path. "Why
 * does this row have no provider job identifier" is a question somebody asks at
 * three in the morning, and *"because the supplier answered synchronously"* is
 * an answer the row should be able to give on its own.
 */
enum ExecutionMode: string
{
    /**
     * Ask, and the answer is the audio.
     *
     * {@see SynchronousMusicGenerationProvider}. No provider job identifier
     * exists and none is invented; `provider_job_id` stays null for the life of
     * the row, and nothing ever polls it.
     */
    case Synchronous = 'SYNCHRONOUS';

    /**
     * Ask, get a reference, come back for the result.
     *
     * {@see MusicGenerationProvider}, and what every adapter written before
     * GEN-007 assumed was the only possibility.
     */
    case AsynchronousPolling = 'ASYNCHRONOUS_POLLING';

    /**
     * Ask, and be told when it is done.
     *
     * Declared and not yet reachable: SaniTube has no generation webhook
     * endpoint, and the capability is what a provider claims rather than what
     * this installation can receive. Named now so that the day one is built,
     * the mode it produces already exists rather than being invented inside
     * whichever adapter needs it first.
     */
    case AsynchronousWebhook = 'ASYNCHRONOUS_WEBHOOK';

    /**
     * Told when it is done, and pollable in case the telling goes missing.
     *
     * Not a hedge but the honest description of most serious hosted suppliers:
     * a webhook that may be lost, behind a status endpoint that is the source
     * of truth. A platform that trusted only the webhook would strand every
     * generation whose callback was dropped.
     */
    case AsyncHybrid = 'ASYNC_HYBRID';

    /**
     * Whether a generation executed this way is ever worth polling.
     *
     * The reason this enum is stored rather than derived at read time: a
     * synchronous generation must never be handed to the poller, and by the
     * time anything asks, the provider that produced it may have been
     * reconfigured or removed from this installation entirely.
     */
    public function isPollable(): bool
    {
        return $this !== self::Synchronous;
    }

    /**
     * Whether a provider job identifier is expected to come back.
     *
     * A submission that returns none is a fault for every mode but one, and
     * before GEN-007 it was a fault for all of them — which is precisely what
     * would have forced a synchronous adapter to invent one.
     */
    public function expectsProviderJobId(): bool
    {
        return $this !== self::Synchronous;
    }

    /**
     * The capability that corresponds to this mode.
     *
     * Modes and capabilities are deliberately separate vocabularies — one
     * describes a single generation, the other describes a supplier — and this
     * is the single place they are mapped, so they cannot drift.
     */
    public function capability(): GenerationCapability
    {
        return match ($this) {
            self::Synchronous => GenerationCapability::Synchronous,
            self::AsynchronousPolling => GenerationCapability::AsyncPolling,
            self::AsynchronousWebhook => GenerationCapability::Webhook,
            self::AsyncHybrid => GenerationCapability::AsyncPolling,
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $mode): string => $mode->value, self::cases());
    }
}
