<?php

declare(strict_types=1);

namespace SaniTube\Catalog\Models;

use Database\Factories\CompositionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use SaniTube\Catalog\Enums\CompositionStatus;
use SaniTube\Contributors\Models\Contributor;
use SaniTube\Foundation\Concerns\HasPublicUuid;

/**
 * The underlying work: the song as written, not as recorded.
 *
 * One composition can have many recordings — a studio take, a live version, a
 * cover by someone else. Publishing and mechanical royalties follow the
 * composition; master royalties follow the {@see Track}. Collapsing the two
 * is what makes publishing income impossible to account for later.
 *
 * @property int $id
 * @property string $uuid
 * @property string $title
 * @property string|null $alternative_title
 * @property string $language_code
 * @property int|null $created_year
 * @property bool $is_public_domain
 * @property CompositionStatus $status
 */
final class Composition extends Model
{
    /** @use HasFactory<CompositionFactory> */
    use HasFactory;

    use HasPublicUuid;
    use SoftDeletes;

    protected $table = 'compositions';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => CompositionStatus::class,
            'is_public_domain' => 'boolean',
        ];
    }

    protected static function newFactory(): CompositionFactory
    {
        return CompositionFactory::new();
    }

    /**
     * @return BelongsToMany<Contributor, $this>
     */
    public function contributors(): BelongsToMany
    {
        return $this->belongsToMany(Contributor::class, 'composition_contributor')
            ->using(CompositionContributor::class)
            ->withPivot(['role', 'share', 'position'])
            ->withTimestamps();
    }

    /**
     * @return HasMany<CompositionContributor, $this>
     */
    public function contributorCredits(): HasMany
    {
        return $this->hasMany(CompositionContributor::class);
    }

    /**
     * @return HasMany<Track, $this>
     */
    public function tracks(): HasMany
    {
        return $this->hasMany(Track::class);
    }

    /**
     * @return MorphMany<ExternalIdentifier, $this>
     */
    public function externalIdentifiers(): MorphMany
    {
        return $this->morphMany(ExternalIdentifier::class, 'identifiable');
    }
}
