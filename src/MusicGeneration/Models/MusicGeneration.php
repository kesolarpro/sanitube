<?php

declare(strict_types=1);

namespace SaniTube\MusicGeneration\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use SaniTube\Foundation\Concerns\HasPublicUuid;
use SaniTube\MusicGeneration\Enums\CommercialRightsStatus;
use SaniTube\MusicGeneration\Enums\ExecutionMode;
use SaniTube\MusicGeneration\Enums\MusicGenerationStatus;

/**
 * One request to a generation provider, and what became of it.
 *
 * @property int $id
 * @property string $uuid
 * @property int|null $project_id
 * @property string $provider
 * @property string|null $provider_job_id
 * @property ExecutionMode|null $execution_mode
 * @property Carbon|null $submission_claimed_at
 * @property MusicGenerationStatus $status
 * @property string $prompt
 * @property string|null $lyrics
 * @property string|null $style_prompt
 * @property string $language_code
 * @property bool $instrumental
 * @property string|null $model
 * @property array<string, mixed>|null $parameters
 * @property int|null $terms_snapshot_id
 * @property CommercialRightsStatus $commercial_rights_status
 * @property string|null $failure_reason
 * @property Carbon|null $submitted_at
 * @property Carbon|null $completed_at
 * @property Carbon|null $last_polled_at
 * @property int $poll_count
 * @property int|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class MusicGeneration extends Model
{
    use HasPublicUuid;

    protected $table = 'music_generations';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => MusicGenerationStatus::class,
            'execution_mode' => ExecutionMode::class,
            'commercial_rights_status' => CommercialRightsStatus::class,
            'instrumental' => 'boolean',
            'parameters' => 'array',
            'submission_claimed_at' => 'datetime',
            'submitted_at' => 'datetime',
            'completed_at' => 'datetime',
            'last_polled_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<GenerationProject, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(GenerationProject::class, 'project_id');
    }

    /**
     * @return HasMany<MusicGenerationResult, $this>
     */
    public function results(): HasMany
    {
        return $this->hasMany(MusicGenerationResult::class, 'generation_id');
    }

    /**
     * @return BelongsTo<ProviderTermsSnapshot, $this>
     */
    public function termsSnapshot(): BelongsTo
    {
        return $this->belongsTo(ProviderTermsSnapshot::class, 'terms_snapshot_id');
    }
}
