<?php

declare(strict_types=1);

namespace Tests\Unit\Storage;

use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use ReflectionMethod;
use SaniTube\Storage\Contracts\StorageProvider;
use SaniTube\Storage\CredentialRedactor;
use SaniTube\Storage\Providers\FilesystemStorageProvider;
use SaniTube\Storage\Testing\InMemoryStorageProvider;
use Tests\TestCase;

/**
 * The two ways a private bucket stops being private: a URL that never expires,
 * and a credential that reaches a log.
 */
final class SignedUrlAndSecrecyTest extends TestCase
{
    #[Test]
    public function a_signed_url_stops_working_once_it_expires(): void
    {
        // Asserting that the query string contains the word "expires" would
        // pass against a URL that never expires. This checks the signature and
        // the deadline, so the test can actually fail.
        $provider = new InMemoryStorageProvider;
        $provider->put('masters/track/original.wav', 'audio');

        $url = $provider->temporaryUrl('masters/track/original.wav', Carbon::now()->addMinutes(15));

        $this->assertTrue($provider->isTemporaryUrlValid($url, Carbon::now()->addMinutes(14)));
        $this->assertFalse($provider->isTemporaryUrlValid($url, Carbon::now()->addMinutes(16)));
    }

    #[Test]
    public function a_tampered_signed_url_is_rejected(): void
    {
        $provider = new InMemoryStorageProvider;
        $provider->put('masters/track/original.wav', 'audio');
        $provider->put('masters/other/original.wav', 'other');

        $url = $provider->temporaryUrl('masters/track/original.wav', Carbon::now()->addMinutes(15));

        // Repointing a valid signature at a different object is the obvious
        // attack on a naive scheme.
        $repointed = str_replace('masters/track', 'masters/other', $url);

        $this->assertFalse($provider->isTemporaryUrlValid($repointed));
    }

    #[Test]
    public function extending_the_deadline_by_hand_invalidates_the_signature(): void
    {
        $provider = new InMemoryStorageProvider;
        $provider->put('masters/track/original.wav', 'audio');

        $url = $provider->temporaryUrl('masters/track/original.wav', Carbon::now()->addMinutes(1));
        $expires = (string) Carbon::now()->addMinutes(1)->getTimestamp();
        $extended = str_replace('expires='.$expires, 'expires='.Carbon::now()->addYear()->getTimestamp(), $url);

        $this->assertFalse($provider->isTemporaryUrlValid($extended));
    }

    /**
     * STO-005. Not "a master has no permanent URL" — *nothing* has one.
     *
     * The contract used to carry a `url()`, and the in-memory double refused
     * it, which made the test suite look like the guarantee was enforced. It
     * was not: the real provider returned whatever `Storage::url()` gave it,
     * and on an S3-compatible disk with a `url` key that is a working,
     * unexpiring public address. Nothing called it, so the divergence was
     * invisible — the safest possible implementation was the only one any test
     * ever exercised.
     *
     * Asked of the *contract*, so a provider added later inherits the
     * guarantee rather than having to remember it.
     */
    #[Test]
    public function no_storage_provider_can_be_asked_for_a_permanent_url(): void
    {
        $forbidden = ['url', 'publicUrl', 'permanentUrl'];

        $declared = array_map(
            static fn (ReflectionMethod $method): string => $method->getName(),
            (new ReflectionClass(StorageProvider::class))->getMethods(),
        );

        foreach ($forbidden as $method) {
            $this->assertNotContains(
                $method,
                $declared,
                sprintf('StorageProvider::%s() would be a permanent public address for a master.', $method),
            );
        }

        // And no implementation smuggles one back in behind the interface.
        foreach ([InMemoryStorageProvider::class, FilesystemStorageProvider::class] as $implementation) {
            foreach ($forbidden as $method) {
                $this->assertFalse(
                    method_exists($implementation, $method),
                    sprintf('%s::%s() exists outside the contract.', $implementation, $method),
                );
            }
        }
    }

    /**
     * The other half, and the one that made the method work in production:
     * the disk itself must not be told a public address, and must say private
     * out loud.
     *
     * Driven from the configured providers rather than a list of disk names,
     * so a provider added to `config/storage.php` is covered the day it lands.
     */
    #[Test]
    public function every_disk_sanitube_stores_on_is_private_and_declares_no_permanent_url(): void
    {
        /** @var array<string, array<string, mixed>> $providers */
        $providers = (array) config('storage.providers', []);
        $checked = 0;

        foreach ($providers as $provider => $definition) {
            $disk = (string) ($definition['disk'] ?? $provider);

            /** @var array<string, mixed> $configuration */
            $configuration = (array) config('filesystems.disks.'.$disk, []);

            $this->assertArrayNotHasKey(
                'url',
                $configuration,
                sprintf(
                    'The [%s] disk, which the %s provider stores on, declares a permanent public URL.',
                    $disk,
                    $provider,
                ),
            );

            // And says private out loud. Flysystem's default happens to be
            // private, which is not the same as SaniTube having decided it —
            // an undeclared default is a decision somebody else can change.
            $this->assertSame(
                'private',
                $configuration['visibility'] ?? null,
                sprintf(
                    'The [%s] disk, which the %s provider stores on, does not declare private visibility.',
                    $disk,
                    $provider,
                ),
            );

            // SEC-002. `serve => true` makes Laravel register a public
            // `GET /storage/{path}` *and* a `PUT` for any local disk, which
            // would put every master behind a signed link the application does
            // not control the lifetime of. The framework's own `local` disk
            // sets it; no disk SaniTube stores on may.
            $this->assertNotTrue(
                $configuration['serve'] ?? false,
                sprintf(
                    'The [%s] disk, which the %s provider stores on, is served over HTTP by the framework.',
                    $disk,
                    $provider,
                ),
            );

            $checked++;
        }

        // A loop over nothing passes every assertion it does not make.
        $this->assertGreaterThan(3, $checked);
    }

    #[Test]
    public function a_signed_url_follows_the_configured_application_url(): void
    {
        // No host is written down anywhere in the codebase; the installation's
        // own URL is the only source.
        config(['app.url' => 'https://music.example.test']);

        $provider = new InMemoryStorageProvider;
        $provider->put('masters/track/original.wav', 'audio');

        $url = $provider->temporaryUrl('masters/track/original.wav', Carbon::now()->addMinutes(5));

        $this->assertStringStartsWith('https://music.example.test/', $url);
    }

    // ------------------------------------------------------------ secrecy

    #[Test]
    public function a_configured_secret_never_survives_into_a_message(): void
    {
        config([
            'filesystems.disks.somewhere' => [
                'key' => 'AKIAEXAMPLEKEYVALUE',
                'secret' => 'sup3rs3cret-value-that-must-never-print',
            ],
        ]);

        $redactor = CredentialRedactor::fromDiskConfiguration();

        $message = 'Request signed with AKIAEXAMPLEKEYVALUE and sup3rs3cret-value-that-must-never-print failed.';
        $redacted = (string) $redactor->redact($message);

        $this->assertStringNotContainsString('AKIAEXAMPLEKEYVALUE', $redacted);
        $this->assertStringNotContainsString('sup3rs3cret-value-that-must-never-print', $redacted);
        $this->assertStringContainsString(CredentialRedactor::MASK, $redacted);
    }

    #[Test]
    public function a_failing_provider_reports_the_failure_with_the_secret_removed(): void
    {
        // The realistic path: an SDK exception quoting the signed request, on
        // its way to a terminal and then to a support thread.
        config(['filesystems.disks.somewhere' => ['secret' => 'sup3rs3cret-value-that-must-never-print']]);

        $provider = new InMemoryStorageProvider;
        $provider->failWith('SignatureDoesNotMatch using sup3rs3cret-value-that-must-never-print');

        $detail = (string) CredentialRedactor::fromDiskConfiguration()->redact($provider->healthCheck()->detail);

        $this->assertStringNotContainsString('sup3rs3cret-value-that-must-never-print', $detail);
    }

    #[Test]
    public function short_configuration_values_are_left_alone(): void
    {
        // Masking every configured string would corrupt the messages it is
        // supposed to make readable: "auto" is a region, not a secret.
        config(['filesystems.disks.somewhere' => ['key' => 'auto', 'secret' => 'us-east']]);

        $redactor = CredentialRedactor::fromDiskConfiguration();

        $this->assertSame(
            'Region auto in us-east could not be reached.',
            $redactor->redact('Region auto in us-east could not be reached.'),
        );
    }

    #[Test]
    public function an_unconfigured_installation_redacts_nothing(): void
    {
        config(['filesystems.disks' => []]);

        $this->assertSame('nothing to hide', CredentialRedactor::fromDiskConfiguration()->redact('nothing to hide'));
    }
}
