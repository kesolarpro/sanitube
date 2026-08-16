<?php

declare(strict_types=1);

namespace SaniTube\Ui\Http\Requests\Releases;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use SaniTube\Releases\Enums\ReleaseArtistRole;

/**
 * The release's artist credits, in order.
 *
 * "At least one PRIMARY" is **not** validated here. It is a property of the
 * credit list that `ReleaseBuilder::setArtists()` enforces, and restating it in
 * a form request would put the rule in two places — one of which a future API
 * caller does not go through.
 */
final class ReleaseArtistsRequest extends FormRequest
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
            'artists' => ['required', 'array', 'min:1'],
            'artists.*.uuid' => ['required', 'string', 'size:36'],
            'artists.*.role' => ['required', Rule::enum(ReleaseArtistRole::class)],
        ];
    }

    /**
     * @return list<array{uuid: string, role: string}>
     */
    public function credits(): array
    {
        /** @var array<int, mixed> $artists */
        $artists = (array) $this->input('artists', []);

        $credits = [];

        foreach ($artists as $artist) {
            if (! is_array($artist)) {
                continue;
            }

            $uuid = $artist['uuid'] ?? null;
            $role = $artist['role'] ?? null;

            if (is_string($uuid) && is_string($role)) {
                $credits[] = ['uuid' => $uuid, 'role' => $role];
            }
        }

        return $credits;
    }
}
