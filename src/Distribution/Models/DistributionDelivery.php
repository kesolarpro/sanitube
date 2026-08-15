<?php

declare(strict_types=1);

namespace SaniTube\Distribution\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use SaniTube\Distribution\Enums\DistributionDeliveryStatus;
use SaniTube\Foundation\Concerns\HasPublicUuid;
use SaniTube\Releases\Models\Release;

/**
 * One release, handed to one distributor.
 *
 * @property int $id
 * @property string $uuid
 * @property int $release_id
 * @property string $provider
 * @property DistributionDeliveryStatus $status
 * @property string|null $external_release_id
 * @property string $idempotency_key
 * @property string|null $failure_reason
 * @property Carbon|null $submitted_at
 * @property Carbon|null $delivered_at
 * @property Carbon|null $live_at
 * @property Carbon|null $last_synced_at
 * @property int|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class DistributionDelivery extends Model
{
    use HasPublicUuid;

    protected $table = 'distribution_deliveries';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => DistributionDeliveryStatus::class,
            'submitted_at' => 'datetime',
            'delivered_at' => 'datetime',
            'live_at' => 'datetime',
            'last_synced_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Release, $this>
     */
    public function release(): BelongsTo
    {
        return $this->belongsTo(Release::class);
    }

    /**
     * @return HasMany<DistributionAttempt, $this>
     */
    public function attempts(): HasMany
    {
        return $this->hasMany(DistributionAttempt::class, 'delivery_id');
    }
}
