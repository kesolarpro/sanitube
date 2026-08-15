<?php

declare(strict_types=1);

namespace Tests\Unit\Storage;

use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use SaniTube\Storage\CredentialRedactor;
use SaniTube\Storage\Exceptions\StorageOperationFailed;
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

    #[Test]
    public function a_private_bucket_has_no_permanent_url(): void
    {
        $provider = new InMemoryStorageProvider;
        $provider->put('masters/track/original.wav', 'audio');

        $this->expectException(StorageOperationFailed::class);
        $this->expectExceptionMessage('cannot produce a public URL');

        $provider->url('masters/track/original.wav');
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
