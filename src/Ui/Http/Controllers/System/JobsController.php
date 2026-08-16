<?php

declare(strict_types=1);

namespace SaniTube\Ui\Http\Controllers\System;

use Inertia\Inertia;
use Inertia\Response;
use SaniTube\Ui\Queries\QueueQuery;

/**
 * Work waiting, and work that broke.
 *
 * No payload and no stack trace reaches the browser — see {@see QueueQuery}.
 */
final class JobsController
{
    public function __invoke(QueueQuery $queue): Response
    {
        return Inertia::render('System/Jobs', [
            'summary' => $queue->summary(),
            'pending' => $queue->pending(),
            'failed' => $queue->failed(),
        ]);
    }
}
