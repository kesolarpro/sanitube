<?php

declare(strict_types=1);

namespace SaniTube\Catalog\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;
use SaniTube\Catalog\Enums\CompositionContributorRole;
use SaniTube\Contributors\Models\Contributor;

/**
 * A writer credit on a composition.
 *
 * `share` is the credit as captured at catalogue time — what the paperwork
 * said when the work was entered. It is not the rights model: RGT-001 adds
 * territory, term and administration, and has to be free to disagree with
 * this. Treating this column as the authority on ownership is the mistake
 * this docblock exists to prevent.
 *
 * @property int $id
 * @property int $composition_id
 * @property int $contributor_id
 * @property CompositionContributorRole $role
 * @property numeric-string|null $share
 * @property int $position
 */
final class CompositionContributor extends Pivot
{
    protected $table = 'composition_contributor';

    public $incrementing = true;

    protected $guarded = [];

    /**
     * The most a set of credits for one role can add up to.
     */
    public const MAX_SHARE_PER_ROLE = 100;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => CompositionContributorRole::class,
            'share' => 'decimal:6',
        ];
    }

    /**
     * @return BelongsTo<Composition, $this>
     */
    public function composition(): BelongsTo
    {
        return $this->belongsTo(Composition::class);
    }

    /**
     * @return BelongsTo<Contributor, $this>
     */
    public function contributor(): BelongsTo
    {
        return $this->belongsTo(Contributor::class);
    }
}
