<?php

declare(strict_types=1);

namespace SaniTube\Identity\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use SaniTube\Audit\Enums\AuditAction;
use SaniTube\Audit\Services\RecordAuditEvent;
use SaniTube\Identity\Enums\UserRole;
use SaniTube\Identity\Exceptions\UserAdministrationException;

/**
 * Who may use this installation, and as what.
 *
 * USR-001. Until this existed the only way to create an account was
 * `sanitube:user:create` over SSH, and there was no way at all to change a
 * role or deactivate somebody — so a platform shipping three roles had no
 * means of assigning any of them without a terminal.
 *
 * **There is no delete, and that is not an omission.** `audit_events.actor_id`
 * is `restrictOnDelete`, so the database itself refuses to remove anybody who
 * has ever done anything — and it is right to: deleting a user would either
 * fail, or destroy the record of who did what. Deactivation achieves the
 * operational goal, keeps the history intelligible, and is reversible when
 * somebody comes back.
 *
 * **The last owner is protected by a lock, not by a check.** The count and the
 * write happen inside one transaction with the owner rows locked, because the
 * interesting failure is two administrators demoting the last two owners at
 * the same instant: each sees one other owner, each proceeds, and the
 * installation ends with none. A `SELECT` followed by an `UPDATE` cannot
 * prevent that; `lockForUpdate` can.
 */
final readonly class ManageUsers
{
    public function __construct(private RecordAuditEvent $audit) {}

    /**
     * @throws UserAdministrationException
     */
    public function create(User $actor, string $name, string $email, UserRole $role, string $password): User
    {
        $this->refuseUnlessOwnerMayTouchThisRole($actor, $role);

        if (User::query()->where('email', $email)->exists()) {
            throw UserAdministrationException::emailTaken();
        }

        // `role` and `is_active` are deliberately not fillable on the model —
        // a request body that can set `role` is a privilege escalation waiting
        // for the first endpoint that forwards user input into `create()`. So
        // they are set explicitly here, which is what makes them greppable.
        $user = new User;

        $user->forceFill([
            'name' => $name,
            'email' => $email,
            'role' => $role,
            'is_active' => true,
            // Hashed here rather than by a model cast, so that a future change
            // to the cast cannot silently store one in the clear.
            'password' => Hash::make($password),
        ])->save();

        $this->audit->record(
            AuditAction::UserCreated,
            subjectUuid: $user->uuid,
            context: ['role' => $role->value],
        );

        return $user;
    }

    /**
     * @throws UserAdministrationException
     */
    public function changeRole(User $actor, User $subject, UserRole $role): User
    {
        if ($actor->is($subject)) {
            throw UserAdministrationException::notYourself();
        }

        // Both directions. Promoting *to* owner and demoting *from* owner are
        // the same act seen from two sides, and both belong to an owner.
        $this->refuseUnlessOwnerMayTouchThisRole($actor, $role);
        $this->refuseUnlessOwnerMayTouchThisRole($actor, $subject->role);

        if ($subject->role === $role) {
            return $subject;
        }

        return DB::transaction(function () use ($subject, $role): User {
            if ($subject->role->isOwner() && ! $role->isOwner()) {
                $this->refuseIfLastOwner($subject);
            }

            $previous = $subject->role;
            $subject->forceFill(['role' => $role])->save();

            $this->audit->record(
                AuditAction::UserRoleChanged,
                subjectUuid: $subject->uuid,
                context: ['from' => $previous->value, 'to' => $role->value],
            );

            return $subject->refresh();
        });
    }

    /**
     * @throws UserAdministrationException
     */
    public function setActive(User $actor, User $subject, bool $active): User
    {
        if ($actor->is($subject)) {
            throw UserAdministrationException::notYourself();
        }

        $this->refuseUnlessOwnerMayTouchThisRole($actor, $subject->role);

        if ($subject->is_active === $active) {
            return $subject;
        }

        return DB::transaction(function () use ($subject, $active): User {
            if (! $active && $subject->role->isOwner()) {
                $this->refuseIfLastOwner($subject);
            }

            $subject->forceFill(['is_active' => $active])->save();

            $this->audit->record(
                $active ? AuditAction::UserReactivated : AuditAction::UserDeactivated,
                subjectUuid: $subject->uuid,
            );

            return $subject->refresh();
        });
    }

    /**
     * Owners are owners' business, in both directions.
     *
     * @throws UserAdministrationException
     */
    private function refuseUnlessOwnerMayTouchThisRole(User $actor, UserRole $role): void
    {
        if ($role->isOwner() && ! $actor->role->canManageOwnership()) {
            throw UserAdministrationException::ownersAreOwnersBusiness();
        }
    }

    /**
     * @throws UserAdministrationException
     */
    private function refuseIfLastOwner(User $subject): void
    {
        // Locked, not merely counted. Two simultaneous demotions each seeing
        // the other owner is exactly how an installation ends up with none.
        $others = User::query()
            ->where('role', UserRole::Owner->value)
            ->where('is_active', true)
            ->whereKeyNot($subject->getKey())
            ->lockForUpdate()
            ->count();

        if ($others === 0) {
            throw UserAdministrationException::lastOwner();
        }
    }
}
