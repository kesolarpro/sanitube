<?php

declare(strict_types=1);

namespace SaniTube\Ui\Http\Controllers\Identity;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use SaniTube\Ui\Queries\UserIndexQuery;

/**
 * Who may use this installation.
 *
 * USR-001. The screen the platform shipped three roles without.
 */
final class UserIndexController
{
    public function __invoke(Request $request, UserIndexQuery $users): Response
    {
        return Inertia::render('Users/Index', ['users' => $users->get($request->user())]);
    }
}
