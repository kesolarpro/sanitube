<?php

declare(strict_types=1);

namespace SaniTube\Ui\Http\Controllers;

use Inertia\Inertia;
use Inertia\Response;

/**
 * The one screen UI-001 ships.
 *
 * A showcase, not a business screen. It exists to prove the design system
 * holds together — every primitive rendered in both themes, at every
 * breakpoint — before twenty real screens are built on top of it, which is
 * precisely the ordering this ticket exists to enforce.
 *
 * It carries **no domain data**. Nothing here queries the catalogue, and the
 * sample values are visibly illustrative rather than plausible figures: a
 * showcase that displayed "1,284 tracks" would be exactly the invented metric
 * the platform's rules forbid.
 */
final class DesignSystemController
{
    public function __invoke(): Response
    {
        return Inertia::render('DesignSystem/Index');
    }
}
