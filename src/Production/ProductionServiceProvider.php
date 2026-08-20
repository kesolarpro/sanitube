<?php

declare(strict_types=1);

namespace SaniTube\Production;

use Illuminate\Support\ServiceProvider;

/**
 * Wires the production module.
 *
 * Nothing to wire yet. A plan is a standing intention and a slot is what turns
 * one into work; until slots exist there is no listener and no schedule for
 * this provider to register, and inventing one now would be a scheduler with
 * nothing to schedule.
 */
final class ProductionServiceProvider extends ServiceProvider
{
    public function register(): void {}
}
