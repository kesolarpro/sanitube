<?php

declare(strict_types=1);

namespace Tests\Unit\Assets;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SaniTube\Assets\Enums\AssetKind;
use SaniTube\Assets\Storage\AssetObjectKeys;
use SaniTube\Assets\Storage\OriginalFilename;

/**
 * Where bytes are addressed, and what an uploader cannot influence about it.
 *
 * The security property is not "traversal is stripped" but "traversal has
 * nowhere to go": a key is built from a server-generated UUID and a character
 * allowlist, so a hostile filename never reaches the address at all.
 */
final class AssetObjectKeysTest extends TestCase
{
    private const UUID = '0192f2b3-4c5d-7e6f-8a9b-0c1d2e3f4a5b';

    #[Test]
    public function a_key_is_built_from_the_uuid_not_the_filename(): void
    {
        $keys = new AssetObjectKeys;

        $key = $keys->canonical(AssetKind::AudioMaster, self::UUID, 'Final Master v3 (FIXED).wav');

        $this->assertSame('masters/'.self::UUID.'/original.wav', $key);
    }

    #[Test]
    public function two_files_with_the_same_name_do_not_collide(): void
    {
        // The whole reason names are not identities: everyone's file is called
        // master.wav.
        $keys = new AssetObjectKeys;

        $this->assertNotSame(
            $keys->canonical(AssetKind::AudioMaster, self::UUID, 'master.wav'),
            $keys->canonical(AssetKind::AudioMaster, '0192f2b3-0000-7e6f-8a9b-0c1d2e3f4a5b', 'master.wav'),
        );
    }

    #[Test]
    #[DataProvider('hostileFilenames')]
    public function a_hostile_filename_cannot_escape_its_prefix(string $filename): void
    {
        $key = (new AssetObjectKeys)->canonical(AssetKind::AudioMaster, self::UUID, $filename);

        $this->assertStringStartsWith('masters/'.self::UUID.'/', $key);
        $this->assertStringNotContainsString('..', $key);
        // prefix / uuid / object — exactly two separators, whatever arrived.
        $this->assertSame(2, substr_count($key, '/'), 'The key gained a directory level: '.$key);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function hostileFilenames(): iterable
    {
        yield 'parent traversal' => ['../../etc/passwd.wav'];
        yield 'absolute path' => ['/etc/shadow.wav'];
        yield 'windows traversal' => ['..\\..\\windows\\system32\\config.wav'];
        yield 'nested' => ['a/b/c/master.wav'];
        yield 'null byte' => ["master\0.wav"];
        yield 'newline' => ["master\n../evil.wav"];
        yield 'dot files' => ['...wav'];
        yield 'empty' => [''];
        yield 'only dots' => ['..'];
    }

    #[Test]
    public function the_extension_is_cosmetic_and_allowlisted(): void
    {
        $keys = new AssetObjectKeys;

        // Nothing outside [a-z0-9] survives, and an unusable extension simply
        // means no suffix — never an error, and never an escape.
        $this->assertStringEndsWith('/original', $keys->canonical(AssetKind::Document, self::UUID, 'contract'));
        $this->assertStringEndsWith('/original', $keys->canonical(AssetKind::Document, self::UUID, 'x.verylongextension'));
        $this->assertStringEndsWith('/original.jpg', $keys->canonical(AssetKind::Artwork, self::UUID, 'cover.JPG'));
    }

    #[Test]
    public function staging_is_a_reserved_prefix_that_no_canonical_key_uses(): void
    {
        $keys = new AssetObjectKeys;

        $staging = $keys->staging(self::UUID, 'master.wav');

        $this->assertTrue($keys->isStaging($staging));

        foreach (AssetKind::cases() as $kind) {
            $this->assertFalse(
                $keys->isStaging($keys->canonical($kind, self::UUID, 'file.wav')),
                sprintf('%s produces a key inside the staging prefix.', $kind->value),
            );
        }
    }

    #[Test]
    public function every_kind_has_a_prefix_of_its_own(): void
    {
        $keys = new AssetObjectKeys;

        foreach (AssetKind::cases() as $kind) {
            $prefix = $keys->prefixFor($kind);

            $this->assertMatchesRegularExpression('/^[a-z]+$/', $prefix);
        }
    }

    #[Test]
    public function the_staging_key_for_an_asset_is_stable(): void
    {
        // Determinism is what makes a retry converge instead of littering.
        $keys = new AssetObjectKeys;

        $this->assertSame(
            $keys->staging(self::UUID, 'master.wav'),
            $keys->staging(self::UUID, 'master.wav'),
        );
    }

    #[Test]
    public function the_original_name_is_kept_for_humans_but_stripped_of_its_path(): void
    {
        $this->assertSame('passwd.wav', OriginalFilename::sanitise('../../etc/passwd.wav'));
        $this->assertSame('config.wav', OriginalFilename::sanitise('..\\..\\windows\\config.wav'));
        $this->assertSame('master.wav', OriginalFilename::sanitise("mas\x00ter.wav"));
        $this->assertSame(OriginalFilename::FALLBACK, OriginalFilename::sanitise('   '));
        $this->assertSame(OriginalFilename::FALLBACK, OriginalFilename::sanitise('..'));
    }

    #[Test]
    public function a_very_long_name_is_bounded(): void
    {
        // The column is 255 characters and a name longer than that would be
        // truncated by the engine — silently on MySQL in non-strict mode.
        $name = OriginalFilename::sanitise(str_repeat('a', 5000).'.wav');

        $this->assertSame(OriginalFilename::MAX_LENGTH, mb_strlen($name));
    }
}
