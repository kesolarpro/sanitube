<?php

declare(strict_types=1);

namespace SaniTube\Transcription\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One stretch of speech within a transcript.
 *
 * Not a "line" and not a lyric. Providers segment on silence, on punctuation or
 * on a fixed window, and none of those is a lyrical line — naming it one here
 * would make every screen above assume a structure the audio never had.
 *
 * @property int $id
 * @property int $transcript_id
 * @property int $position
 * @property int $start_ms
 * @property int $end_ms
 * @property string $text
 */
final class TranscriptSegment extends Model
{
    protected $table = 'transcript_segments';

    protected $guarded = [];

    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'start_ms' => 'integer',
            'end_ms' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Transcript, $this>
     */
    public function transcript(): BelongsTo
    {
        return $this->belongsTo(Transcript::class);
    }
}
