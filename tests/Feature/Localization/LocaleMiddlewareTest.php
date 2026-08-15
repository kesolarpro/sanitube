<?php

declare(strict_types=1);

namespace Tests\Feature\Localization;

use PHPUnit\Framework\Attributes\Test;
use SaniTube\Localization\LocaleRegistry;
use Tests\TestCase;

final class LocaleMiddlewareTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->app['router']->middleware('web')->get('_test/locale', fn (): array => [
            'locale' => app()->getLocale(),
            'navigation' => __('sanitube.navigation.catalog'),
        ]);
    }

    #[Test]
    public function it_serves_the_default_locale_when_nothing_is_requested(): void
    {
        $this->get('_test/locale')->assertOk()->assertJson([
            'locale' => 'en',
            'navigation' => 'Catalog',
        ]);
    }

    #[Test]
    public function an_explicit_language_parameter_switches_the_interface(): void
    {
        $this->get('_test/locale?lang=fr')->assertOk()->assertJson([
            'locale' => 'fr',
            'navigation' => 'Catalogue',
        ]);
    }

    #[Test]
    public function an_explicit_choice_is_remembered_for_later_requests(): void
    {
        $this->get('_test/locale?lang=de')->assertOk()->assertJson(['locale' => 'de']);

        $this->get('_test/locale')->assertOk()->assertJson([
            'locale' => 'de',
            'navigation' => 'Katalog',
        ]);
    }

    #[Test]
    public function the_browser_language_is_honoured_when_nothing_was_chosen(): void
    {
        $this->withHeader('Accept-Language', 'es-ES,es;q=0.9,en;q=0.5')
            ->get('_test/locale')
            ->assertOk()
            ->assertJson(['locale' => 'es', 'navigation' => 'Catálogo']);
    }

    #[Test]
    public function an_unsupported_language_falls_back_instead_of_failing(): void
    {
        $this->withHeader('Accept-Language', 'ja-JP,ja;q=0.9')
            ->get('_test/locale')
            ->assertOk()
            ->assertJson(['locale' => 'en']);

        $this->get('_test/locale?lang=ja')->assertOk()->assertJson(['locale' => 'en']);
    }

    #[Test]
    public function every_configured_locale_ships_with_its_translation_file(): void
    {
        // A locale offered in the UI but missing its catalogue would render as
        // raw translation keys.
        foreach ($this->app->make(LocaleRegistry::class)->codes() as $code) {
            $this->assertFileExists(
                lang_path($code.'/sanitube.php'),
                sprintf('Locale [%s] is configured but has no translation file.', $code),
            );
        }
    }

    #[Test]
    public function no_translation_key_is_left_untranslated_across_locales(): void
    {
        // Every file in `lang/en`, not just `sanitube.php`. English is the
        // reference because it is where a key is written first; the gate is
        // that no other locale may lag behind it.
        //
        // Generalised deliberately: the original version named one file, so
        // the identity strings — and every UI file after them — would have
        // been invisible to it. A completeness check that covers one file is a
        // check that stops being true the moment somebody adds a second.
        $registry = $this->app->make(LocaleRegistry::class);
        $missing = [];

        foreach ($this->translationFiles() as $file) {
            /** @var array<string, mixed> $reference */
            $reference = require lang_path('en/'.$file);
            $expected = $this->flatten($reference);

            foreach ($registry->codes() as $code) {
                $path = lang_path($code.'/'.$file);

                if (! is_file($path)) {
                    $missing[] = sprintf('%s has no %s at all', $code, $file);

                    continue;
                }

                /** @var array<string, mixed> $translations */
                $translations = require $path;

                foreach (array_diff($expected, $this->flatten($translations)) as $key) {
                    $missing[] = sprintf('%s is missing %s.%s', $code, basename($file, '.php'), $key);
                }
            }
        }

        $this->assertSame([], $missing, implode('; ', $missing));
    }

    #[Test]
    public function no_locale_carries_a_key_english_does_not(): void
    {
        // The other direction, and it matters as much. A key that exists only
        // in French is a string nobody sees in English — usually a rename that
        // was applied to one file and forgotten in the rest, which leaves the
        // old key sitting there looking translated.
        $registry = $this->app->make(LocaleRegistry::class);
        $stray = [];

        foreach ($this->translationFiles() as $file) {
            /** @var array<string, mixed> $reference */
            $reference = require lang_path('en/'.$file);
            $expected = $this->flatten($reference);

            foreach ($registry->codes() as $code) {
                $path = lang_path($code.'/'.$file);

                if (! is_file($path)) {
                    continue;
                }

                /** @var array<string, mixed> $translations */
                $translations = require $path;

                foreach (array_diff($this->flatten($translations), $expected) as $key) {
                    $stray[] = sprintf('%s has %s.%s, which English does not', $code, basename($file, '.php'), $key);
                }
            }
        }

        $this->assertSame([], $stray, implode('; ', $stray));
    }

    /**
     * Every translation file English defines.
     *
     * @return list<string>
     */
    private function translationFiles(): array
    {
        return array_values(array_map(
            basename(...),
            array_filter((array) glob(lang_path('en/*.php')), is_string(...)),
        ));
    }

    /**
     * @param  array<string, mixed>  $translations
     * @return list<string>
     */
    private function flatten(array $translations, string $prefix = ''): array
    {
        $keys = [];

        foreach ($translations as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;

            if (is_array($value)) {
                $keys = array_merge($keys, $this->flatten($value, $path));

                continue;
            }

            $keys[] = $path;
        }

        return $keys;
    }
}
