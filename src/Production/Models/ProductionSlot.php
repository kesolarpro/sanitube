<?php

declare(strict_types=1);

namespace SaniTube\Production\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use SaniTube\Foundation\Concerns\HasPublicUuid;
use SaniTube\MusicGeneration\Models\MusicGeneration;
use SaniTube\Production\Enums\ProductionSlotStatus;

/**
 * One occasion on which a plan intends something to happen.
 *
 * **A standing invitation, not the work.** The scheduler opens these and does
 * nothing else. Whatever produces something claims one, acts, and settles it.
 *
 * @property int $id
 * @property string $uuid
 * @property int $production_plan_id
 * @property Carbon $scheduled_for
 * @property ProductionSlotStatus $status
 * @property string $intent
 * @property int|null $music_generation_id
 * @property list<string>|null $attempted_providers
 * @property string|null $claimed_by
 * @property Carbon|null $claimed_at
 * @property string|null $outcome_reason
 * @property Carbon|null $settled_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class ProductionSlot extends Model
{
    use HasPublicUuid;

    /**
     * What a slot invites. SaniTube's word, never a vendor's or a job class
     * name: an intent named after the thing that happens to implement it today
     * is an intent that has to be renamed when that changes.
     */
    public const INTENT_PRODUCE_TRACK = 'PRODUCE_TRACK';

    protected $table = 'production_slots';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ProductionSlotStatus::class,
            'scheduled_for' => 'date',
            'attempted_providers' => 'array',
            'claimed_at' => 'datetime',
            'settled_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<ProductionPlan, $this>
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(ProductionPlan::class, 'production_plan_id');
    }

    /**
     * @return BelongsTo<MusicGeneration, $this>
     */
    public function generation(): BelongsTo
    {
        return $this->belongsTo(MusicGeneration::class, 'music_generation_id');
    }

    /**
     * Suppliers this occasion has already been offered to.
     *
     * A list rather than a count, because the question after a bad night is
     * *which* ones were tried. Never null to a caller: an occasion that has
     * tried nobody has tried an empty list, and making that distinction
     * visible would make every caller handle it.
     *
     * @return list<string>
     */
    public function attempted(): array
    {
        // Read through `mixed` rather than trusting the annotation: the column
        // is an array cast, so a hand-edited row can hold anything, and the
        // same defensiveness EditorialProfile::terms() applies to its lists
        // applies here.
        $names = $this->getAttribute('attempted_providers');

        if (! is_array($names)) {
            return [];
        }

        $clean = [];

        foreach ($names as $name) {
            if (is_string($name) && trim($name) !== '') {
                $clean[] = trim($name);
            }
        }

        return $clean;
    }

    /**
     * Whether this slot's day has arrived.
     *
     * A slot opened for next Tuesday is not work waiting; it is work
     * scheduled. A worker that ignored this would do a month of production in
     * an afternoon.
     */
    public function isDue(?Carbon $now = null): bool
    {
        return ! $this->scheduled_for->isAfter(($now ?? Carbon::now())->startOfDay());
    }
}
