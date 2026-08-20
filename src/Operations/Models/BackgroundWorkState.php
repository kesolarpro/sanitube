<?php

declare(strict_types=1);

namespace SaniTube\Operations\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Whether this installation is currently doing background work.
 *
 * Exactly one row, always. It is created on first read rather than seeded, so
 * an installation that has never been paused still has something to report and
 * nothing has to special-case its absence.
 *
 * @property int $id
 * @property bool $is_paused
 * @property string|null $reason
 * @property int|null $paused_by_user_id
 * @property Carbon|null $paused_at
 */
final class BackgroundWorkState extends Model
{
    protected $table = 'background_work_state';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_paused' => 'boolean',
            'paused_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function pausedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'paused_by_user_id');
    }
}
