<?php

declare(strict_types=1);

namespace SaniTube\Ui\Http\Controllers\Assets;

use Inertia\Inertia;
use Inertia\Response;
use SaniTube\Ui\Queries\UploadScreenQuery;

/**
 * The screen that puts a file into SaniTube.
 *
 * Before UPL-001 there was none — no `<input type="file">` existed anywhere in
 * the application, so neither audio nor artwork could enter from a browser, and
 * the release cover picker was permanently empty on a fresh installation.
 */
final class UploadScreenController
{
    public function __invoke(UploadScreenQuery $query): Response
    {
        return Inertia::render('Assets/Upload', ['upload' => $query->handle()]);
    }
}
