<?php

declare(strict_types=1);

namespace SaniTube\Enrichment\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use SaniTube\Assets\Models\Asset;
use SaniTube\Enrichment\Enums\SuggestionStatus;
use SaniTube\Enrichment\SuggestedMetadata;
use SaniTube\Foundation\Concerns\HasPublicUuid;
use SaniTube\Transcription\Models\Transcript;

/**
 * A model's proposal about one recording, kept where a person can answer it.
 *
 * **Nothing here is catalogue data** (ADR-0016). Every column is prefixed
 * `suggested_` because a column called `title` gets copied into a track by
 * somebody in a hurry, and one called `suggested_title` gets questioned first.
 * Acceptance is a separate act, performed by a person, through the ordinary
 * domain services — never by this row being copied.
 *
 * The provenance columns are what make a suggestion judgeable months later:
 * which prompt version asked, which provider and model answered, and which
 * piece of stored evidence they were given. Without them, "is this still
 * something we believe" has no answer.
 *
 * @property int $id
 * @property string $uuid
 * @property int $asset_id
 * @property string $task
 * @property string $provider
 * @property string|null $model
 * @property string $prompt_version
 * @property int|null $transcript_id
 * @property string|null $evidence_version
 * @property string|null $suggested_title
 * @property string|null $suggested_language
 * @property list<string>|null $suggested_genres
 * @property list<string>|null $suggested_moods
 * @property list<string>|null $suggested_themes
 * @property string|null $suggested_description
 * @property int|null $confidence_basis_points
 * @property string|null $rationale
 * @property SuggestionStatus $status
 * @property int|null $decided_by
 * @property Carbon|null $decided_at
 * @property Carbon $suggested_at
 */
final class MetadataSuggestion extends Model
{
    use HasPublicUuid;

    /**
     * SaniTube's word for what was asked. One task today; the column exists so
     * a second one does not need a second table.
     */
    public const TASK_TRACK_METADATA = 'track_metadata';

    protected $table = 'metadata_suggestions';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => SuggestionStatus::class,
            'suggested_genres' => 'array',
            'suggested_moods' => 'array',
            'suggested_themes' => 'array',
            'suggested_at' => 'datetime',
            'decided_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Asset, $this>
     */
    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    /**
     * The evidence this was made from, if it still exists.
     *
     * Nullable on purpose. A transcript can be replaced by a newer provider
     * version, and a suggestion whose evidence is gone is still worth reading —
     * it just needs to be read knowing that.
     *
     * @return BelongsTo<Transcript, $this>
     */
    public function transcript(): BelongsTo
    {
        return $this->belongsTo(Transcript::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    /**
     * The model's own opinion of its answer, as a percentage.
     *
     * **Recorded, never acted on.** A confidence a model reports about its own
     * output is more output, not evidence about the output — and a threshold
     * that auto-accepted above some number would be a threshold that ships a
     * wrong title to a distributor unread.
     */
    public function confidence(): ?float
    {
        return $this->confidence_basis_points === null
            ? null
            : $this->confidence_basis_points / 100;
    }

    /**
     * Whether the evidence this was made from is still the evidence on file.
     *
     * The question a reviewer actually needs answered before accepting
     * something a model said three weeks ago.
     */
    public function evidenceIsCurrent(): bool
    {
        $transcript = $this->transcript;

        if (! $transcript instanceof Transcript) {
            return $this->transcript_id === null && $this->evidence_version === null;
        }

        return $this->evidence_version === $transcript->provider.':'.$transcript->provider_version;
    }

    public function suggestion(): SuggestedMetadata
    {
        return new SuggestedMetadata(
            title: $this->suggested_title,
            language: $this->suggested_language,
            genres: $this->suggested_genres ?? [],
            moods: $this->suggested_moods ?? [],
            themes: $this->suggested_themes ?? [],
            description: $this->suggested_description,
            confidenceBasisPoints: $this->confidence_basis_points,
            rationale: $this->rationale,
        );
    }
}
