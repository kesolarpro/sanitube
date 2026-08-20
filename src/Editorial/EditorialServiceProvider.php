<?php

declare(strict_types=1);

namespace SaniTube\Editorial;

use Illuminate\Support\ServiceProvider;

/**
 * Wires the editorial module.
 *
 * Nothing to wire yet, and the provider exists so the module is registered
 * rather than discovered: a module that only appears once it has a listener is
 * a module whose migrations run on a schedule nobody chose.
 */
final class EditorialServiceProvider extends ServiceProvider
{
    public function register(): void {}
}
