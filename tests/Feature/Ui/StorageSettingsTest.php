<?php

declare(strict_types=1);

namespace Tests\Feature\Ui;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use SaniTube\Identity\Enums\UserRole;
use SaniTube\Installer\Services\EnvironmentFile;
use SaniTube\Storage\ProviderConfiguration;
use SaniTube\Ui\Queries\SettingsQuery;
use SaniTube\Ui\Settings\WritableSettings;
use Tests\TestCase;

/**
 * The settings screen names the variables this installation actually reads.
 *
 * **STO-004 is a bug about obedience.** The screen was not wrong about the
 * state — it read credential presence from the right disk, so an R2 install
 * with credentials showed *configured* — it was wrong about the instruction.
 * It printed `AWS_ACCESS_KEY_ID` beside every S3-compatible provider, and the
 * r2 disk reads `R2_ACCESS_KEY_ID`. An operator setting up a fresh R2 bucket
 * would follow the screen, set the variable it named, watch "not configured"
 * come back unchanged, and have no way from any screen to discover that the
 * variable being read was one they had never been shown.
 *
 * Worse, SET-002 made that name writable. Typing a real R2 key into the field
 * the screen offered wrote it to `AWS_ACCESS_KEY_ID`, where it configured the
 * s3 disk — a disk this installation never touches — and left a live cloud
 * credential in a `.env` file for no reason at all.
 *
 * Four claims carry these tests:
 *
 *   - the screen names **this provider's** variables, and never another's;
 *   - the writable list agrees with the screen, provider by provider;
 *   - the names are not decoration: setting the named variable is what puts a
 *     value on the disk the provider stores through;
 *   - the vocabulary is closed, so configuration cannot introduce `url` and
 *     turn a private bucket into a permanent public address.
 */
final class StorageSettingsTest extends TestCase
{
    use RefreshDatabase;

    private string $sandbox;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sandbox = sys_get_temp_dir().'/sanitube-storage-settings-'.bin2hex(random_bytes(6));
        mkdir($this->sandbox, 0755, true);

        file_put_contents($this->sandbox.'/.env', "APP_DEBUG=false\n");

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

    // -------------------------------------------------- the reported names

    /**
     * The regression, stated plainly.
     */
    #[Test]
    public function an_r2_installation_is_told_the_r2_variables_and_never_amazons(): void
    {
        config(['storage.default' => 'r2']);

        $names = $this->storageVariables();

        $this->assertContains('R2_ACCESS_KEY_ID', $names);
        $this->assertContains('R2_SECRET_ACCESS_KEY', $names);
        $this->assertContains('R2_BUCKET', $names);
        $this->assertContains('R2_ENDPOINT', $names);

        foreach ($names as $name) {
            $this->assertStringStartsNotWith(
                'AWS_',
                $name,
                sprintf('[%s] is an Amazon variable on an R2 installation; the r2 disk reads none of them.', $name),
            );
        }
    }

    #[Test]
    public function a_backblaze_installation_is_told_the_backblaze_variables(): void
    {
        config(['storage.default' => 'b2']);

        $names = $this->storageVariables();

        $this->assertContains('B2_ACCESS_KEY_ID', $names);
        $this->assertContains('B2_SECRET_ACCESS_KEY', $names);
        $this->assertContains('B2_BUCKET', $names);
        $this->assertContains('B2_ENDPOINT', $names);
    }

    /**
     * Amazon still gets Amazon's, which is the half that was accidentally
     * right and has to stay right.
     */
    #[Test]
    public function an_amazon_installation_is_still_told_the_amazon_variables(): void
    {
        config(['storage.default' => 's3']);

        $names = $this->storageVariables();

        $this->assertContains('AWS_ACCESS_KEY_ID', $names);
        $this->assertContains('AWS_SECRET_ACCESS_KEY', $names);
        $this->assertContains('AWS_BUCKET', $names);

        // Amazon derives its endpoint from the region, so there is nothing to
        // ask for. A blank field labelled "endpoint" would be an invitation to
        // fill it in with something wrong.
        $this->assertNotContains('AWS_ENDPOINT', $names);
    }

    #[Test]
    public function a_local_installation_is_asked_for_no_credential_at_all(): void
    {
        config(['storage.default' => 'local']);

        $section = $this->storageSection();

        $this->assertSame([], $section['secrets']);

        // And is therefore complete. An install on a single server with a
        // local disk is a supported deployment, not a half-configured one.
        $this->assertTrue($section['complete']);
    }

    // ------------------------------------------------- screen against writer

    #[Test]
    public function the_screen_and_the_writer_name_the_same_storage_variables(): void
    {
        // Provider by provider, never as a union. Both lists depend on the
        // selection, so a union would let the s3 screen vouch for the R2 form
        // — which is the exact substitution that produced the bug.
        foreach ($this->app->make(ProviderConfiguration::class)->names() as $provider) {
            config(['storage.default' => $provider]);

            // Compared as name => is-it-a-secret, because agreeing on the
            // names and disagreeing on that is its own bug: a credential the
            // form treats as a plain setting is one an unrelated edit can
            // erase, and one the screen would publish the value of.
            //
            // Keyed rather than ordered: the screen puts addresses with the
            // settings and credentials with the secrets, while the form keeps
            // declaration order, and only membership is being claimed.
            $published = $this->publishedSecrecy();
            $writable = [];

            // Scoped by asking the provider which variables are *its own*,
            // rather than by excluding the SANITUBE_ prefix. The exclusion
            // stood in for "storage variables are the ones without it" — true
            // until CFG-001 made MAIL_* writable, at which point this test
            // failed for a reason that had nothing to do with storage. A set
            // derived from the thing it is about cannot drift that way.
            $storageVariables = array_map(
                static fn (object $field): string => (string) $field->variable,
                $this->app->make(ProviderConfiguration::class)->selectedFields(),
            );

            foreach ($this->app->make(WritableSettings::class)->all() as $setting) {
                if (! in_array($setting->variable, $storageVariables, true)) {
                    continue;
                }

                $writable[$setting->variable] = $setting->secret;
            }

            ksort($published);
            ksort($writable);

            $this->assertSame(
                $published,
                $writable,
                sprintf('The %s screen and the %s form disagree about the %s variables.', $provider, $provider, $provider),
            );
        }
    }

    /**
     * The bucket is held as closely as the key.
     *
     * Naming it tells somebody who has found a leaked key exactly what to
     * point it at, and the screen's whole discipline is that a credential is
     * two words and never a value.
     */
    #[Test]
    public function the_bucket_is_a_secret_and_no_credential_value_reaches_the_browser(): void
    {
        config([
            'storage.default' => 'r2',
            'filesystems.disks.r2.key' => 'the-key-itself',
            'filesystems.disks.r2.secret' => 'the-secret-itself',
            'filesystems.disks.r2.bucket' => 'the-bucket-itself',
        ]);

        $secrecy = $this->publishedSecrecy();

        $this->assertTrue($secrecy['R2_ACCESS_KEY_ID']);
        $this->assertTrue($secrecy['R2_SECRET_ACCESS_KEY']);
        $this->assertTrue($secrecy['R2_BUCKET']);

        // The address is not a credential and is published on purpose: an
        // installation pointed at the wrong account cannot be diagnosed from a
        // screen that refuses to say where it points.
        $this->assertFalse($secrecy['R2_ENDPOINT']);
        $this->assertFalse($secrecy['R2_DEFAULT_REGION']);

        $payload = json_encode($this->storageSection(), JSON_THROW_ON_ERROR);

        $this->assertStringNotContainsString('the-key-itself', $payload);
        $this->assertStringNotContainsString('the-secret-itself', $payload);
        $this->assertStringNotContainsString('the-bucket-itself', $payload);
    }

    // ------------------------------------------------------ the write lands

    #[Test]
    public function writing_an_r2_credential_writes_the_variable_the_r2_disk_reads(): void
    {
        config(['storage.default' => 'r2']);

        $this->actingAs($this->owner())
            ->patch('/settings', ['R2_ACCESS_KEY_ID' => 'a-real-r2-key'])
            ->assertRedirect();

        $this->assertStringContainsString('R2_ACCESS_KEY_ID=a-real-r2-key', $this->environment());
    }

    /**
     * The other half: the name that used to be offered is now refused.
     *
     * Not merely absent from the form — refused by the writer, because a form
     * field is a suggestion and the allow-list is the decision. Writing it
     * would leave a live cloud credential in a `.env` file configuring a disk
     * this installation never touches.
     */
    #[Test]
    public function an_r2_installation_cannot_write_amazons_variable(): void
    {
        config(['storage.default' => 'r2']);

        $this->actingAs($this->owner())
            ->patch('/settings', ['AWS_ACCESS_KEY_ID' => 'a-real-r2-key']);

        $this->assertStringNotContainsString('AWS_ACCESS_KEY_ID', $this->environment());
        $this->assertStringNotContainsString('a-real-r2-key', $this->environment());
    }

    #[Test]
    public function the_provider_itself_can_be_changed_but_only_to_one_that_exists(): void
    {
        $this->actingAs($this->owner())
            ->patch('/settings', ['SANITUBE_STORAGE_PROVIDER' => 'r2'])
            ->assertRedirect();

        $this->assertStringContainsString('SANITUBE_STORAGE_PROVIDER=r2', $this->environment());

        $this->actingAs($this->owner())
            ->patch('/settings', ['SANITUBE_STORAGE_PROVIDER' => 'dropbox'])
            ->assertSessionHasErrors('SANITUBE_STORAGE_PROVIDER');

        $this->assertStringNotContainsString('dropbox', $this->environment());
    }

    // -------------------------------------------------- the names are real

    /**
     * The declared variable is the one the disk reads. Not asserted by reading
     * the source, but by setting it and watching where it lands.
     *
     * This is the assertion that would have caught STO-004 the day it was
     * written, and the one that keeps a future provider from being added with
     * a plausible-looking name nothing reads.
     */
    #[Test]
    public function every_declared_variable_is_the_one_its_disk_actually_reads(): void
    {
        $providers = $this->app->make(ProviderConfiguration::class);
        $restore = [];
        $asserted = 0;

        try {
            foreach ($providers->names() as $provider) {
                $disk = $providers->disk($provider);

                foreach ($providers->fields($provider) as $field) {
                    $sentinel = 'sentinel-'.bin2hex(random_bytes(8));

                    $restore[$field->variable] = $_SERVER[$field->variable] ?? null;
                    $_SERVER[$field->variable] = $sentinel;

                    /** @var array{disks: array<string, array<string, mixed>>} $filesystems */
                    $filesystems = require base_path('config/filesystems.php');

                    $this->assertSame(
                        $sentinel,
                        $filesystems['disks'][$disk][$field->field->value] ?? null,
                        sprintf(
                            'The %s provider says %s configures its %s, but the %s disk does not read it.',
                            $provider,
                            $field->variable,
                            $field->field->value,
                            $disk,
                        ),
                    );

                    $asserted++;
                }
            }
        } finally {
            foreach ($restore as $variable => $value) {
                if ($value === null) {
                    unset($_SERVER[$variable]);

                    continue;
                }

                $_SERVER[$variable] = $value;
            }
        }

        // A loop over nothing passes every assertion it does not make.
        $this->assertGreaterThan(8, $asserted);
    }

    // ------------------------------------------------------ the closed list

    /**
     * Configuration may not introduce a public URL.
     *
     * `url` on a Laravel disk is a permanent, unexpiring public address for
     * every object on it, and SaniTube reaches audio through signed links that
     * expire. If the settings screen built its fields from whatever a provider
     * declared, a config edit — or a copied provider block — would put that
     * key in front of an operator as a normal-looking field.
     */
    #[Test]
    public function a_provider_declaring_a_public_url_is_ignored_rather_than_offered(): void
    {
        // Declared *alone*, with no legitimate field beside them. An earlier
        // version of this test listed `endpoint` first and proved nothing: a
        // dropped-by-vocabulary key and a dropped-by-duplicate key look the
        // same from outside, so the assertion passed even with the vocabulary
        // opened. Nothing here can be mistaken for a duplicate.
        config([
            'storage.default' => 'r2',
            'storage.providers.r2.settings' => [
                'url' => 'R2_URL',
                'visibility' => 'R2_VISIBILITY',
            ],
        ]);

        $this->assertSame(
            ['R2_ACCESS_KEY_ID', 'R2_SECRET_ACCESS_KEY', 'R2_BUCKET'],
            $this->storageVariables(),
            'A key outside the vocabulary was rendered as a field.',
        );

        $writable = $this->app->make(WritableSettings::class)->variables();

        $this->assertNotContains('R2_URL', $writable);
        $this->assertNotContains('R2_VISIBILITY', $writable);

        // And beside a real one, it is still the real one that survives.
        config(['storage.providers.r2.settings' => [
            'url' => 'R2_URL',
            'endpoint' => 'R2_ENDPOINT',
        ]]);

        $names = $this->storageVariables();

        $this->assertContains('R2_ENDPOINT', $names);
        $this->assertNotContains('R2_URL', $names);
    }

    /**
     * The same part named twice is one field, not two.
     *
     * A provider block is a thing people copy, and a `region` left in both
     * groups is the obvious way to get one. Two fields for one variable is not
     * cosmetic: the screen would show it once as a credential and once as an
     * address, which is a contradiction about whether its value is safe to
     * publish.
     */
    #[Test]
    public function a_part_declared_in_both_groups_is_offered_once(): void
    {
        config([
            'storage.default' => 'r2',
            'storage.providers.r2.credentials' => [
                'key' => 'R2_ACCESS_KEY_ID',
                'secret' => 'R2_SECRET_ACCESS_KEY',
                'bucket' => 'R2_BUCKET',
            ],
            'storage.providers.r2.settings' => [
                'bucket' => 'R2_BUCKET_AGAIN',
                'endpoint' => 'R2_ENDPOINT',
            ],
        ]);

        $names = $this->storageVariables();

        $this->assertSame(
            ['R2_ENDPOINT', 'R2_ACCESS_KEY_ID', 'R2_SECRET_ACCESS_KEY', 'R2_BUCKET'],
            $names,
        );

        // The first declaration wins, so a part cannot be quietly re-pointed
        // at a second variable by appending to the block.
        $this->assertNotContains('R2_BUCKET_AGAIN', $names);
    }

    /**
     * A provider nobody has written an adapter for asks for nothing, and says
     * so rather than showing a form that configures a service that is not
     * there.
     */
    #[Test]
    public function an_unknown_provider_is_reported_as_unknown_and_asks_for_nothing(): void
    {
        config(['storage.default' => 'nowhere']);

        $section = $this->storageSection();

        $this->assertFalse($section['known']);
        $this->assertFalse($section['complete']);
        $this->assertSame([], $this->storageVariables());
    }

    // ------------------------------------------------------------ fixtures

    /**
     * The storage section as the screen publishes it.
     *
     * @return array<string, mixed>
     */
    private function storageSection(): array
    {
        /** @var list<array<string, mixed>> $sections */
        $sections = $this->app->make(SettingsQuery::class)->overview()['sections'];

        foreach ($sections as $section) {
            if ($section['key'] === 'storage') {
                return $section;
            }
        }

        $this->fail('The settings screen published no storage section.');
    }

    /**
     * The provider-specific variables on it, in order.
     *
     * `SANITUBE_*` is excluded throughout: the TTL and the provider selector
     * belong to SaniTube rather than to whichever service holds the masters,
     * and they are the same on every installation.
     *
     * @return list<string>
     */
    private function storageVariables(): array
    {
        $section = $this->storageSection();
        $names = [];

        foreach ([...(array) $section['settings'], ...(array) $section['secrets']] as $entry) {
            $variable = (string) $entry['variable'];

            if (str_starts_with($variable, 'SANITUBE_')) {
                continue;
            }

            $names[] = $variable;
        }

        return $names;
    }

    /**
     * The provider's variables and whether each is held secret.
     *
     * @return array<string, bool>
     */
    private function publishedSecrecy(): array
    {
        $section = $this->storageSection();
        $secrecy = [];

        foreach ([...(array) $section['settings'], ...(array) $section['secrets']] as $entry) {
            $variable = (string) $entry['variable'];

            if (str_starts_with($variable, 'SANITUBE_')) {
                continue;
            }

            $secrecy[$variable] = (bool) $entry['secret'];
        }

        return $secrecy;
    }

    private function environment(): string
    {
        return (string) file_get_contents($this->sandbox.'/.env');
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
