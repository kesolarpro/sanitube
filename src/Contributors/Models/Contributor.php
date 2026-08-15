<?php

declare(strict_types=1);

namespace SaniTube\Contributors\Models;

use Database\Factories\ContributorFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use SaniTube\Catalog\Models\Composition;
use SaniTube\Catalog\Models\ExternalIdentifier;
use SaniTube\Catalog\Models\Track;
use SaniTube\Contributors\Enums\ContributorType;
use SaniTube\Foundation\Concerns\HasPublicUuid;

/**
 * The party that actually created or administers something.
 *
 * Writers, producers, engineers and publishers are Contributors; this is where
 * rights and publishing will attach in RGT-001 and PUB-001. It is deliberately
 * distinct from an Artist credit, because a payment has to reach a legal
 * person and a stage name is not one.
 *
 * @property int $id
 * @property string $uuid
 * @property string $legal_name
 * @property string|null $display_name
 * @property ContributorType $type
 * @property string|null $country
 * @property string|null $notes
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class Contributor extends Model
{
    /** @use HasFactory<ContributorFactory> */
    use HasFactory;

    use HasPublicUuid;
    use SoftDeletes;

    protected $table = 'contributors';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => ContributorType::class,
        ];
    }

    protected static function newFactory(): ContributorFactory
    {
        return ContributorFactory::new();
    }

    /**
     * @return BelongsToMany<Composition, $this>
     */
    public function compositions(): BelongsToMany
    {
        return $this->belongsToMany(Composition::class, 'composition_contributor')
            ->withPivot(['role', 'share', 'position'])
            ->withTimestamps();
    }

    /**
     * @return BelongsToMany<Track, $this>
     */
    public function tracks(): BelongsToMany
    {
        return $this->belongsToMany(Track::class, 'track_contributor')
            ->withPivot(['role', 'position'])
            ->withTimestamps();
    }

    /**
     * IPI and ISNI live here rather than as columns, so they inherit
     * uniqueness, provenance and revocation like every other identifier.
     *
     * @return MorphMany<ExternalIdentifier, $this>
     */
    public function externalIdentifiers(): MorphMany
    {
        return $this->morphMany(ExternalIdentifier::class, 'identifiable');
    }
}
