<?php

declare(strict_types=1);

namespace SaniTube\Catalog\Observers;

use SaniTube\Catalog\Exceptions\ExternalIdentifierException;
use SaniTube\Catalog\Models\ExternalIdentifier;

/**
 * Enforces identifier immutability.
 *
 * The rule is enforced at the model layer, not only in the assignment service,
 * so that no future code path — an import, an admin screen, a console command
 * — can quietly edit an identifier that is already in circulation.
 */
final class ExternalIdentifierObserver
{
    public function updating(ExternalIdentifier $identifier): void
    {
        $dirty = $identifier->getDirty();

        $frozen = array_values(array_intersect(ExternalIdentifier::IMMUTABLE, array_keys($dirty)));

        if ($frozen !== []) {
            throw ExternalIdentifierException::immutable($frozen);
        }

        if (! array_key_exists('active_marker', $dirty)) {
            return;
        }

        // The only legal transition is active → revoked. Anything else, in
        // particular NULL → 1, would resurrect an identifier that the outside
        // world has already been told is gone.
        $original = $identifier->getOriginal('active_marker');

        if ($original === null || $dirty['active_marker'] !== null) {
            throw ExternalIdentifierException::reactivationForbidden();
        }
    }

    public function deleting(ExternalIdentifier $identifier): void
    {
        throw ExternalIdentifierException::deletionForbidden();
    }
}
