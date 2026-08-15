<?php

declare(strict_types=1);

namespace SaniTube\Catalog\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The record that an identifier was withdrawn, and why.
 *
 * Kept in its own table rather than as nullable columns on the identifier, so
 * that the identifier row stays a statement of fact and the revocation stays a
 * separate, once-only event. The unique constraint on `external_identifier_id`
 * is what makes "revoked twice" impossible rather than merely discouraged.
 *
 * @property int $id
 * @property int $external_identifier_id
 * @property string $reason
 * @property Carbon $revoked_at
 */
final class ExternalIdentifierRevocation extends Model
{
    protected $table = 'external_identifier_revocations';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'revoked_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<ExternalIdentifier, $this>
     */
    public function externalIdentifier(): BelongsTo
    {
        return $this->belongsTo(ExternalIdentifier::class);
    }
}
