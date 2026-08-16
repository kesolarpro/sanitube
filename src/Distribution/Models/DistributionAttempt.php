<?php

declare(strict_types=1);

namespace SaniTube\Distribution\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use SaniTube\Distribution\Enums\DistributionAction;
use SaniTube\Distribution\Enums\DistributionAttemptOutcome;
use SaniTube\Foundation\Concerns\HasPublicUuid;

/**
 * One conversation with a distributor. Append-only.
 *
 * @property int $id
 * @property string $uuid
 * @property int $delivery_id
 * @property DistributionAction $action
 * @property DistributionAttemptOutcome $outcome
 * @property string $idempotency_key
 * @property int|null $decided_by
 * @property string|null $response_summary
 * @property int|null $duration_ms
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class DistributionAttempt extends Model
{
    use HasPublicUuid;

    protected $table = 'distribution_attempts';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'action' => DistributionAction::class,
            'outcome' => DistributionAttemptOutcome::class,
        ];
    }

    /**
     * @return BelongsTo<DistributionDelivery, $this>
     */
    public function delivery(): BelongsTo
    {
        return $this->belongsTo(DistributionDelivery::class, 'delivery_id');
    }
}
