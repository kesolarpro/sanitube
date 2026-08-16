<?php

declare(strict_types=1);

namespace SaniTube\Ui\Http\Controllers\Studio;

use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use SaniTube\MusicGeneration\Models\MusicGeneration;
use SaniTube\Ui\Queries\GenerationDetailQuery;

/**
 * One generation. Bound by UUID; carries no provider job id and no audio URL.
 */
final class GenerationDetailController
{
    public function __invoke(Request $request, MusicGeneration $generation, GenerationDetailQuery $detail): Response
    {
        /** @var User $viewer */
        $viewer = $request->user();

        return Inertia::render('Studio/Generations/Show', [
            'generation' => $detail->forGeneration($generation, $viewer),
        ]);
    }
}
