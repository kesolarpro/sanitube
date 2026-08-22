<?php

declare(strict_types=1);

namespace SaniTube\Ui\Http\Requests\Editorial;

use Illuminate\Foundation\Http\FormRequest;

/**
 * An imprint's editorial policy, from a form.
 *
 * PROD-002. One request for creating and correcting, the same way the service
 * behind it is one class: the rules are identical in both directions and a
 * second copy of them drifts.
 *
 * **The slug is not a field.** It is derived from the name once and then
 * frozen, because it is how a production plan and a console command name a
 * profile — a slug that followed a rename would turn "rename this imprint"
 * into "orphan everything that mentioned it".
 *
 * The term lists are arrays of short strings, and the service deduplicates
 * them case-insensitively: a palette holding "Ambient" and "ambient" makes a
 * prompt repeat itself and a screen show the same word twice.
 *
 * `default_language` is two or three letters and validated as such rather than
 * against a closed list. A closed list of languages is a list that is wrong
 * for somebody, and this platform does not decide which languages a label may
 * work in.
 */
final class WriteProfileRequest extends FormRequest
{
    /**
     * The lists of short terms, named once so the rules and the reader agree.
     *
     * @var list<string>
     */
    public const TERM_LISTS = [
        'preferred_genres',
        'preferred_moods',
        'preferred_themes',
        'avoided_terms',
    ];

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = [
            'name' => [$this->isMethod('POST') ? 'required' : 'sometimes', 'string', 'min:1', 'max:255'],
            'summary' => ['nullable', 'string', 'max:2000'],
            'default_artist' => ['nullable', 'string', 'max:36'],
            'default_language' => ['nullable', 'string', 'regex:/^[A-Za-z]{2,3}$/'],
            'title_guidance' => ['nullable', 'string', 'max:2000'],
            'description_guidance' => ['nullable', 'string', 'max:2000'],
            // Only on correction. A profile is created active, because one
            // that arrived retired is one somebody has to remember to switch
            // on before it can be used for anything.
            'is_active' => ['nullable', 'boolean'],
        ];

        foreach (self::TERM_LISTS as $list) {
            // Bounded because these become part of a prompt. A palette of two
            // hundred genres is not a preference, and the cost of one is paid
            // per generation for as long as the profile exists.
            $rules[$list] = ['nullable', 'array', 'max:50'];
            $rules[$list.'.*'] = ['string', 'max:128'];
        }

        return $rules;
    }

    /**
     * The fields the writer understands, with absent ones left absent.
     *
     * Not named `attributes()`: that name belongs to `FormRequest`, where it
     * means the display names used in validation messages.
     *
     * @return array<string, mixed>
     */
    public function profileAttributes(): array
    {
        $validated = $this->validated();
        $fields = [];

        $simple = [
            'name',
            'summary',
            'default_language',
            'title_guidance',
            'description_guidance',
            'is_active',
            ...self::TERM_LISTS,
        ];

        foreach ($simple as $field) {
            if (array_key_exists($field, $validated)) {
                $fields[$field] = $validated[$field];
            }
        }

        return $fields;
    }

    public function defaultArtistUuid(): ?string
    {
        $uuid = $this->validated('default_artist');

        return is_string($uuid) && $uuid !== '' ? $uuid : null;
    }

    public function mentionsDefaultArtist(): bool
    {
        // "Not mentioned" and "cleared" are different intentions, and only the
        // second should null the column.
        return array_key_exists('default_artist', $this->validated());
    }
}
