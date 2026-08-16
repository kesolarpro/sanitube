<?php

declare(strict_types=1);

namespace SaniTube\Ui\Http\Controllers\System;

use Inertia\Inertia;
use Inertia\Response;
use SaniTube\Ui\Http\Requests\System\AuditIndexRequest;
use SaniTube\Ui\Queries\AuditIndexQuery;

/**
 * What has been done here.
 *
 * A GET that reads stored rows and does nothing else — no probe, no write, and
 * deliberately no audit line of its own. Reading the log is not an event; a log
 * that records its own readers grows by being looked at.
 */
final class AuditController
{
    public function __invoke(AuditIndexRequest $request, AuditIndexQuery $events): Response
    {
        $filters = $request->filters();

        return Inertia::render('System/Audit', [
            'page' => $events->paginate($filters, $request->cursor()),
            'filters' => $this->forForm($filters),
            'options' => $events->options(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, string|null>
     */
    private function forForm(array $filters): array
    {
        $form = [];

        foreach ($filters as $key => $value) {
            $form[$key] = is_string($value) ? $value : null;
        }

        return $form;
    }
}
