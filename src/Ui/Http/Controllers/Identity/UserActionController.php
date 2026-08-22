<?php

declare(strict_types=1);

namespace SaniTube\Ui\Http\Controllers\Identity;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use SaniTube\Identity\Exceptions\UserAdministrationException;
use SaniTube\Identity\Services\ManageUsers;
use SaniTube\Ui\Http\Requests\Identity\CreateUserRequest;
use SaniTube\Ui\Http\Requests\Identity\UpdateUserRequest;

/**
 * Creating an account, changing a role, and letting somebody back in.
 *
 * USR-001. Every refusal comes back as a **code**, rendered by the interface
 * in the reader's language — the same shape the rest of the platform uses.
 * "Only an owner may change who else is an owner" composed here would be one
 * sentence in one language shown to everybody.
 *
 * The password never travels back. It arrives once, is hashed by
 * {@see ManageUsers}, and no response, redirect or audit line repeats it.
 */
final class UserActionController
{
    public function store(CreateUserRequest $request, ManageUsers $users): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        try {
            $users->create(
                $actor,
                $request->string('name')->toString(),
                $request->string('email')->toString(),
                $request->role(),
                $request->string('password')->toString(),
            );
        } catch (UserAdministrationException $refusal) {
            return back()->withErrors(['user' => $refusal->reason]);
        }

        return back();
    }

    public function update(UpdateUserRequest $request, User $user, ManageUsers $users): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        try {
            $role = $request->newRole();

            if ($role !== null) {
                $users->changeRole($actor, $user, $role);
            }

            $activation = $request->activation();

            if ($activation !== null) {
                $users->setActive($actor, $user, $activation);
            }
        } catch (UserAdministrationException $refusal) {
            return back()->withErrors(['user' => $refusal->reason]);
        }

        return back();
    }
}
