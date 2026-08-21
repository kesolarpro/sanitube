<?php

declare(strict_types=1);

namespace SaniTube\Ui\Queries;

use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;
use SaniTube\Distribution\Enums\DistributionDeliveryStatus;
use SaniTube\Distribution\Models\DistributionDelivery;

/**
 * Every handover this installation has made or attempted.
 *
 * Two things never leave the server, and they are the same two the API refuses
 * to publish: `external_release_id` identifies a record in somebody else's
 * account, and `idempotency_key` is the token that makes a repeat submission
 * recognisable — a reader holding it could construct one. The screen shows
 * `has_external_reference` instead, because the question a label actually has
 * is *"did this reach them"*, not *"what did they call it"*.
 *
 * `failure_reason` is **not** on this screen at all. The index answers "which
 * deliveries need attention"; why one failed is a question the detail screen
 * takes, where the reason is scrubbed and shortened before it is shown.
 */
final readonly class DeliveryIndexQuery
{
    public const PER_PAGE = 25;

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function paginate(array $filters = [], ?string $cursor = null, int $perPage = self::PER_PAGE): array
    {
        $cursor = $this->validCursor($cursor);

        $query = DistributionDelivery::query()->with('release')->withCount('attempts');

        $this->applyFilters($query, $filters);

        $page = $query
            ->orderBy('created_at')
            ->orderBy('uuid')
            ->cursorPaginate($perPage, ['*'], 'cursor', $cursor);

        return [
            'rows' => $this->rows($page),
            'next_cursor' => $page->nextCursor()?->encode(),
            'previous_cursor' => $page->previousCursor()?->encode(),
            'per_page' => $perPage,
        ];
    }

    /**
     * @param  list<string>  $providers
     * @return array<string, list<string>>
     */
    public function options(array $providers): array
    {
        return [
            'status' => array_map(
                static fn (DistributionDeliveryStatus $case): string => $case->value,
                DistributionDeliveryStatus::cases(),
            ),
            'provider' => $providers,
        ];
    }

    public static function describesThisOrdering(string $cursor): bool
    {
        return CursorShape::carries($cursor, ['created_at', 'uuid']);
    }

    /**
     * @param  CursorPaginator<int, DistributionDelivery>  $page
     * @return list<array<string, mixed>>
     */
    private function rows(CursorPaginator $page): array
    {
        $rows = [];

        foreach ($page->items() as $delivery) {
            $release = $delivery->release;

            $rows[] = [
                'uuid' => $delivery->uuid,
                'provider' => $delivery->provider,
                'status' => $delivery->status->value,
                'release' => $release === null ? null : [
                    'uuid' => $release->uuid,
                    'title' => $release->title,
                ],
                'attempt_count' => (int) ($delivery->attempts_count ?? 0),
                'has_external_reference' => $delivery->external_release_id !== null,
                'submitted_at' => $delivery->submitted_at?->toAtomString(),
                'live_at' => $delivery->live_at?->toAtomString(),
                'last_synced_at' => $delivery->last_synced_at?->toAtomString(),
                'created_at' => $delivery->created_at?->toAtomString(),
            ];
        }

        return $rows;
    }

    /**
     * @param  Builder<DistributionDelivery>  $query
     * @param  array<string, mixed>  $filters
     */
    private function applyFilters(Builder $query, array $filters): void
    {
        $status = $filters['status'] ?? null;

        if (is_string($status) && $status !== '') {
            $query->where('status', DistributionDeliveryStatus::from($status)->value);
        }

        $provider = $filters['provider'] ?? null;

        if (is_string($provider) && $provider !== '') {
            $query->where('provider', $provider);
        }
    }

    private function validCursor(?string $cursor): ?string
    {
        if ($cursor === null || $cursor === '') {
            return null;
        }

        if (! self::describesThisOrdering($cursor)) {
            throw new InvalidArgumentException('cursor');
        }

        return $cursor;
    }
}
