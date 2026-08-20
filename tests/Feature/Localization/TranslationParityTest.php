<?php

declare(strict_types=1);

namespace Tests\Feature\Localization;

use PHPUnit\Framework\Attributes\Test;
use SaniTube\Localization\LocaleRegistry;
use Tests\TestCase;

/**
 * Every locale says everything, or CI says which one does not.
 *
 * The existing guard checks that each configured locale *has* a translation
 * file. That catches a locale added with no catalogue at all and nothing else:
 * a file present but missing half its keys renders as `ui.import.title` on the
 * screen, in production, for whoever reads the platform in that language.
 *
 * Six locales times several hundred keys is not something anybody verifies by
 * looking. It is exactly the kind of completeness a machine should be asked
 * for, and until this test there was nothing between "a key was added to
 * English" and "a Portuguese user sees a dotted identifier".
 *
 * **English is the reference** because it is the language every key is written
 * in first. A locale with *extra* keys is reported too: it is almost always a
 * key that was renamed in English and left behind everywhere else, which is
 * the same defect seen from the other side.
 */
final class TranslationParityTest extends TestCase
{
    /**
     * The files each locale ships. Named rather than globbed: a new catalogue
     * should be added here deliberately, so nobody discovers in production
     * that it was never checked.
     *
     * @var list<string>
     */
    private const CATALOGUES = ['ui', 'sanitube'];

    #[Test]
    public function every_locale_carries_every_key_english_does(): void
    {
        foreach (self::CATALOGUES as $catalogue) {
            $reference = $this->flatten($this->load('en', $catalogue));

            foreach ($this->locales() as $locale) {
                if ($locale === 'en') {
                    continue;
                }

                $missing = array_diff($reference, $this->flatten($this->load($locale, $catalogue)));

                $this->assertSame([], array_values($missing), sprintf(
                    'Locale [%s] is missing %d key(s) from [%s.php]: %s',
                    $locale,
                    count($missing),
                    $catalogue,
                    implode(', ', array_slice(array_values($missing), 0, 20)),
                ));
            }
        }
    }

    #[Test]
    public function no_locale_carries_a_key_english_has_dropped(): void
    {
        // Almost always a key renamed in English and left behind elsewhere —
        // the same defect as the test above, seen from the other side. Left
        // alone it accumulates until nobody can tell which entries are live.
        foreach (self::CATALOGUES as $catalogue) {
            $reference = $this->flatten($this->load('en', $catalogue));

            foreach ($this->locales() as $locale) {
                if ($locale === 'en') {
                    continue;
                }

                $extra = array_diff($this->flatten($this->load($locale, $catalogue)), $reference);

                $this->assertSame([], array_values($extra), sprintf(
                    'Locale [%s] carries %d key(s) [%s.php] no longer has in English: %s',
                    $locale,
                    count($extra),
                    $catalogue,
                    implode(', ', array_slice(array_values($extra), 0, 20)),
                ));
            }
        }
    }

    #[Test]
    public function no_translation_is_left_as_a_placeholder_marker(): void
    {
        // A marker shipped in a catalogue is worse than a missing key: the
        // missing key is loud, and this renders as though somebody meant it.
        //
        // **Case-sensitive and word-bounded, and that is not fussiness.** The
        // first version of this check was `assertStringNotContainsStringIgnoringCase('TODO')`
        // and it failed immediately on the Spanish for "all" — `todos` — in a
        // sentence that was perfectly finished. A guard that cries wolf in the
        // very languages it exists to protect is a guard somebody deletes.
        foreach ($this->locales() as $locale) {
            foreach (self::CATALOGUES as $catalogue) {
                foreach ($this->values($this->load($locale, $catalogue)) as $key => $value) {
                    $this->assertDoesNotMatchRegularExpression('/\b(TODO|FIXME|XXX|TRANSLATE ME)\b/', $value, sprintf(
                        'Locale [%s] leaves [%s] unfinished in [%s.php].',
                        $locale,
                        $key,
                        $catalogue,
                    ));
                }
            }
        }
    }

    // ----------------------------------------------------------- fixtures

    /**
     * @return list<string>
     */
    private function locales(): array
    {
        return $this->app->make(LocaleRegistry::class)->codes();
    }

    /**
     * @return array<string, mixed>
     */
    private function load(string $locale, string $catalogue): array
    {
        $path = lang_path($locale.'/'.$catalogue.'.php');

        $this->assertFileExists($path);

        /** @var array<string, mixed> $translations */
        $translations = require $path;

        return $translations;
    }

    /**
     * Every leaf key, dotted.
     *
     * @param  array<string, mixed>  $translations
     * @return list<string>
     */
    private function flatten(array $translations, string $prefix = ''): array
    {
        return array_keys($this->values($translations, $prefix));
    }

    /**
     * @param  array<string, mixed>  $translations
     * @return array<string, string>
     */
    private function values(array $translations, string $prefix = ''): array
    {
        $flat = [];

        foreach ($translations as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;

            if (is_array($value)) {
                $flat = array_merge($flat, $this->values($value, $path));

                continue;
            }

            $flat[$path] = (string) $value;
        }

        return $flat;
    }
}
