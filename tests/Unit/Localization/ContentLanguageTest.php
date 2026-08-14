<?php

declare(strict_types=1);

namespace Tests\Unit\Localization;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SaniTube\Localization\ContentLanguage;
use SaniTube\Localization\Exceptions\InvalidContentLanguageException;

final class ContentLanguageTest extends TestCase
{
    #[Test]
    public function it_accepts_iso_639_1_codes(): void
    {
        $this->assertSame('fr', ContentLanguage::fromCode('fr')->code);
        $this->assertSame('pt', ContentLanguage::fromCode('PT')->code);
    }

    #[Test]
    public function regional_variants_reduce_to_the_language(): void
    {
        // A recording has a language, not a market: pt-BR and pt-PT are the
        // same content language as far as DSP metadata is concerned.
        $this->assertSame('pt', ContentLanguage::fromCode('pt-BR')->code);
        $this->assertSame('pt', ContentLanguage::fromCode('pt_PT')->code);
    }

    #[Test]
    public function it_models_instrumental_unknown_and_multiple_with_standard_codes(): void
    {
        $this->assertSame('zxx', ContentLanguage::instrumental()->code);
        $this->assertSame('und', ContentLanguage::unknown()->code);
        $this->assertSame('mul', ContentLanguage::multiple()->code);

        $this->assertTrue(ContentLanguage::instrumental()->isInstrumental());
        $this->assertTrue(ContentLanguage::unknown()->isUnknown());
        $this->assertTrue(ContentLanguage::multiple()->isMultiple());
    }

    #[Test]
    public function special_codes_are_distinguishable_from_real_languages(): void
    {
        $this->assertTrue(ContentLanguage::fromCode('zxx')->isSpecial());
        $this->assertFalse(ContentLanguage::fromCode('de')->isSpecial());
    }

    #[Test]
    public function only_an_instrumental_forbids_lyrics(): void
    {
        $this->assertFalse(ContentLanguage::instrumental()->allowsLyrics());
        $this->assertTrue(ContentLanguage::unknown()->allowsLyrics());
        $this->assertTrue(ContentLanguage::fromCode('es')->allowsLyrics());
    }

    #[Test]
    #[DataProvider('invalidCodes')]
    public function it_rejects_values_that_are_not_language_codes(string $code): void
    {
        $this->expectException(InvalidContentLanguageException::class);

        ContentLanguage::fromCode($code);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidCodes(): iterable
    {
        yield 'empty' => [''];
        yield 'single letter' => ['f'];
        yield 'three letters that are not a special code' => ['fre'];
        yield 'digits' => ['12'];
        yield 'instrumental spelled out' => ['instrumental'];
    }

    #[Test]
    public function try_from_code_returns_null_instead_of_throwing(): void
    {
        $this->assertNull(ContentLanguage::tryFromCode(null));
        $this->assertNull(ContentLanguage::tryFromCode('nonsense'));
        $this->assertSame('it', ContentLanguage::tryFromCode('it')?->code);
    }

    #[Test]
    public function it_compares_by_value(): void
    {
        $this->assertTrue(ContentLanguage::fromCode('en')->equals(ContentLanguage::fromCode('en-GB')));
        $this->assertFalse(ContentLanguage::fromCode('en')->equals(ContentLanguage::fromCode('de')));
        $this->assertSame('en', (string) ContentLanguage::fromCode('en'));
    }
}
