<?php

declare(strict_types=1);

namespace SaniTube\Ui\Queries;

use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Database\Eloquent\Builder;
use SaniTube\Catalog\Models\Track;
use SaniTube\Enrichment\Enums\SuggestionStatus;
use SaniTube\Enrichment\Models\MetadataSuggestion;
use SaniTube\Media\Models\AudioAnalysis;
use SaniTube\Transcription\Models\Transcript;

/**
 * The enrichment review queue.
 *
 * **The four levels are separate objects in the payload, and that is the whole
 * design of this screen.** ADR-0016 draws three lines and the catalogue draws
 * the fourth, and a reviewer who cannot see which is which will accept a
 * model's guess believing it was measured:
 *
 *   - `canonical` — what the catalogue currently says. A person put it there.
 *   - `measured` — what the platform observed from the file itself. Facts.
 *   - `proposed` — what a specialised model reported. The transcript's detected
 *     language: narrow, purpose-built, and still a guess.
 *   - `suggested` — what a language model interpreted. The weakest thing here.
 *
 * They are **named by the server**, never assembled in the bundle from a flat
 * row. DEDUP-003 learned that lesson: a component that re-derives which level a
 * value belongs to is a component that can drift from the domain, and this is
 * the one distinction this screen must not get wrong.
 *
 * `canonical` is **null when there is no track**, and is never filled in from
 * the suggestion. A blank canonical column is the honest answer to "what does
 * the catalogue say about this recording" when the answer is "nothing yet", and
 * showing the model's guess in that column would be the exact confusion the
 * separation exists to prevent.
 *
 * Ordered by `(suggested_at, uuid)` and by nothing else — not by confidence.
 * A queue that promoted confident answers to the top would teach a reviewer
 * that the rows near the bottom are the doubtful ones, which is a claim no
 * model's self-reported confidence supports.
 *
 * Nothing here is authorisation. `actions` is presentation; the route
 * middleware is the check.
 */
final readonly class SuggestionReviewQuery
{
    public const PER_PAGE = 25;

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function paginate(array $filters = [], ?string $cursor = null, int $perPage = self::PER_PAGE): array
    {
        $query = MetadataSuggestion::query()->with(['asset', 'transcript']);

        $this->applyFilters($query, $filters);

        $page = $query
            ->orderBy('suggested_at')
            ->orderBy('uuid')
            ->cursorPaginate($perPage, ['*'], 'cursor', $this->validCursor($cursor));

        return [
            'rows' => $this->rows($page),
            'next_cursor' => $page->nextCursor()?->encode(),
            'previous_cursor' => $page->previousCursor()?->encode(),
            'per_page' => $perPage,
        ];
    }

    /**
     * @return array<string, list<string>>
     */
    public function options(): array
    {
        return [
            'status' => array_map(
                static fn (SuggestionStatus $case): string => $case->value,
                SuggestionStatus::cases(),
            ),
        ];
    }

    public static function describesThisOrdering(string $cursor): bool
    {
        return CursorShape::carries($cursor, ['suggested_at', 'uuid']);
    }

    /**
     * @param  CursorPaginator<int, MetadataSuggestion>  $page
     * @return list<array<string, mixed>>
     */
    private function rows(CursorPaginator $page): array
    {
        $rows = [];

        // Both lookups for the whole page, once, before the loop.
        //
        // They used to be inside it: a track query and an analysis query per
        // suggestion, fifty queries for a page of twenty-five. Neither shows
        // up in the growth measurement `CatalogueScaleTest` runs, and that is
        // not an oversight in that test — an N+1 behind a cursor runs once per
        // *row on the page*, and the page size does not change with the
        // catalogue. It is the per-screen ceiling that catches this shape, and
        // this screen was one of the five that test could not reach.
        $assetIds = $this->assetIds($page);
        $tracks = $this->tracksByAsset($assetIds);
        $analyses = $this->analysesByAsset($assetIds);

        foreach ($page->items() as $suggestion) {
            $track = $tracks[$suggestion->asset_id] ?? null;

            $rows[] = [
                'uuid' => $suggestion->uuid,
                'asset_uuid' => $suggestion->asset?->uuid,
                'task' => $suggestion->task,
                'status' => $suggestion->status->value,

                // How this was produced, so a reviewer can judge it rather than
                // only read it. A prompt version later found to be wrong is
                // traceable through this column and nowhere else.
                'provider' => $suggestion->provider,
                'model' => $suggestion->model,
                'prompt_version' => $suggestion->prompt_version,

                // The model's opinion of its own answer, shown and never acted
                // on. It is more output, not evidence about the output.
                'confidence' => $suggestion->confidence(),

                // Whether the evidence this was made from is still the evidence
                // on file. The question a reviewer actually has before
                // accepting something a model said three weeks ago.
                'evidence_is_current' => $suggestion->evidenceIsCurrent(),

                'suggested_at' => $suggestion->suggested_at->toAtomString(),
                'decided_at' => $suggestion->decided_at?->toAtomString(),

                'canonical' => $this->canonical($track),
                'measured' => $this->measured($analyses[$suggestion->asset_id] ?? null),
                'proposed' => $this->proposed($suggestion),
                'suggested' => $this->suggested($suggestion),

                // Presentation. A reviewer with nothing to accept into should
                // be told so before they press a button, and the route
                // middleware is what actually refuses.
                'actions' => [
                    'decidable' => $suggestion->status === SuggestionStatus::Proposed,
                    'has_track' => $track instanceof Track,
                ],
            ];
        }

        return $rows;
    }

    /**
     * The assets this page of suggestions points at, deduplicated.
     *
     * @param  CursorPaginator<int, MetadataSuggestion>  $page
     * @return list<int>
     */
    private function assetIds(CursorPaginator $page): array
    {
        $ids = [];

        foreach ($page->items() as $suggestion) {
            $ids[$suggestion->asset_id] = true;
        }

        return array_map('intval', array_keys($ids));
    }

    /**
     * The track each asset is the master of, if any.
     *
     * Keyed by asset rather than by track, because that is the direction this
     * screen asks in: it holds a suggestion about a file and wants to know
     * what the catalogue already says about it. Most assets have no track —
     * a suggestion on an unpromoted candidate is the ordinary case — so this
     * returns fewer entries than it was given ids, and `canonical` stays null
     * for the rest, which is the honest answer.
     *
     * @param  list<int>  $assetIds
     * @return array<int, Track>
     */
    private function tracksByAsset(array $assetIds): array
    {
        if ($assetIds === []) {
            return [];
        }

        $tracks = [];

        foreach (Track::query()->whereIn('master_asset_id', $assetIds)->get() as $track) {
            $tracks[(int) $track->master_asset_id] = $track;
        }

        return $tracks;
    }

    /**
     * The newest successful analysis for each asset.
     *
     * Reduced in PHP rather than with a window function or a correlated
     * subquery: this platform runs on SQLite, MySQL 8 and MariaDB 10.6, and
     * the shapes that express "latest per group" in SQL are exactly where
     * those three disagree. The rows fetched are bounded by the page — twenty
     * five assets, and an asset is analysed once or twice — so the reduction
     * is over tens of rows, not the table.
     *
     * @param  list<int>  $assetIds
     * @return array<int, AudioAnalysis>
     */
    private function analysesByAsset(array $assetIds): array
    {
        if ($assetIds === []) {
            return [];
        }

        $analyses = [];

        // Ascending, so the last one written for an asset is the one left in
        // the map — the same row `latest('id')->first()` used to return.
        $found = AudioAnalysis::query()
            ->whereIn('asset_id', $assetIds)
            ->where('succeeded', true)
            ->orderBy('id')
            ->get();

        foreach ($found as $analysis) {
            $analyses[(int) $analysis->asset_id] = $analysis;
        }

        return $analyses;
    }

    /**
     * What the catalogue currently says.
     *
     * Null when this asset masters no track, and never filled in from the
     * suggestion. "Nothing yet" is the honest answer, and putting the model's
     * guess in this column would be exactly the confusion the four levels exist
     * to prevent.
     *
     * @return array<string, mixed>|null
     */
    private function canonical(?Track $track): ?array
    {
        if (! $track instanceof Track) {
            return null;
        }

        return [
            'track_uuid' => $track->uuid,
            'title' => $track->title,
            'language_code' => $track->language_code,
            'genre_primary' => $track->genre_primary,
            'genre_secondary' => $track->genre_secondary,
            'status' => $track->status->value,
        ];
    }

    /**
     * What the platform observed from the file.
     *
     * Facts, and shown beside the guesses so a reviewer can sanity-check one
     * against the other — a "three minute folk song" suggested over a
     * nine-second file is visible here and nowhere else.
     *
     * @return array<string, mixed>|null
     */
    private function measured(?AudioAnalysis $analysis): ?array
    {
        if (! $analysis instanceof AudioAnalysis) {
            return null;
        }

        return [
            'duration_ms' => $analysis->duration_ms,
            'codec' => $analysis->codec,
            'channels' => $analysis->channels,
            'sample_rate' => $analysis->sample_rate,
        ];
    }

    /**
     * What a specialised model reported.
     *
     * The transcript's detected language sits at its own level rather than
     * being folded in with the suggestion. It came from a purpose-built model
     * that heard the audio, which makes it better evidence than an
     * interpretation of a transcript — and still a guess, which is why it is
     * not canonical.
     *
     * @return array<string, mixed>|null
     */
    private function proposed(MetadataSuggestion $suggestion): ?array
    {
        $transcript = $suggestion->transcript;

        if (! $transcript instanceof Transcript) {
            return null;
        }

        return [
            'language' => $transcript->detected_language,
            'language_hint' => $transcript->language_hint,
            'contradicts_the_hint' => $transcript->contradictsTheHint(),
            'provider' => $transcript->provider,
            'covered_seconds' => $transcript->covered_seconds,
        ];
    }

    /**
     * What a language model interpreted. The weakest level.
     *
     * @return array<string, mixed>
     */
    private function suggested(MetadataSuggestion $suggestion): array
    {
        return [
            'title' => $suggestion->suggested_title,
            'language' => $suggestion->suggested_language,
            'genres' => $suggestion->suggested_genres ?? [],
            'moods' => $suggestion->suggested_moods ?? [],
            'themes' => $suggestion->suggested_themes ?? [],
            'description' => $suggestion->suggested_description,

            // What in the evidence led to the answer. The most useful thing on
            // the row for deciding whether to believe the rest of it.
            'rationale' => $suggestion->rationale,
        ];
    }

    /**
     * @param  Builder<MetadataSuggestion>  $query
     * @param  array<string, mixed>  $filters
     */
    private function applyFilters(Builder $query, array $filters): void
    {
        $status = $filters['status'] ?? null;

        if (is_string($status) && $status !== '') {
            $query->where('status', SuggestionStatus::from($status)->value);
        }
    }

    /**
     * A cursor from a different ordering describes a position that does not
     * exist here, and following it would silently return the wrong page.
     */
    private function validCursor(?string $cursor): ?string
    {
        return is_string($cursor) && $cursor !== '' && self::describesThisOrdering($cursor) ? $cursor : null;
    }
}
