<?php

declare(strict_types=1);

namespace SaniTube\Ui\Http\Controllers\Studio;

use Inertia\Inertia;
use Inertia\Response;
use SaniTube\MusicGeneration\Models\MusicGeneration;
use SaniTube\Ui\Queries\GenerationDetailQuery;

/**
 * One generation. Bound by UUID; carries no provider job id and no audio URL.
 */
final class GenerationDetailController
{
    public function __invoke(MusicGeneration $generation, GenerationDetailQuery $detail): Response
    {
        return Inertia::render('Studio/Generations/Show', [
            'generation' => $detail->forGeneration($generation),
        ]);
    }
}
