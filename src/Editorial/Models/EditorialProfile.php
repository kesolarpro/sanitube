<?php

declare(strict_types=1);

namespace SaniTube\Editorial\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use SaniTube\Artists\Models\Artist;
use SaniTube\Contributors\Models\Contributor;
use SaniTube\Foundation\Concerns\HasPublicUuid;

/**
 * How one imprint wants its catalogue to sound and read.
 *
 * **Not an Artist, and deliberately not part of one.** An {@see Artist} is a
 * public credit — the name printed on a release — and this is a set of
 * editorial preferences: which languages, which genres, what to avoid, how a
 * title should read. One artist can be released under several profiles and one
 * profile can credit several artists, so folding these fields onto the artist
 * row would make both of those impossible and would put editorial policy in a
 * table that distribution reads as identity.
 *
 * It is not a {@see Contributor} either. A contributor is a
 * person or company with rights; nothing here has rights.
 *
 * **Everything about a particular label lives in these rows, never in code.**
 * That is the whole point of the table: an imprint's language, its palette of
 * genres, the words it does not use are *data*, so a second imprint on the same
 * installation is a second row and not a fork. `no_imprint_is_named_in_the_
 * source` asserts it, because this is exactly the boundary that erodes the
 * first time somebody hardcodes "our label always releases in French".
 *
 * A profile is a set of **preferences, never constraints.** Nothing here
 * refuses anything: an editor who wants a track outside the palette makes one,
 * and the profile is what a suggestion, a plan or a proposal starts from rather
 * than what any of them are limited to.
 *
 * @property int $id
 * @property string $uuid
 * @property string $name
 * @property string $slug
 * @property string|null $summary
 * @property int|null $default_artist_id
 * @property string|null $default_language
 * @property list<string>|null $preferred_genres
 * @property list<string>|null $preferred_moods
 * @property list<string>|null $preferred_themes
 * @property list<string>|null $avoided_terms
 * @property string|null $title_guidance
 * @property string|null $description_guidance
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class EditorialProfile extends Model
{
    use HasPublicUuid;

    protected $table = 'editorial_profiles';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'preferred_genres' => 'array',
            'preferred_moods' => 'array',
            'preferred_themes' => 'array',
            'avoided_terms' => 'array',
            'is_active' => 'boolean',
        ];
    }

    /**
     * The credit this imprint usually releases under, if it has a usual one.
     *
     * Nullable, and a preference rather than a rule. A profile with no default
     * artist is an imprint that decides per release, which is an ordinary way
     * to run one.
     *
     * @return BelongsTo<Artist, $this>
     */
    public function defaultArtist(): BelongsTo
    {
        return $this->belongsTo(Artist::class, 'default_artist_id');
    }

    /**
     * What this profile suggests, as one block of guidance.
     *
     * Assembled here rather than in whichever service is prompting, so a second
     * caller cannot assemble it differently and produce quietly different
     * suggestions from the same profile.
     *
     * **Returns null when the profile says nothing useful.** An empty guidance
     * block is worse than none: it fills a prompt with headings and no content,
     * and a model reading "Preferred genres:" with nothing after it will invent
     * some.
     */
    public function guidance(): ?string
    {
        $lines = [];

        if (is_string($this->summary) && trim($this->summary) !== '') {
            $lines[] = trim($this->summary);
        }

        foreach ([
            'Preferred genres' => $this->preferred_genres,
            'Preferred moods' => $this->preferred_moods,
            'Recurring themes' => $this->preferred_themes,
        ] as $label => $terms) {
            $terms = $this->terms($terms);

            if ($terms !== []) {
                $lines[] = sprintf('%s: %s.', $label, implode(', ', $terms));
            }
        }

        $avoided = $this->terms($this->avoided_terms);

        if ($avoided !== []) {
            // Phrased as a prohibition rather than a preference, because a
            // model handed "avoid: X" alongside "prefer: Y" treats both as
            // suggestions of equal weight.
            $lines[] = sprintf('Do not use these words or themes: %s.', implode(', ', $avoided));
        }

        foreach ([$this->title_guidance, $this->description_guidance] as $guidance) {
            if (is_string($guidance) && trim($guidance) !== '') {
                $lines[] = trim($guidance);
            }
        }

        return $lines === [] ? null : implode("\n", $lines);
    }

    /**
     * @return list<string>
     */
    private function terms(mixed $terms): array
    {
        if (! is_array($terms)) {
            return [];
        }

        $clean = [];

        foreach ($terms as $term) {
            if (is_string($term) && trim($term) !== '') {
                $clean[] = trim($term);
            }
        }

        return $clean;
    }
}
