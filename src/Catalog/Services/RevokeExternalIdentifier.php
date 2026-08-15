<?php

declare(strict_types=1);

namespace SaniTube\Catalog\Services;

use Illuminate\Support\Facades\DB;
use SaniTube\Catalog\Events\ExternalIdentifierRevoked;
use SaniTube\Catalog\Exceptions\ExternalIdentifierException;
use SaniTube\Catalog\Models\ExternalIdentifier;
use SaniTube\Catalog\Models\ExternalIdentifierRevocation;

/**
 * Withdraws an identifier without erasing it.
 *
 * Revocation is the only mutation an identifier ever undergoes, and it is
 * one-way: `active_marker` goes 1 → NULL, a revocation record is written, and
 * the identifier row itself is left exactly as it was. What was true stays
 * readable, and the entity is free to receive a replacement.
 *
 * Both steps happen in one transaction. A revocation record without the
 * marker change would leave the identifier still blocking its slot; the marker
 * change without the record would lose the reason.
 */
final class RevokeExternalIdentifier
{
    public function __invoke(
        ExternalIdentifier $identifier,
        string $reason,
        ?\DateTimeInterface $revokedAt = null,
    ): ExternalIdentifierRevocation {
        return DB::transaction(function () use ($identifier, $reason, $revokedAt): ExternalIdentifierRevocation {
            // Re-read under the transaction: two concurrent revocations must
            // not both see an active identifier and both proceed.
            $fresh = ExternalIdentifier::query()
                ->whereKey($identifier->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($fresh->isRevoked()) {
                throw ExternalIdentifierException::alreadyRevoked($fresh->uuid);
            }

            $revocation = new ExternalIdentifierRevocation([
                'external_identifier_id' => $fresh->getKey(),
                'reason' => $reason,
                'revoked_at' => $revokedAt ?? now(),
            ]);

            $revocation->save();

            $fresh->active_marker = null;
            $fresh->save();

            $identifier->setRawAttributes($fresh->getAttributes(), sync: true);

            event(new ExternalIdentifierRevoked($fresh, $revocation));

            return $revocation;
        });
    }
}
