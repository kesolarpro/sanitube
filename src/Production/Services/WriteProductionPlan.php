<?php

declare(strict_types=1);

namespace SaniTube\Production\Services;

use App\Models\User;
use Illuminate\Support\Str;
use SaniTube\Editorial\Models\EditorialProfile;
use SaniTube\Production\Enums\AutonomyMode;
use SaniTube\Production\Enums\ProductionPlanStatus;
use SaniTube\Production\Exceptions\ProductionPlanException;
use SaniTube\Production\Models\ProductionPlan;

/**
 * Writing a production plan, and moving it between states.
 *
 * **The locked mode is refused here as well as in the enum**, and the
 * duplication is deliberate. The enum says which modes exist and which may be
 * chosen; this says that a write attempting a locked one fails rather than
 * silently falling back to something safer. A silent fallback is the worse
 * failure: an operator who asked for unattended release and got `ASSISTED`
 * without being told believes the platform is doing something it is not.
 *
 * A plan starts `ACTIVE`. Creating one is a deliberate act and a plan that
 * arrived paused would be a plan somebody has to remember to start — which is
 * how a body of work quietly never happens.
 */
final readonly class WriteProductionPlan
{
    /**
     * @param  array<string, mixed>  $attributes
     *
     * @throws ProductionPlanException
     */
    public function create(EditorialProfile $profile, array $attributes, ?User $author = null): ProductionPlan
    {
        if (! $profile->is_active) {
            throw ProductionPlanException::profileInactive();
        }

        $name = $this->name($attributes['name'] ?? null);
        $slug = $this->slug($name);

        if (ProductionPlan::query()->where('slug', $slug)->exists()) {
            throw ProductionPlanException::slugTaken($slug);
        }

        $mode = $this->mode($attributes['autonomy_mode'] ?? AutonomyMode::Manual);

        $plan = ProductionPlan::query()->create([
            'name' => $name,
            'slug' => $slug,
            'editorial_profile_id' => $profile->id,
            'autonomy_mode' => $mode,

            // Active on creation. A plan that arrived paused is a plan
            // somebody has to remember to start, which is how a body of work
            // quietly never happens.
            'status' => ProductionPlanStatus::Active,
            'created_by' => $author?->id,
        ]);

        $optional = $this->optional($attributes);

        if ($optional !== []) {
            $plan->forceFill($optional)->save();
        }

        return $plan->refresh();
    }

    /**
     * Change what the platform is allowed to do on its own.
     *
     * Its own method rather than a field on a general update, because this is
     * the setting with consequences and it should be visible in a call site as
     * the thing that happened.
     *
     * @throws ProductionPlanException
     */
    public function setAutonomy(ProductionPlan $plan, AutonomyMode $mode): ProductionPlan
    {
        $plan->forceFill(['autonomy_mode' => $this->mode($mode)])->save();

        return $plan->refresh();
    }

    /**
     * A person stopping a plan.
     *
     * The reason columns stay null: they record why *the platform* stopped, and
     * a person's reason belongs in the audit line that records their act. A
     * column the plan overwrites on its next self-stop is not a record of
     * anything.
     */
    public function pause(ProductionPlan $plan): ProductionPlan
    {
        $plan->forceFill([
            'status' => ProductionPlanStatus::Paused,
            'halted_reason' => null,
            'halted_at' => null,
        ])->save();

        return $plan->refresh();
    }

    /**
     * The platform stopping itself and asking for somebody.
     *
     * **Not the same as pausing**, and the separate method is the point: a
     * self-stop carries a machine-readable reason and a time, and a list that
     * mixed these in with deliberate pauses would tell an operator nothing
     * about which of them needs their attention.
     */
    public function requireReview(ProductionPlan $plan, string $reason): ProductionPlan
    {
        $plan->forceFill([
            'status' => ProductionPlanStatus::ReviewRequired,
            'halted_reason' => mb_substr($reason, 0, 64),
            'halted_at' => now(),
        ])->save();

        return $plan->refresh();
    }

    /**
     * The plan did what it set out to do.
     */
    public function exhaust(ProductionPlan $plan): ProductionPlan
    {
        $plan->forceFill([
            'status' => ProductionPlanStatus::Exhausted,
            'halted_reason' => 'TARGET_REACHED',
            'halted_at' => now(),
        ])->save();

        return $plan->refresh();
    }

    /**
     * @throws ProductionPlanException
     */
    public function resume(ProductionPlan $plan): ProductionPlan
    {
        if (! $plan->status->isResumable()) {
            // An exhausted plan needs its target raised and a disabled one
            // needs reconsidering; neither is a thing a resume button should
            // do silently on somebody's behalf.
            throw ProductionPlanException::notResumable($plan->status);
        }

        $plan->forceFill([
            'status' => ProductionPlanStatus::Active,
            'halted_reason' => null,
            'halted_at' => null,
        ])->save();

        return $plan->refresh();
    }

    public function disable(ProductionPlan $plan): ProductionPlan
    {
        $plan->forceFill(['status' => ProductionPlanStatus::Disabled])->save();

        return $plan->refresh();
    }

    /**
     * @throws ProductionPlanException
     */
    private function mode(mixed $mode): AutonomyMode
    {
        $resolved = $mode instanceof AutonomyMode
            ? $mode
            : AutonomyMode::tryFrom(is_string($mode) ? $mode : '');

        if (! $resolved instanceof AutonomyMode) {
            return AutonomyMode::Manual;
        }

        // Refused rather than quietly lowered. An operator who asked for
        // unattended release and silently got ASSISTED believes the platform is
        // doing something it is not.
        if (! $resolved->isAvailable()) {
            throw ProductionPlanException::modeLocked($resolved);
        }

        return $resolved;
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function optional(array $attributes): array
    {
        $fields = [];

        if (array_key_exists('target_track_count', $attributes)) {
            $target = $attributes['target_track_count'];
            $fields['target_track_count'] = is_int($target) && $target > 0 ? $target : null;
        }

        if (array_key_exists('cadence_days', $attributes)) {
            $cadence = $attributes['cadence_days'];
            $fields['cadence_days'] = is_int($cadence) && $cadence > 0 ? min($cadence, 3650) : null;
        }

        if (array_key_exists('notes', $attributes)) {
            $notes = $attributes['notes'];
            $fields['notes'] = is_string($notes) && trim($notes) !== '' ? trim($notes) : null;
        }

        return $fields;
    }

    /**
     * @throws ProductionPlanException
     */
    private function name(mixed $value): string
    {
        $name = is_string($value) ? trim($value) : '';

        if ($name === '') {
            throw ProductionPlanException::nameRequired();
        }

        return mb_substr($name, 0, 255);
    }

    private function slug(string $name): string
    {
        $slug = Str::slug($name);

        return $slug === '' ? 'plan-'.substr((string) Str::uuid7(), 0, 8) : mb_substr($slug, 0, 128);
    }
}
