<?php

declare(strict_types=1);

namespace SaniTube\MusicGeneration\Enums;

/**
 * How far a generation project has got.
 *
 * A project is a *campaign*: "forty ambient instrumentals for the autumn
 * catalogue". It groups generations so that a defaulted style, language and
 * target count are stated once rather than retyped forty times.
 */
enum GenerationProjectStatus: string
{
    case Draft = 'DRAFT';
    case Active = 'ACTIVE';
    case Completed = 'COMPLETED';
    case Archived = 'ARCHIVED';

    /**
     * Whether new generations may still be attached.
     *
     * A completed project is a finished campaign, and adding to it quietly
     * would make "how many did that campaign produce" unanswerable.
     */
    public function acceptsGenerations(): bool
    {
        return $this === self::Draft || $this === self::Active;
    }
}
