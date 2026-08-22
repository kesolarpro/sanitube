<?php

declare(strict_types=1);

namespace SaniTube\Ui\Queries;

use Illuminate\Support\Facades\DB;
use SaniTube\Editorial\Models\EditorialProfile;
use SaniTube\Production\Models\ProductionPlan;

/**
 * The imprints this installation writes in the manner of.
 *
 * PROD-002. A production plan cannot exist without one of these, and until now
 * nothing could make one: `WriteEditorialProfile` had no controller, no console
 * command and no seeder, so the only way a profile came into being was a
 * database client. Which meant the planner — the one part of SaniTube that acts
 * unattended — could not be started from inside the product at all.
 *
 * **Everything about a particular label is in these rows, never in code.** A
 * second imprint on the same installation is a second row and not a fork, and
 * this screen is where the first one is made.
 *
 * Not paginated. An installation has imprints, not a catalogue of them — the
 * table grows with how many labels somebody runs, which is a number a person
 * chose. If that ever stops being true the failure is visible rather than
 * silent, which is why the count travels with the rows.
 */
final readonly class EditorialProfileIndexQuery
{
    /**
     * @return array<string, mixed>
     */
    public function overview(): array
    {
        $profiles = EditorialProfile::query()
            ->with('defaultArtist')
            ->orderBy('is_active', 'desc')
            ->orderBy('name')
            ->get();

        // One grouped query rather than one per row. A count per profile is
        // the N+1 PERF-001 spent a ticket removing everywhere else, and this
        // list renders on every visit to the screen that makes a plan.
        $plans = $this->planCounts();

        $rows = [];

        foreach ($profiles as $profile) {
            $rows[] = [
                'uuid' => $profile->uuid,
                'name' => $profile->name,
                // Published and never editable. The slug is frozen at creation
                // because it is how a console command names a profile, and one
                // that followed a rename would repoint every reference.
                'slug' => $profile->slug,
                'is_active' => $profile->is_active,
                'summary' => $profile->summary,
                'default_language' => $profile->default_language,
                'default_artist_id' => $profile->default_artist_id,
                'default_artist' => $profile->defaultArtist?->name,
                'preferred_genres' => $profile->preferred_genres ?? [],
                'preferred_moods' => $profile->preferred_moods ?? [],
                'preferred_themes' => $profile->preferred_themes ?? [],
                'avoided_terms' => $profile->avoided_terms ?? [],
                'title_guidance' => $profile->title_guidance,
                'description_guidance' => $profile->description_guidance,
                'plans' => $plans[$profile->id] ?? 0,
            ];
        }

        return ['rows' => $rows, 'total' => count($rows)];
    }

    /**
     * How many plans point at each profile, in one query.
     *
     * @return array<int, int>
     */
    private function planCounts(): array
    {
        $counts = [];

        $rows = ProductionPlan::query()
            ->select('editorial_profile_id', DB::raw('count(*) as total'))
            ->groupBy('editorial_profile_id')
            ->get();

        foreach ($rows as $row) {
            $counts[(int) $row->editorial_profile_id] = (int) $row->getAttribute('total');
        }

        return $counts;
    }

    /**
     * The profiles a plan may be pointed at.
     *
     * **Active ones only.** A plan pointed at a retired imprint is one that
     * produces in the manner of something the label has stopped using, and the
     * writer refuses it — so offering it here would be a choice that fails on
     * save.
     *
     * @return list<array<string, mixed>>
     */
    public function selectable(): array
    {
        $rows = [];

        foreach (EditorialProfile::query()->where('is_active', true)->orderBy('name')->get() as $profile) {
            $rows[] = ['uuid' => $profile->uuid, 'name' => $profile->name];
        }

        return $rows;
    }
}
