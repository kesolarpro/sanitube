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
 * `OWNER` and `ADMIN` are separated for one reason: an owner cannot be locked
 * out. Deactivating the last owner is refused, which is what stops an
 * installation becoming unadministrable — a real failure mode on a single-user
 * platform where the only account is the label's.
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

    public function isOwner(): bool
    {
        return $this === self::Owner;
    }
}
