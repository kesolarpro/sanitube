<?php

declare(strict_types=1);

namespace SaniTube\Ui\Http\Controllers\Ingestion;

use Inertia\Inertia;
use Inertia\Response;
use SaniTube\Ui\Queries\ImportScreenQuery;

/**
 * The catalogue import tool.
 *
 * Separate from the single-file upload screen on purpose, because the two
 * produce different things. Uploading makes one asset, immediately, which is
 * what somebody wants for a cover they are about to attach to a release.
 * Importing deposits many files and starts a *batch*, which is what somebody
 * wants for nine hundred masters — and a batch is the only shape in which nine
 * hundred files can be brought in without an HTTP request having to survive it.
 */
final class ImportScreenController
{
    public function __invoke(ImportScreenQuery $query): Response
    {
        // Not `import`. A prop called that is unreachable from a Vue template:
        // the expression parser reads a bare `import` as the meta-property and
        // refuses the file. vue-tsc accepts it and the build does not, which
        // is the one place the two disagree.
        return Inertia::render('Ingestion/Import', ['capability' => $query->payload()]);
    }
}
