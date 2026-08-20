<?php

declare(strict_types=1);

namespace SaniTube\Transcription\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use SaniTube\Assets\Models\Asset;
use SaniTube\Foundation\Concerns\HasPublicUuid;

/**
 * What a supplier heard in one asset.
 *
 * **A suggestion and never catalogue truth** (ADR-0016). Nothing downstream may
 * read a row here as the track's lyrics, its language, or its title. It is
 * evidence that a model produced text, kept with enough about how it was
 * produced — which supplier, which version, what it was told to expect, how
 * much audio it covered — for a person to judge it.
 *
 * @property int $id
 * @property string $uuid
 * @property int $asset_id
 * @property string $provider
 * @property string $provider_version
 * @property string|null $model
 * @property string|null $detected_language
 * @property string|null $language_hint
 * @property string $text
 * @property int|null $covered_seconds
 * @property Carbon $transcribed_at
 */
final class Transcript extends Model
{
    use HasPublicUuid;

    protected $table = 'transcripts';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['transcribed_at' => 'datetime'];
    }

    /**
     * Whether the supplier disagreed with what it was told to expect.
     *
     * Not an error, and specifically not treated as one. A hint is what an
     * operator believed; the guess is what a model heard. The two differing is
     * the single most useful thing this row can say — it is how a
     * mislabelled import gets noticed — so it is surfaced rather than resolved.
     */
    public function contradictsTheHint(): bool
    {
        return $this->language_hint !== null
            && $this->detected_language !== null
            && strtolower($this->language_hint) !== strtolower($this->detected_language);
    }

    /**
     * @return BelongsTo<Asset, $this>
     */
    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    /**
     * @return HasMany<TranscriptSegment, $this>
     */
    public function segments(): HasMany
    {
        return $this->hasMany(TranscriptSegment::class)->orderBy('position');
    }
}
