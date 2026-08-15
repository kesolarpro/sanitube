<?php

declare(strict_types=1);

namespace SaniTube\MusicGeneration\Enums;

use SaniTube\MusicGeneration\GenerationStatus;

/**
 * Where a generation is, in SaniTube's vocabulary.
 *
 * Deliberately not the same enum as {@see GenerationStatus},
 * which is what a *provider* reported. Two vocabularies because they answer
 * two questions: the provider says what its job is doing, and this says what
 * the platform believes about the row. DRAFT has no provider counterpart at
 * all — it is a generation somebody composed and has not submitted — and a
 * single enum would have had to invent a provider state for it.
 */
enum MusicGenerationStatus: string
{
    case Draft = 'DRAFT';
    case Queued = 'QUEUED';
    case Processing = 'PROCESSING';
    case Completed = 'COMPLETED';
    case Failed = 'FAILED';
    case Cancelled = 'CANCELLED';

    public function isTerminal(): bool
    {
        return match ($this) {
            self::Completed, self::Failed, self::Cancelled => true,
            self::Draft, self::Queued, self::Processing => false,
        };
    }

    /**
     * Whether the platform should still be asking the provider about this.
     *
     * DRAFT is excluded because nothing was ever submitted: polling it would
     * ask about a job identifier that does not exist.
     */
    public function isPollable(): bool
    {
        return $this === self::Queued || $this === self::Processing;
    }

    public function isCancellable(): bool
    {
        return ! $this->isTerminal();
    }
}
