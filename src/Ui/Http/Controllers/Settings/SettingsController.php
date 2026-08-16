<?php

declare(strict_types=1);

namespace SaniTube\Ui\Http\Controllers\Settings;

use Inertia\Inertia;
use Inertia\Response;
use SaniTube\Ui\Queries\SettingsQuery;

/**
 * What this installation is configured with.
 *
 * Read-only, and administrator-only. Read-only because SET-001 is the shell:
 * knowing what is set is a different problem from changing it, and changing it
 * means writing a `.env` file from an HTTP request — a security surface that
 * gets its own ticket and its own review rather than arriving as a side effect
 * of a screen that reports.
 *
 * Administrator-only even though it publishes no secret. The list of which
 * credentials are present is itself a map of where the soft spots are.
 */
final class SettingsController
{
    public function __invoke(SettingsQuery $settings): Response
    {
        return Inertia::render('Settings/Index', [
            'settings' => $settings->overview(),
        ]);
    }
}
