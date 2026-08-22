<?php

declare(strict_types=1);

namespace Tests\Feature\Ui;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use SaniTube\Identity\Enums\UserRole;
use SaniTube\Installer\Services\EnvironmentFile;
use SaniTube\Ui\Queries\SettingsQuery;
use SaniTube\Ui\Settings\WritableSettings;
use Tests\TestCase;

/**
 * The settings that are about the machine rather than about a provider.
 *
 * CFG-004. Five sections that did not exist — audio and media, the queue,
 * backup, production automation, system health — each of which was configured
 * by an environment variable an operator had no way to see, let alone change,
 * without a shell.
 *
 * **What is deliberately absent is the interesting part**, and each absence is
 * a different kind of danger:
 *
 *   - The **binary paths** are published and not writable. The platform
 *     executes whatever they name, so a form field here would turn a stolen
 *     admin session into arbitrary command execution on the host — a different
 *     order of damage from every other setting on the page.
 *   - The **queue connection** is published and not writable. Repointing it
 *     does not move the jobs already sitting in the old connection; they are
 *     simply never run again, silently, while the screen reports success.
 *   - The **backup destination** is writable and checked, here as well as at
 *     backup time. A backup inside the web root is the whole database,
 *     downloadable by anybody who guesses the filename.
 */
final class OperationalSettingsTest extends TestCase
{
    use RefreshDatabase;

    private string $sandbox;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sandbox = sys_get_temp_dir().'/sanitube-operational-'.bin2hex(random_bytes(6));
        mkdir($this->sandbox, 0755, true);

        file_put_contents($this->sandbox.'/.env', implode("\n", [
            'APP_KEY=base64:'.base64_encode(random_bytes(32)),
            'SANITUBE_BACKUP_KEEP=7',
            'SANITUBE_MAX_MASTER_BYTES=2147483648',
        ])."\n");

        $this->app->bind(EnvironmentFile::class, fn (): EnvironmentFile => new EnvironmentFile($this->sandbox.'/.env'));
    }

    protected function tearDown(): void
    {
        foreach ((array) glob($this->sandbox.'/*') as $file) {
            if (is_string($file)) {
                @unlink($file);
            }
        }

        @rmdir($this->sandbox);

        parent::tearDown();
    }

    // ------------------------------------------------------ what is on show

    #[Test]
    public function the_screen_carries_a_section_for_each_operational_concern(): void
    {
        $keys = [];

        /** @var list<array<string, mixed>> $sections */
        $sections = $this->app->make(SettingsQuery::class)->overview()['sections'];

        foreach ($sections as $section) {
            $keys[] = $section['key'];
        }

        foreach (['media', 'queue', 'backup', 'automation', 'system'] as $expected) {
            $this->assertContains($expected, $keys, sprintf('No [%s] section on the settings screen.', $expected));
        }
    }

    #[Test]
    public function the_upload_ceilings_are_shown_as_the_numbers_they_actually_are(): void
    {
        config(['assets.max_upload_bytes.AUDIO_MASTER' => 12345]);

        // UPL-004 was about a ceiling that was configured and invisible. A
        // screen that shows the promise is a screen an operator can reconcile
        // with what their host will carry.
        $this->assertContains('12345', $this->valuesIn('media'));
    }

    // ------------------------------------------------- what must not be set

    #[Test]
    public function an_executable_path_is_shown_and_can_never_be_written(): void
    {
        $writable = $this->app->make(WritableSettings::class);

        foreach (['SANITUBE_FFPROBE_PATH', 'SANITUBE_FPCALC_PATH'] as $variable) {
            // Visible, because somebody debugging a missing binary needs to
            // know what is being looked for.
            $this->assertContains($variable, $this->variablesIn('media'));

            // And unwritable, because the platform runs it.
            $this->assertNull($writable->find($variable), sprintf('[%s] can be written from a form.', $variable));
        }

        $this->actingAs($this->owner())
            ->patch('/settings', ['SANITUBE_FFPROBE_PATH' => '/tmp/not-ffprobe'])
            ->assertRedirect();

        // Not rejected — never heard of. An allow-list refuses by not
        // containing the key, which is a stronger refusal than a rule.
        $this->assertStringNotContainsString('/tmp/not-ffprobe', $this->environment());
        $this->assertStringNotContainsString('SANITUBE_FFPROBE_PATH', $this->environment());
    }

    #[Test]
    public function the_queue_connection_is_shown_and_can_never_be_switched_from_here(): void
    {
        $this->assertContains('QUEUE_CONNECTION', $this->variablesIn('queue'));
        $this->assertNull($this->app->make(WritableSettings::class)->find('QUEUE_CONNECTION'));

        $this->actingAs($this->owner())
            ->patch('/settings', ['QUEUE_CONNECTION' => 'redis'])
            ->assertRedirect();

        // Jobs already queued on the old connection would never run again, and
        // nothing on any screen would say so.
        $this->assertStringNotContainsString('QUEUE_CONNECTION', $this->environment());
    }

    // -------------------------------------------------------- backup safety

    #[Test]
    public function a_backup_destination_inside_the_web_root_is_refused(): void
    {
        foreach ([public_path(), public_path().'/backups', public_path().'/'] as $inside) {
            $this->actingAs($this->owner())
                ->patch('/settings', ['SANITUBE_BACKUP_PATH' => $inside])
                ->assertSessionHasErrors('SANITUBE_BACKUP_PATH');
        }

        // A backup there is the whole catalogue and every credential-shaped
        // value in the database, downloadable by anybody who guesses the name.
        $this->assertStringNotContainsString(public_path(), $this->environment());
    }

    #[Test]
    public function a_destination_outside_the_web_root_is_accepted(): void
    {
        $outside = storage_path('backups');

        $this->actingAs($this->owner())
            ->patch('/settings', ['SANITUBE_BACKUP_PATH' => $outside])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertStringContainsString('SANITUBE_BACKUP_PATH='.$outside, $this->environment());
    }

    #[Test]
    public function a_path_that_merely_starts_with_the_same_letters_is_not_inside_it(): void
    {
        // `public_html` next to `public` is a real cPanel layout, and a prefix
        // comparison without the separator would refuse a perfectly safe
        // directory.
        $sibling = rtrim(public_path(), '/').'_html/backups';

        $this->actingAs($this->owner())
            ->patch('/settings', ['SANITUBE_BACKUP_PATH' => $sibling])
            ->assertSessionHasNoErrors();
    }

    #[Test]
    public function the_backup_time_has_to_be_a_time(): void
    {
        $this->actingAs($this->owner())
            ->patch('/settings', ['SANITUBE_BACKUP_AT' => 'nightly'])
            ->assertSessionHasErrors('SANITUBE_BACKUP_AT');

        $this->actingAs($this->owner())
            ->patch('/settings', ['SANITUBE_BACKUP_AT' => '02:40'])
            ->assertSessionHasNoErrors();

        $this->assertStringContainsString('SANITUBE_BACKUP_AT=02:40', $this->environment());
    }

    // ----------------------------------------------------------- the bounds

    #[Test]
    public function a_disk_threshold_out_of_range_is_refused_rather_than_stored(): void
    {
        // A blocker of zero means "never block", which is the setting that
        // turns a full disk into silent data loss instead of a refusal.
        $this->actingAs($this->owner())
            ->patch('/settings', ['SANITUBE_DISK_BLOCKER_MB' => '0'])
            ->assertSessionHasErrors('SANITUBE_DISK_BLOCKER_MB');

        $this->actingAs($this->owner())
            ->patch('/settings', ['SANITUBE_DISK_BLOCKER_MB' => '512'])
            ->assertSessionHasNoErrors();
    }

    #[Test]
    public function a_claim_lease_shorter_than_a_minute_is_refused(): void
    {
        // A lease shorter than the work it covers means two workers take the
        // same occasion, and a supplier is asked twice for the same track.
        $this->actingAs($this->owner())
            ->patch('/settings', ['SANITUBE_PRODUCTION_CLAIM_LEASE_SECONDS' => '5'])
            ->assertSessionHasErrors('SANITUBE_PRODUCTION_CLAIM_LEASE_SECONDS');
    }

    // ----------------------------------------------------------- fixtures

    /**
     * @return array<string, mixed>
     */
    private function sectionNamed(string $key): array
    {
        /** @var list<array<string, mixed>> $sections */
        $sections = $this->app->make(SettingsQuery::class)->overview()['sections'];

        foreach ($sections as $section) {
            if ($section['key'] === $key) {
                return $section;
            }
        }

        $this->fail(sprintf('No [%s] section in the payload.', $key));
    }

    /**
     * @return list<string>
     */
    private function variablesIn(string $key): array
    {
        $variables = [];
        $section = $this->sectionNamed($key);

        foreach ([...(array) $section['settings'], ...(array) $section['secrets']] as $entry) {
            $variables[] = (string) $entry['variable'];
        }

        return $variables;
    }

    /**
     * @return list<string>
     */
    private function valuesIn(string $key): array
    {
        $values = [];

        foreach ((array) $this->sectionNamed($key)['settings'] as $entry) {
            $values[] = (string) ($entry['value'] ?? '');
        }

        return $values;
    }

    private function environment(): string
    {
        return (string) file_get_contents($this->sandbox.'/.env');
    }

    private function owner(): User
    {
        return User::factory()->create(['role' => UserRole::Owner, 'is_active' => true]);
    }
}
