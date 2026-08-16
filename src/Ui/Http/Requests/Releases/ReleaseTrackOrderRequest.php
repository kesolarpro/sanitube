<?php

declare(strict_types=1);

namespace SaniTube\Ui\Http\Requests\Releases;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A new order for one disc.
 *
 * A partial list is legitimate and is not an error: the builder keeps anything
 * the caller left out in its relative order and appends it, because dropping
 * it would make reorder a destructive operation that looks like a cosmetic one.
 */
final class ReleaseTrackOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'disc' => ['sometimes', 'integer', 'min:1', 'max:99'],
            'tracks' => ['required', 'array', 'min:1'],
            'tracks.*' => ['string', 'size:36'],
        ];
    }

    /**
     * @return list<string>
     */
    public function trackUuids(): array
    {
        /** @var array<int, mixed> $tracks */
        $tracks = (array) $this->input('tracks', []);

        return array_values(array_filter(
            array_map(static fn (mixed $uuid): string => is_string($uuid) ? $uuid : '', $tracks),
            static fn (string $uuid): bool => $uuid !== '',
        ));
    }

    public function disc(): int
    {
        return max(1, (int) $this->input('disc', 1));
    }
}
