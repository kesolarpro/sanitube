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
        $registry = $this->app->make(LocaleRegistry::class);
        $reference = require lang_path('en/sanitube.php');
        $expected = $this->flatten($reference);

        foreach ($registry->codes() as $code) {
            $actual = $this->flatten(require lang_path($code.'/sanitube.php'));

            $this->assertSame(
                [],
                array_values(array_diff($expected, $actual)),
                sprintf('Locale [%s] is missing translation keys.', $code),
            );
        }
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
