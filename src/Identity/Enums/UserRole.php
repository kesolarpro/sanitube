<?php

declare(strict_types=1);

namespace SaniTube\Identity\Enums;

/**
 * What a person may do in SaniTube.
 *
 * Three roles, and deliberately no more. A permission system with twenty
 * granular flags is one nobody configures correctly, and the platform's actual
 * boundaries are coarse: either you can change the catalogue or you cannot,
 * and either you can hand a release to a distributor or you cannot.
 *
 * **`OWNER` is the root role.** An admin operates the platform; an owner owns
 * it. The difference is not seniority, it is a boundary: only an owner may
 * change who else is an owner, write a provider credential, or touch the
 * settings that decide who can get in. An admin who could promote themselves
 * to owner is an admin who *is* an owner, and the distinction would be
 * decoration.
 *
 * **The last owner cannot be removed, deactivated or demoted.** That is what
 * stops an installation becoming unadministrable — a real failure mode on a
 * platform where the only account is the label's. USR-001 implements it; until
 * then this paragraph described a rule no code enforced.
 *
 * Finer permissions arrive when a real second user type exists. Inventing them
 * now would mean guessing at a division of labour nobody has yet.
 */
enum UserRole: string
{
    case Owner = 'OWNER';
    case Admin = 'ADMIN';
    case Member = 'MEMBER';

    /**
     * Whether this role may change the catalogue — promote a candidate, build
     * a release, select a generation result.
     */
    public function canWriteCatalogue(): bool
    {
        return $this !== self::Member;
    }

    /**
     * Whether this role may hand a release to a distributor.
     *
     * The one irreversible act in the platform, so it is the one that is not
     * simply "can write". A member who can assemble a release still cannot
     * send it to a store.
     */
    public function canDistribute(): bool
    {
        return $this !== self::Member;
    }

    /**
     * Whether this role may administer the installation itself — users,
     * providers, storage configuration.
     */
    public function canAdminister(): bool
    {
        return $this === self::Owner || $this === self::Admin;
    }

    /**
     * Whether this role may change the ownership and security root.
     *
     * USR-001. Owner only, and the list it governs is short and deliberate:
     * making or unmaking another owner, writing a provider credential, and the
     * settings that decide who can reach the installation at all.
     *
     * Everything else an administrator does — queues, backups, providers'
     * *behaviour*, the global stop — stays with `canAdminister()`. The split is
     * between operating the platform and owning it.
     */
    public function canManageOwnership(): bool
    {
        return $this === self::Owner;
    }

    public function isOwner(): bool
    {
        return $this === self::Owner;
    }
}
