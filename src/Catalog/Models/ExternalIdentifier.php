<?php

declare(strict_types=1);

namespace SaniTube\Catalog\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use SaniTube\Catalog\Enums\ExternalIdentifierSource;
use SaniTube\Catalog\Enums\ExternalIdentifierType;
use SaniTube\Catalog\Services\RevokeExternalIdentifier;
use SaniTube\Foundation\Concerns\HasPublicUuid;

/**
 * An identifier issued by someone outside SaniTube.
 *
 * These rows are the catalogue's memory of what the outside world calls
 * things, and they are treated as evidence rather than as editable data. Once
 * written, the identity of the record — what it points at, its type, its
 * namespace, its value, where it came from — never changes. The only permitted
 * mutation is `active_marker` going 1 → NULL through
 * {@see RevokeExternalIdentifier}, and physical
 * deletion is refused outright.
 *
 * The reason is concrete: an ISRC that was distributed is now printed in
 * royalty reports and store metadata that SaniTube does not control. Editing
 * the row would not change any of that; it would only destroy the record of
 * what was true.
 *
 * `active_marker` is 1 while active and NULL once revoked. Combined with the
 * unique index on (identifiable, type, namespace, active_marker), this makes
 * "one active identifier of a given type per entity" a guarantee the database
 * enforces — any number of revoked rows may coexist, only one active row may.
 *
 * @property int $id
 * @property string $uuid
 * @property string $identifiable_type
 * @property int $identifiable_id
 * @property ExternalIdentifierType $type
 * @property string $namespace
 * @property string $value
 * @property bool $is_authoritative
 * @property ExternalIdentifierSource $source
 * @property Carbon $assigned_at
 * @property int|null $active_marker
 */
final class ExternalIdentifier extends Model
{
    use HasPublicUuid;

    protected $table = 'external_identifiers';

    protected $guarded = [];

    /** Marker value meaning "this identifier is in force". */
    public const ACTIVE = 1;

    /**
     * Fields that may never change once the row exists.
     */
    public const IMMUTABLE = [
        'identifiable_type',
        'identifiable_id',
        'type',
        'namespace',
        'value',
        'is_authoritative',
        'source',
        'assigned_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => ExternalIdentifierType::class,
            'source' => ExternalIdentifierSource::class,
            'is_authoritative' => 'boolean',
            'assigned_at' => 'datetime',
            'active_marker' => 'integer',
        ];
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function identifiable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return HasOne<ExternalIdentifierRevocation, $this>
     */
    public function revocation(): HasOne
    {
        return $this->hasOne(ExternalIdentifierRevocation::class);
    }

    public function isActive(): bool
    {
        return $this->active_marker === self::ACTIVE;
    }

    public function isRevoked(): bool
    {
        return ! $this->isActive();
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('active_marker', self::ACTIVE);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeRevoked(Builder $query): Builder
    {
        return $query->whereNull('active_marker');
    }
}
