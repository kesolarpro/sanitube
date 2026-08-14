<?php

declare(strict_types=1);

use App\Providers\AppServiceProvider;
use SaniTube\Foundation\Modules\ModuleLoaderServiceProvider;

return [
    AppServiceProvider::class,

    // Registers every module declared in config/sanitube.php, along with its
    // own service provider, routes, migrations, translations and views.
    ModuleLoaderServiceProvider::class,
];
