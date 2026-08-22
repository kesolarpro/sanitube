<?php

declare(strict_types=1);

namespace SaniTube\Ui\Queries;

use App\Models\User;
use SaniTube\Identity\Enums\UserRole;

/**
 * Who may use this installation.
 *
 * USR-001. **The address is published here and nowhere else in the platform.**
 * Every other screen names a person and withholds it — an operations screen is
 * a screen people share, and a paused-installation banner has no business
 * handing out an email. This screen is the exception because the address *is*
 * the account: it is what somebody signs in with, and administering accounts
 * without seeing them would be administering by guesswork.
 *
 * Never the password hash, never the remember token, never the session. A
 * hash on a screenshot is a hash somebody can work on offline.
 *
 * `protected` says why a row's controls are disabled, in the reader's
 * language, rather than leaving them mysteriously inert — a control that
 * refuses without explaining is one people press twice.
 */
final readonly class UserIndexQuery
{
    /**
     * @return array<string, mixed>
     */
    public function get(?User $viewer): array
    {
        $rows = [];

        /** @var list<User> $users */
        $users = User::query()
            ->orderByRaw($this->roleOrdering())
            ->orderBy('name')
            ->get()
            ->all();

        $activeOwners = 0;

        foreach ($users as $user) {
            if ($user->role->isOwner() && $user->is_active) {
                $activeOwners++;
            }
        }

        foreach ($users as $user) {
            $rows[] = [
                'uuid' => $user->uuid,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role->value,
                'is_active' => $user->is_active,
                'last_login_at' => $user->last_login_at?->toAtomString(),
                'created_at' => $user->created_at?->toAtomString(),
                // Themselves. The service refuses self-administration, and a
                // screen that offered the control anyway would be a screen
                // whose buttons lie.
                'is_self' => $viewer instanceof User && $viewer->is($user),
                'is_last_owner' => $user->role->isOwner() && $user->is_active && $activeOwners === 1,
            ];
        }

        return [
            'rows' => $rows,
            'roles' => array_map(static fn (UserRole $role): string => $role->value, UserRole::cases()),
            // What *this* reader may do, so the screen shows controls that
            // work rather than controls that 403.
            'may_manage_owners' => $viewer instanceof User && $viewer->role->canManageOwnership(),
            'active_owners' => $activeOwners,
        ];
    }

    /**
     * Owners first, then admins, then members.
     *
     * Not alphabetical by role value, which would put ADMIN above OWNER and
     * bury the accounts that matter most under the ones that matter least.
     */
    private function roleOrdering(): string
    {
        return sprintf(
            "CASE role WHEN '%s' THEN 0 WHEN '%s' THEN 1 ELSE 2 END",
            UserRole::Owner->value,
            UserRole::Admin->value,
        );
    }
}
