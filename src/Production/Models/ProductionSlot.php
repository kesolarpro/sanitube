<?php

declare(strict_types=1);

namespace SaniTube\Production\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use SaniTube\Foundation\Concerns\HasPublicUuid;
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
