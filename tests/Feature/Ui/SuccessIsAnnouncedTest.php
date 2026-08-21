<?php

declare(strict_types=1);

namespace Tests\Feature\Ui;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use SaniTube\Identity\Enums\UserRole;
use SaniTube\Localization\LocaleRegistry;
use Tests\TestCase;

/**
 * UI-006. A write that worked says so.
 *
 * **Thirty-six controllers were flashing a status code that nothing shared and
 * no screen read.** `->with('status', 'release.ready')` put a string in the
 * session; the Inertia middleware shared `success` and `error` and never
 * `status`; nothing sets `success` anywhere in the platform. So every
 * successful write in the interface — importing a batch, promoting a
 * candidate, saving credits, marking a release ready, and **handing a release
 * to a distributor, the one act that cannot be undone** — redirected in
 * silence, and an operator's only way to know it had worked was to go and look.
 *
 * Ten of the sentences existed. They had been written by earlier tickets, in
 * six languages, and shown to nobody: `ui.settings.saved` said "Saved." to an
 * empty room. The other twenty-six codes had no translation at all, so even
 * wired up they would have rendered a dotted identifier.
 *
 * The load-bearing test here is the first one. It reads every `with('status',
 * …)` in `src/` and demands a line for it in every locale — which is what makes
 * a *new* silent confirmation impossible rather than merely fixing the
 * thirty-six that existed.
 */
final class SuccessIsAnnouncedTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Every code a controller can flash has a sentence, in every language.
     */
    #[Test]
    public function every_flashed_code_has_a_line_in_every_locale(): void
    {
        $codes = $this->flashedCodes();

        // A scan that found nothing would pass every assertion it does not
        // make. There are dozens of these calls; a handful means the pattern
        // changed and this test stopped reading the code it exists to read.
        $this->assertGreaterThan(20, count($codes), 'The scan found almost no status codes, so it did not scan.');

        foreach ($this->locales() as $locale) {
            /** @var array<string, mixed> $ui */
            $ui = require base_path('lang/'.$locale.'/ui.php');

            /** @var array<string, string> $flash */
            $flash = (array) ($ui['flash'] ?? []);

            foreach ($codes as $code) {
                $this->assertArrayHasKey(
                    $code,
                    $flash,
                    sprintf(
                        'A controller flashes [%s] and locale [%s] has no sentence for it, so that write '
                            .'would confirm itself with a dotted identifier.',
                        $code,
                        $locale,
                    ),
                );

                $this->assertNotSame('', trim((string) $flash[$code]));
            }
        }
    }

    /**
     * And nothing sits in the catalogue that no controller flashes.
     *
     * The same defect from the other side: copy written for a confirmation
     * that was renamed, or never wired, is copy six translators maintain for a
     * message nobody will ever see.
     */
    #[Test]
    public function nothing_in_the_flash_catalogue_is_unreachable(): void
    {
        /** @var array<string, mixed> $ui */
        $ui = require base_path('lang/en/ui.php');

        /** @var array<string, string> $flash */
        $flash = (array) ($ui['flash'] ?? []);

        $codes = $this->flashedCodes();

        foreach (array_keys($flash) as $code) {
            $this->assertContains(
                $code,
                $codes,
                sprintf('[%s] is translated in six languages and no controller flashes it.', $code),
            );
        }
    }

    // ------------------------------------------------------- the wiring

    #[Test]
    public function the_code_reaches_the_browser(): void
    {
        $response = $this->actingAs($this->owner())
            ->withSession(['status' => 'release.ready'])
            ->get('/releases');

        $response->assertSuccessful();

        // Shared as a *code*. A controller that flashed English would hand a
        // Portuguese reader an English confirmation for the one thing they
        // most need to have understood.
        $response->assertInertia(
            fn ($page) => $page->where('flash.status', 'release.ready')
        );
    }

    #[Test]
    public function the_sentence_that_goes_with_it_reaches_the_browser_too(): void
    {
        $response = $this->actingAs($this->owner())
            ->withSession(['status' => 'release.ready'])
            ->get('/releases');

        // Read out of the payload rather than through the fluent helper: the
        // translation map is flat with *dotted* keys, and a helper that treats
        // a dot as nesting cannot address one.
        /** @var array{props: array{translations: array<string, string>}} $page */
        $page = $response->viewData('page');

        $this->assertSame(
            'Marked ready for distribution. It can no longer be changed in place.',
            $page['props']['translations']['ui.flash.release.ready'] ?? null,
        );
    }

    /**
     * A page nobody flashed anything into says nothing.
     */
    #[Test]
    public function an_ordinary_page_load_carries_no_confirmation(): void
    {
        $this->actingAs($this->owner())
            ->get('/releases')
            ->assertInertia(fn ($page) => $page->where('flash.status', null));
    }

    /**
     * The irreversible act, end to end.
     *
     * Named on its own because it is the one where silence costs most: a person
     * who does not know whether a release was handed over is a person about to
     * hand it over again.
     */
    #[Test]
    public function marking_a_release_ready_confirms_itself(): void
    {
        /** @var array<string, mixed> $ui */
        $ui = require base_path('lang/en/ui.php');

        /** @var array<string, string> $flash */
        $flash = (array) $ui['flash'];

        $this->assertArrayHasKey('distribution.submitted', $flash);
        $this->assertStringContainsString('cannot be taken back', $flash['distribution.submitted']);
    }

    // ------------------------------------------------------------ fixtures

    /**
     * Every status code any controller flashes.
     *
     * Read from the source rather than from a list kept beside it: a list that
     * can drift from the code is the thing this whole test exists because of.
     *
     * @return list<string>
     */
    private function flashedCodes(): array
    {
        $codes = [];

        foreach ($this->sourceFiles() as $file) {
            $source = (string) file_get_contents($file);

            // The expression, not just a literal: several call sites choose
            // between two codes with a ternary, and a scanner that only read
            // the simple form would quietly stop covering them.
            preg_match_all("/with\(\s*'status'\s*,(.*?)\)\s*;/s", $source, $calls);

            foreach ($calls[1] as $expression) {
                // A call site that translates server-side is a Blade screen —
                // the sign-in and installer pages, which render before Inertia
                // exists and read `session('status')` directly. Those flash a
                // finished sentence and have always shown it; this test is
                // about the interface, which flashes a code.
                if (str_contains($expression, '__(')) {
                    continue;
                }

                preg_match_all("/'([a-z][a-z0-9_]*(?:\.[a-z0-9_]+)+)'/", $expression, $found);

                foreach ($found[1] as $code) {
                    $codes[$code] = true;
                }
            }
        }

        $codes = array_keys($codes);
        sort($codes);

        return $codes;
    }

    /**
     * @return list<string>
     */
    private function sourceFiles(): array
    {
        $files = [];

        /** @var iterable<\SplFileInfo> $iterator */
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(base_path('src'), \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }

    /**
     * @return list<string>
     */
    private function locales(): array
    {
        return $this->app->make(LocaleRegistry::class)->codes();
    }

    private function owner(): User
    {
        $user = User::query()->create([
            'name' => 'Owner',
            'email' => 'owner-'.bin2hex(random_bytes(4)).'@example.test',
            'password' => 'a-long-enough-passphrase',
        ]);

        $user->forceFill(['role' => UserRole::Owner, 'is_active' => true])->save();

        return $user;
    }
}
