<?php

declare(strict_types=1);

namespace SaniTube\Deduplication\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use SaniTube\Assets\Models\Asset;
use SaniTube\Deduplication\Enums\DuplicateBasis;
use SaniTube\Deduplication\Enums\DuplicateDecision;
use SaniTube\Deduplication\Enums\DuplicateLevel;
use SaniTube\Foundation\Concerns\HasPublicUuid;

/**
 * One finding about two assets, and what was done about it.
 *
 * A **finding**, not a fact about the catalogue. Nothing downstream may treat
 * a row here as meaning an asset is redundant; it means the platform noticed
 * something and, if `decision` says so, that a person agreed. The evidence is
 * kept beside the verdict — the measurement, the alignment it was taken at,
 * and how much audio it covered — so that a reviewer can see why they are
 * being asked, and so that changing a threshold later can re-decide old
 * findings without re-reading a single master.
 *
 * @property int $id
 * @property string $uuid
 * @property int $asset_id
 * @property int $related_asset_id
 * @property DuplicateLevel $level
 * @property DuplicateBasis $basis
 * @property DuplicateDecision $decision
 * @property int|null $similarity_basis_points
 * @property int|null $offset_frames
 * @property int|null $overlap_frames
 * @property string|null $algorithm
 * @property int|null $decided_by_user_id
 * @property Carbon|null $decided_at
 * @property Carbon $detected_at
 */
final class DuplicateRelation extends Model
{
    use HasPublicUuid;

    protected $table = 'duplicate_relations';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'level' => DuplicateLevel::class,
            'basis' => DuplicateBasis::class,
            'decision' => DuplicateDecision::class,
            'decided_at' => 'datetime',
            'detected_at' => 'datetime',
        ];
    }

    /**
     * The measurement as a fraction, or null when there was none.
     *
     * Null for a checksum finding rather than 1.0: identical bytes were never
     * measured for acoustic similarity, and reporting a perfect score would
     * put a number in the interface that no comparison produced.
     */
    public function similarity(): ?float
    {
        return $this->similarity_basis_points === null
            ? null
            : $this->similarity_basis_points / 10000;
    }

    /**
     * @return BelongsTo<Asset, $this>
     */
    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    /**
     * @return BelongsTo<Asset, $this>
     */
    public function relatedAsset(): BelongsTo
    {
        return $this->belongsTo(Asset::class, 'related_asset_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by_user_id');
    }
}
