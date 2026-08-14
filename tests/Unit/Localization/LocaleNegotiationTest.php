<?php

declare(strict_types=1);

namespace Tests\Unit\Localization;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SaniTube\Localization\Exceptions\UnsupportedLocaleException;
use SaniTube\Localization\LocaleNegotiator;
use SaniTube\Localization\LocaleRegistry;
use SaniTube\Localization\UiLocale;

final class LocaleNegotiationTest extends TestCase
{
    private function registry(string $default = 'en', string $fallback = 'en'): LocaleRegistry
    {
        return new LocaleRegistry([
            'en' => ['name' => 'English', 'native' => 'English'],
            'fr' => ['name' => 'French', 'native' => 'Français'],
            'de' => ['name' => 'German', 'native' => 'Deutsch'],
            'ar' => ['name' => 'Arabic', 'native' => 'العربية', 'direction' => 'rtl'],
        ], $default, $fallback);
    }

    #[Test]
    public function it_normalises_regional_tags_to_the_supported_locale(): void
    {
        $registry = $this->registry();

        $this->assertSame('fr', $registry->get('fr-CA')->code);
        $this->assertSame('fr', $registry->get('FR_ca')->code);
        $this->assertTrue($registry->isSupported('de-AT'));
    }

    #[Test]
    public function right_to_left_locales_are_supported_by_configuration_alone(): void
    {
        $this->assertTrue($this->registry()->get('ar')->isRightToLeft());
        $this->assertFalse($this->registry()->get('en')->isRightToLeft());
    }

    #[Test]
    public function an_unsupported_locale_is_reported_with_the_supported_list(): void
    {
        $this->expectException(UnsupportedLocaleException::class);
        $this->expectExceptionMessage('en, fr, de, ar');

        $this->registry()->get('ja');
    }

    #[Test]
    public function a_misconfigured_default_degrades_instead_of_breaking_the_application(): void
    {
        // Someone sets APP_LOCALE=ja without adding translations: the UI must
        // still render, in the fallback locale.
        $registry = $this->registry(default: 'ja', fallback: 'fr');

        $this->assertSame('fr', $registry->default()->code);
    }

    #[Test]
    public function an_explicit_choice_wins_over_everything_else(): void
    {
        $negotiator = new LocaleNegotiator($this->registry());

        $locale = $negotiator->negotiate(explicit: 'de', stored: 'fr', acceptLanguage: 'en-US');

        $this->assertSame('de', $locale->code);
    }

    #[Test]
    public function a_stored_preference_wins_over_the_browser_header(): void
    {
        $negotiator = new LocaleNegotiator($this->registry());

        $locale = $negotiator->negotiate(stored: 'fr', acceptLanguage: 'en-US,en;q=0.9');

        $this->assertSame('fr', $locale->code);
    }

    #[Test]
    public function it_honours_accept_language_quality_values(): void
    {
        $negotiator = new LocaleNegotiator($this->registry());

        $locale = $negotiator->fromAcceptLanguage('ja;q=0.9, de;q=0.8, fr;q=0.95');

        $this->assertSame('fr', $locale?->code);
    }

    #[Test]
    public function equal_quality_values_keep_header_order(): void
    {
        $negotiator = new LocaleNegotiator($this->registry());

        $this->assertSame('de', $negotiator->fromAcceptLanguage('de, fr')?->code);
        $this->assertSame('fr', $negotiator->fromAcceptLanguage('fr, de')?->code);
    }

    #[Test]
    public function a_zero_quality_language_is_explicitly_refused(): void
    {
        $negotiator = new LocaleNegotiator($this->registry());

        $this->assertNull($negotiator->fromAcceptLanguage('fr;q=0'));
    }

    #[Test]
    public function an_unparsable_header_falls_through_to_the_default(): void
    {
        $negotiator = new LocaleNegotiator($this->registry(default: 'en'));

        $this->assertSame('en', $negotiator->negotiate(acceptLanguage: '*;q=0.5, ;;;')->code);
        $this->assertSame('en', $negotiator->negotiate()->code);
    }

    #[Test]
    public function a_stale_stored_preference_is_ignored_rather_than_rejected(): void
    {
        // A locale removed from the configuration must not lock a user out.
        $negotiator = new LocaleNegotiator($this->registry(default: 'en'));

        $this->assertSame('en', $negotiator->negotiate(stored: 'ja')->code);
    }

    #[Test]
    public function header_negotiation_can_be_switched_off(): void
    {
        $negotiator = new LocaleNegotiator($this->registry(default: 'en'), useAcceptLanguage: false);

        $this->assertSame('en', $negotiator->negotiate(acceptLanguage: 'fr')->code);
    }

    #[Test]
    public function it_rejects_a_malformed_locale_code_in_configuration(): void
    {
        $this->expectException(UnsupportedLocaleException::class);

        UiLocale::fromArray('1234', []);
    }
}
