<?php

declare(strict_types=1);

namespace SaniTube\Editorial\Services;

use Illuminate\Support\Str;
use SaniTube\Editorial\Exceptions\EditorialProfileException;
use SaniTube\Editorial\Models\EditorialProfile;

/**
 * Creating and correcting an imprint's editorial profile.
 *
 * One service for both, because the rules are the same in both directions and
 * a separate updater is a second copy of them that drifts. The difference is a
 * nullable first argument.
 *
 * **The slug is derived once and then frozen.** It is how a production plan and
 * a console command name a profile, and a slug that followed a rename would
 * silently repoint every reference — turning "rename this imprint" into "orphan
 * everything that mentioned it".
 */
final readonly class WriteEditorialProfile
{
    /**
     * Fields a caller may set. A closed list, so a payload carrying
     * `is_active` or `id` cannot reach the row through a mass assignment.
     */
    private const WRITABLE = [
        'summary',
        'default_artist_id',
        'default_language',
        'preferred_genres',
        'preferred_moods',
        'preferred_themes',
        'avoided_terms',
        'title_guidance',
        'description_guidance',
    ];

    /**
     * @param  array<string, mixed>  $attributes
     *
     * @throws EditorialProfileException
     */
    public function create(array $attributes): EditorialProfile
    {
        $name = $this->name($attributes['name'] ?? null);
        $slug = $this->slug($name);

        if (EditorialProfile::query()->where('slug', $slug)->exists()) {
            throw EditorialProfileException::slugTaken($slug);
        }

        // **Validated before anything is written.** Reading the fields after
        // creating the row would leave a half-made profile behind whenever one
        // of them is refused -- a named imprint with none of the policy that
        // was the reason for creating it, sitting in a unique slug so the
        // corrected attempt cannot succeed either.
        $fields = $this->fields($attributes);

        // Created with the identity columns, then filled. Spreading the
        // optional fields into the `create()` literal collapses it to
        // `array<string, mixed>`, which tells static analysis nothing about
        // which columns are written -- and the closed writable list is the
        // whole guard here, so it is worth keeping checkable.
        $profile = EditorialProfile::query()->create([
            'name' => $name,
            'slug' => $slug,
            'is_active' => true,
        ]);

        if ($fields !== []) {
            $profile->forceFill($fields)->save();
        }

        return $profile->refresh();
    }

    /**
     * @param  array<string, mixed>  $attributes
     *
     * @throws EditorialProfileException
     */
    public function update(EditorialProfile $profile, array $attributes): EditorialProfile
    {
        $changes = $this->fields($attributes);

        if (array_key_exists('name', $attributes)) {
            // The name changes; the slug does not. Everything that references
            // this profile does so by slug.
            $changes['name'] = $this->name($attributes['name']);
        }

        if (array_key_exists('is_active', $attributes)) {
            $changes['is_active'] = (bool) $attributes['is_active'];
        }

        if ($changes === []) {
            return $profile;
        }

        $profile->forceFill($changes)->save();

        return $profile->refresh();
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function fields(array $attributes): array
    {
        $fields = [];

        foreach (self::WRITABLE as $field) {
            if (! array_key_exists($field, $attributes)) {
                continue;
            }

            $fields[$field] = match ($field) {
                'default_language' => $this->language($attributes[$field]),
                'default_artist_id' => is_int($attributes[$field]) ? $attributes[$field] : null,
                'preferred_genres', 'preferred_moods', 'preferred_themes', 'avoided_terms' => $this->terms($attributes[$field]),
                default => $this->text($attributes[$field]),
            };
        }

        return $fields;
    }

    /**
     * @throws EditorialProfileException
     */
    private function name(mixed $value): string
    {
        $name = is_string($value) ? trim($value) : '';

        if ($name === '') {
            throw EditorialProfileException::nameRequired();
        }

        return mb_substr($name, 0, 255);
    }

    private function slug(string $name): string
    {
        $slug = Str::slug($name);

        // A name in a script `Str::slug` cannot transliterate produces an empty
        // slug, and an empty unique column is a collision waiting for the
        // second such imprint. The uuid prefix is ugly and correct.
        return $slug === '' ? 'profile-'.substr((string) Str::uuid7(), 0, 8) : mb_substr($slug, 0, 128);
    }

    /**
     * @throws EditorialProfileException
     */
    private function language(mixed $value): ?string
    {
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return null;
        }

        $code = is_string($value) ? strtolower(trim($value)) : '';

        if (preg_match('/^[a-z]{2,3}$/', $code) !== 1) {
            throw EditorialProfileException::languageNotACode();
        }

        return $code;
    }

    /**
     * @return list<string>
     */
    private function terms(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $terms = [];

        foreach ($value as $term) {
            if (! is_string($term) || trim($term) === '') {
                continue;
            }

            $trimmed = mb_substr(trim($term), 0, 128);

            // Deduplicated case-insensitively, for the reason a suggestion's
            // terms are: a list holding "Ambient" and "ambient" makes a prompt
            // repeat itself and a screen show the same word twice.
            if (! in_array(mb_strtolower($trimmed), array_map(mb_strtolower(...), $terms), true)) {
                $terms[] = $trimmed;
            }
        }

        return $terms;
    }

    private function text(mixed $value): ?string
    {
        $text = is_string($value) ? trim($value) : '';

        return $text === '' ? null : $text;
    }
}
