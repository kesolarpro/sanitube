<?php

declare(strict_types=1);

namespace Tests\Feature\Editorial;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use SaniTube\Artists\Models\Artist;
use SaniTube\Editorial\Exceptions\EditorialProfileException;
use SaniTube\Editorial\Models\EditorialProfile;
use SaniTube\Editorial\Services\WriteEditorialProfile;
use Tests\TestCase;

/**
 * EDI-001 — how one imprint wants its catalogue to sound and read.
 *
 * **The claim this file defends is that a label is data.** Everything specific
 * to an imprint — its language, its palette of genres, the words it does not
 * use, how a title should read — lives in a row, so a second imprint on the
 * same installation is a second row rather than a fork.
 *
 * And it is not an artist. An artist is a public credit; this is editorial
 * policy. One artist can be released under several profiles and one profile can
 * credit several artists, so the two cannot be the same row without making both
 * of those impossible.
 */
final class EditorialProfileTest extends TestCase
{
    use RefreshDatabase;

    // ------------------------------------------------------- a label is data

    #[Test]
    public function no_imprint_is_named_in_the_source(): void
    {
        // **The test this ticket exists for.** The boundary erodes the first
        // time somebody writes "our label always releases in French" into a
        // service, and it erodes invisibly: the code still works, for exactly
        // one installation.
        //
        // So the check is mechanical. Any real imprint name that ends up in
        // application code fails here, and the fix is a row.
        $names = ['mon refuge', 'monrefuge'];
        $offenders = [];

        foreach ($this->sourceFiles() as $file) {
            $contents = mb_strtolower((string) file_get_contents($file));

            foreach ($names as $name) {
                if (str_contains($contents, $name)) {
                    $offenders[] = sprintf('%s names an imprint', $file);
                }
            }
        }

        $this->assertSame([], $offenders, implode('; ', $offenders));
    }

    #[Test]
    public function two_imprints_live_side_by_side_on_one_installation(): void
    {
        // The practical consequence. If a label were code, this would be a
        // second deployment.
        $writer = $this->writer();

        $first = $writer->create([
            'name' => 'Quiet Rooms',
            'default_language' => 'fr',
            'preferred_genres' => ['Ambient', 'Modern Classical'],
        ]);

        $second = $writer->create([
            'name' => 'Loud Rooms',
            'default_language' => 'en',
            'preferred_genres' => ['Punk'],
        ]);

        $this->assertNotSame($first->slug, $second->slug);
        $this->assertSame('fr', $first->default_language);
        $this->assertSame('en', $second->fresh()?->default_language);
        $this->assertSame(2, EditorialProfile::query()->count());
    }

    // ------------------------------------------------- not an artist

    #[Test]
    public function a_profile_is_not_an_artist_and_the_artist_row_is_untouched(): void
    {
        $artist = Artist::factory()->create(['name' => 'Someone']);

        // Re-fetched on both sides. A freshly-created model's attribute array
        // differs from a loaded one in key order and in how booleans come back
        // from the driver, so comparing the two would fail on a row nothing
        // touched -- which is a test that reports a bug it did not find.
        $before = Artist::query()->findOrFail($artist->id)->getAttributes();

        $profile = $this->writer()->create([
            'name' => 'Quiet Rooms',
            'default_artist_id' => $artist->id,
            'preferred_moods' => ['calm'],
        ]);

        $this->assertSame($before, Artist::query()->findOrFail($artist->id)->getAttributes());
        $this->assertSame($artist->id, $profile->defaultArtist?->id);
    }

    #[Test]
    public function one_artist_may_be_released_under_more_than_one_profile(): void
    {
        // The relationship that folding these columns onto the artist row
        // would have made impossible.
        $artist = Artist::factory()->create();
        $writer = $this->writer();

        $writer->create(['name' => 'Quiet Rooms', 'default_artist_id' => $artist->id]);
        $writer->create(['name' => 'Loud Rooms', 'default_artist_id' => $artist->id]);

        $this->assertSame(2, EditorialProfile::query()->where('default_artist_id', $artist->id)->count());
    }

    #[Test]
    public function a_profile_without_a_default_artist_is_ordinary(): void
    {
        // An imprint that decides the credit per release, which is a normal
        // way to run one.
        $profile = $this->writer()->create(['name' => 'Quiet Rooms']);

        $this->assertNull($profile->default_artist_id);
        $this->assertNull($profile->defaultArtist);
    }

    // ------------------------------------------------------------- guidance

    #[Test]
    public function the_guidance_block_is_assembled_in_one_place(): void
    {
        // Assembled on the model rather than in whichever service is
        // prompting, so a second caller cannot assemble it differently and
        // produce quietly different suggestions from the same profile.
        $profile = $this->writer()->create([
            'name' => 'Quiet Rooms',
            'summary' => 'Slow instrumental music for late evenings.',
            'preferred_genres' => ['Ambient'],
            'preferred_moods' => ['calm', 'melancholy'],
            'avoided_terms' => ['party'],
            'title_guidance' => 'Titles are lowercase and never mention the season.',
        ]);

        $guidance = $profile->guidance();

        $this->assertIsString($guidance);
        $this->assertStringContainsString('Slow instrumental music', $guidance);
        $this->assertStringContainsString('Preferred genres: Ambient.', $guidance);
        $this->assertStringContainsString('Preferred moods: calm, melancholy.', $guidance);
        $this->assertStringContainsString('Titles are lowercase', $guidance);

        // Phrased as a prohibition rather than a preference: a model handed
        // "avoid: X" alongside "prefer: Y" treats both as equally optional.
        $this->assertStringContainsString('Do not use these words or themes: party.', $guidance);
    }

    #[Test]
    public function a_profile_that_says_nothing_produces_no_guidance_at_all(): void
    {
        // An empty guidance block is worse than none: it fills a prompt with
        // headings and no content, and a model reading "Preferred genres:"
        // with nothing after it will invent some.
        $profile = $this->writer()->create(['name' => 'Quiet Rooms']);

        $this->assertNull($profile->guidance());
    }

    #[Test]
    public function blank_terms_do_not_become_headings(): void
    {
        // Written **around** the service, deliberately. The writer already
        // strips blanks, so a test going through it proves only the writer --
        // and `guidance()` is called on whatever is in the row, including rows
        // a seeder, a migration or a future importer wrote. Its own filter is
        // the one that has to hold.
        $profile = EditorialProfile::query()->create([
            'name' => 'Quiet Rooms',
            'slug' => 'quiet-rooms',
            'preferred_genres' => ['   ', ''],
            'preferred_moods' => ['calm'],
        ]);

        $guidance = (string) $profile->guidance();

        $this->assertStringNotContainsString('Preferred genres', $guidance);
        $this->assertStringContainsString('Preferred moods: calm.', $guidance);
    }

    #[Test]
    public function the_writer_strips_blanks_before_they_are_ever_stored(): void
    {
        // The other half. Two filters, and each is tested where it acts.
        $profile = $this->writer()->create([
            'name' => 'Loud Rooms',
            'preferred_genres' => ['   ', '', 'Punk'],
        ]);

        $this->assertSame(['Punk'], $profile->preferred_genres);
    }

    // ------------------------------------------------------------- writing

    #[Test]
    public function terms_are_deduplicated_case_insensitively(): void
    {
        $profile = $this->writer()->create([
            'name' => 'Quiet Rooms',
            'preferred_genres' => ['Ambient', 'ambient', 'AMBIENT', 'Folk'],
        ]);

        $this->assertSame(['Ambient', 'Folk'], $profile->preferred_genres);
    }

    #[Test]
    public function a_default_language_must_be_a_code(): void
    {
        // Copied onto releases and read by the distribution export, where a
        // word instead of a key is a rejected delivery.
        try {
            $this->writer()->create(['name' => 'Quiet Rooms', 'default_language' => 'French']);
            $this->fail('A language that is a word must be refused.');
        } catch (EditorialProfileException $refused) {
            $this->assertSame('EDITORIAL_LANGUAGE_NOT_A_CODE', $refused->reason);
        }

        $this->assertSame(0, EditorialProfile::query()->count());
    }

    #[Test]
    public function a_profile_needs_a_name(): void
    {
        $this->expectException(EditorialProfileException::class);

        $this->writer()->create(['name' => '   ']);
    }

    #[Test]
    public function two_profiles_cannot_share_a_slug(): void
    {
        $writer = $this->writer();
        $writer->create(['name' => 'Quiet Rooms']);

        try {
            $writer->create(['name' => 'quiet rooms']);
            $this->fail('Two imprints with one slug is an ambiguity nothing can resolve later.');
        } catch (EditorialProfileException $refused) {
            $this->assertSame('EDITORIAL_SLUG_TAKEN', $refused->reason);
        }
    }

    #[Test]
    public function a_name_in_a_script_that_does_not_transliterate_still_gets_a_slug(): void
    {
        // An empty unique column is a collision waiting for the second such
        // imprint.
        $profile = $this->writer()->create(['name' => '静けさ']);

        $this->assertNotSame('', $profile->slug);
        $this->assertSame('静けさ', $profile->name);
    }

    #[Test]
    public function renaming_an_imprint_does_not_repoint_what_referred_to_it(): void
    {
        // The slug is how a production plan and a console command name a
        // profile. A slug that followed a rename would turn "rename this
        // imprint" into "orphan everything that mentioned it".
        $profile = $this->writer()->create(['name' => 'Quiet Rooms']);
        $slug = $profile->slug;

        $renamed = $this->writer()->update($profile, ['name' => 'Quieter Rooms']);

        $this->assertSame('Quieter Rooms', $renamed->name);
        $this->assertSame($slug, $renamed->slug);
    }

    #[Test]
    public function a_payload_cannot_set_what_it_was_not_offered(): void
    {
        // A closed writable list, so a form that grew an extra field cannot
        // reach a column through mass assignment.
        $profile = $this->writer()->create([
            'name' => 'Quiet Rooms',
            'is_active' => false,
            'slug' => 'something-else',
            'id' => 999,
        ]);

        $this->assertTrue($profile->is_active);
        $this->assertSame('quiet-rooms', $profile->slug);
        $this->assertNotSame(999, $profile->id);
    }

    #[Test]
    public function an_update_that_changes_nothing_writes_nothing(): void
    {
        $profile = $this->writer()->create(['name' => 'Quiet Rooms']);
        $before = $profile->updated_at?->toAtomString();

        // Compared as strings: two Carbon instances for the same moment are
        // equal and are not the same object, and `assertSame` on them fails
        // whatever the code did.
        $this->assertSame($before, $this->writer()->update($profile, [])->updated_at?->toAtomString());
    }

    #[Test]
    public function an_imprint_can_be_retired_without_being_deleted(): void
    {
        // Releases already made under it still name it, and deleting the row
        // would leave them describing an imprint that does not exist.
        $profile = $this->writer()->create(['name' => 'Quiet Rooms']);

        $retired = $this->writer()->update($profile, ['is_active' => false]);

        $this->assertFalse($retired->is_active);
        $this->assertSame(1, EditorialProfile::query()->count());
    }

    // ------------------------------------------------------- nothing refuses

    #[Test]
    public function a_profile_constrains_nothing(): void
    {
        // Preferences, never constraints. An editor who wants a track outside
        // the palette makes one; the profile is what a suggestion or a plan
        // starts from, not what any of them are limited to. Asserted by the
        // absence of any method that could refuse.
        $methods = get_class_methods(EditorialProfile::class);

        foreach ($methods as $method) {
            $this->assertStringNotContainsString('refuse', mb_strtolower($method));
            $this->assertStringNotContainsString('enforce', mb_strtolower($method));
            $this->assertStringNotContainsString('validate', mb_strtolower($method));
        }
    }

    // ------------------------------------------------------------ fixtures

    private function writer(): WriteEditorialProfile
    {
        return $this->app->make(WriteEditorialProfile::class);
    }

    /**
     * Every PHP file the application ships, excluding tests and vendor.
     *
     * @return list<string>
     */
    private function sourceFiles(): array
    {
        $files = [];

        foreach ([base_path('src'), base_path('app'), base_path('config'), base_path('lang')] as $directory) {
            /** @var iterable<\SplFileInfo> $found */
            $found = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory));

            foreach ($found as $file) {
                if ($file->isFile() && in_array($file->getExtension(), ['php', 'vue', 'ts'], true)) {
                    $files[] = $file->getPathname();
                }
            }
        }

        return $files;
    }
}
