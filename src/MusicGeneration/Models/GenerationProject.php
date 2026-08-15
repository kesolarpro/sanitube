<?php

declare(strict_types=1);

namespace SaniTube\MusicGeneration\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use SaniTube\Foundation\Concerns\HasPublicUuid;
use SaniTube\MusicGeneration\Enums\GenerationProjectStatus;

/**
 * A generation campaign.
 *
 * @property int $id
 * @property string $uuid
 * @property string $name
 * @property GenerationProjectStatus $status
 * @property int|null $target_track_count
 * @property string|null $default_language
 * @property string|null $default_genre
 * @property string|null $default_style_prompt
 * @property int|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class GenerationProject extends Model
{
    use HasPublicUuid;

    protected $table = 'generation_projects';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['status' => GenerationProjectStatus::class];
    }

    /**
     * @return HasMany<MusicGeneration, $this>
     */
    public function generations(): HasMany
    {
        return $this->hasMany(MusicGeneration::class, 'project_id');
    }
}
