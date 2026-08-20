<?php

declare(strict_types=1);

namespace SaniTube\Production\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use SaniTube\Editorial\Models\EditorialProfile;
use SaniTube\Foundation\Concerns\HasPublicUuid;
use SaniTube\Production\Enums\AutonomyMode;
use SaniTube\Production\Enums\ProductionPlanStatus;

/**
 * A body of work somebody intends to produce, and how far the platform may
 * carry it alone.
 *
 * **Autonomy lives here and nowhere else.** Not on the artist, which is a
 * public credit; not on the editorial profile, which is taste. How much a
 * machine may do unattended is a decision about *this body of work*, and the
 * same artist can appear on one plan driven by hand and another that generates
 * on its own.
 *
 * A plan is a standing intention rather than a schedule. `cadence_days` says
 * how often it expects to produce something; when a particular piece of work
 * actually happens is a slot's business, and the separation is what keeps a
 * plan from becoming a cron entry with opinions.
 *
 * @property int $id
 * @property string $uuid
 * @property string $name
 * @property string $slug
 * @property int $editorial_profile_id
 * @property AutonomyMode $autonomy_mode
 * @property ProductionPlanStatus $status
 * @property int|null $target_track_count
 * @property int|null $cadence_days
 * @property string|null $notes
 * @property string|null $halted_reason
 * @property Carbon|null $halted_at
 * @property int|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class ProductionPlan extends Model
{
    use HasPublicUuid;

    protected $table = 'production_plans';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'autonomy_mode' => AutonomyMode::class,
            'status' => ProductionPlanStatus::class,
            'halted_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<EditorialProfile, $this>
     */
    public function editorialProfile(): BelongsTo
    {
        return $this->belongsTo(EditorialProfile::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Whether the platform may act on this plan unattended right now.
     *
     * **Both halves are required, and asking for them separately is how a
     * paused plan keeps generating.** The status says whether the plan is
     * running at all; the mode says how far the platform may go on its own. A
     * caller that checked only the mode would happily generate under a plan
     * somebody stopped an hour ago.
     */
    public function mayGenerateUnattended(): bool
    {
        return $this->status->isRunnable() && $this->autonomy_mode->mayGenerateUnattended();
    }

    public function mayPrepareReleasesUnattended(): bool
    {
        return $this->status->isRunnable() && $this->autonomy_mode->mayPrepareReleases();
    }

    /**
     * Whether the platform may hand a release to a distributor unattended.
     *
     * **False on every plan that can exist**, because the mode that would make
     * it true cannot be set. Written as a method rather than left implicit so
     * that a future caller asks the question in one place, and so that turning
     * it on is one edit rather than a search.
     */
    public function mayReleaseUnattended(): bool
    {
        return $this->status->isRunnable() && $this->autonomy_mode->mayReleaseUnattended();
    }
}
