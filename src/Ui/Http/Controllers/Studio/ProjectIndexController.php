<?php

declare(strict_types=1);

namespace SaniTube\Ui\Http\Controllers\Studio;

use Inertia\Inertia;
use Inertia\Response;
use SaniTube\Ui\Http\Requests\GenerationProjectIndexRequest;
use SaniTube\Ui\Queries\GenerationProjectIndexQuery;

final class ProjectIndexController
{
    public function __invoke(GenerationProjectIndexRequest $request, GenerationProjectIndexQuery $projects): Response
    {
        return Inertia::render('Studio/Projects/Index', [
            'page' => $projects->paginate($request->cursor()),
        ]);
    }
}
