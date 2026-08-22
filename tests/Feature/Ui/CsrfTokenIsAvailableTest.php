<?php

declare(strict_types=1);

namespace Tests\Feature\Ui;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PHPUnit\Framework\Attributes\Test;
use SaniTube\Identity\Enums\UserRole;
use SaniTube\Storage\StorageManager;
use SaniTube\Storage\Testing\InMemoryStorageProvider;
use Tests\Support\Ui\EnforcesCsrfTokens;
use Tests\TestCase;

/**
 * UPL-005 — the contract between the layout and the JavaScript that reads it.
 *
 * `app.blade.php` had no `<meta name="csrf-token">`. Three screens read one:
 * the import screen, the single-file upload screen and the connection test on
 * the settings page. None of them uses Inertia for those requests, because
 * `router.post` cannot report upload progress and cannot be aborted, so each
 * builds an `XMLHttpRequest` or a `fetch` and signs it from that tag.
 *
 * With the tag gone, all three sent `X-CSRF-TOKEN:` empty, the middleware
 * refused them with 419, and **not one byte ever reached a controller**. The
 * evidence: a real MP3 of 4,700,000 bytes posted to `/ingestion/import/relay`
 * from a signed-in browser on the production host came back 419 with
 * `{"message": "CSRF token mismatch."}`. Nothing was written to `laravel.log`,
 * because a rendered 419 is an HTTP exception and not a reported one — so the
 * empty log read as "no problem" for as long as anybody looked at it.
 *
 * **Why no test caught it.** Feature tests go through Laravel's test client,
 * where CSRF verification does not run at all. Component tests mock the
 * network. Between the Blade template that publishes the token and the
 * JavaScript that reads it there was nothing — the one contract in the
 * application with a producer, a consumer, and no assertion joining them.
 *
 * That is what this file is. `deposit-refusals.spec.ts` holds the other side:
 * that the token, once published, is put in the header, and that a refusal is
 * read for what it is.
 */
final class CsrfTokenIsAvailableTest extends TestCase
{
    use EnforcesCsrfTokens;
    use RefreshDatabase;

    /** @var list<string> */
    private array $temporary = [];

    protected function tearDown(): void
    {
        foreach ($this->temporary as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }

        parent::tearDown();
    }

    // ------------------------------------------------------- the layout

    #[Test]
    public function the_layout_publishes_a_token_the_browser_can_read(): void
    {
        // The test that was missing. Rendered rather than read off disk: a
        // template can contain the tag and still not emit it.
        $html = $this->actingAs($this->user())->get('/')->assertOk()->getContent();

        $this->assertIsString($html);

        $token = $this->tokenIn($html);

        $this->assertNotNull($token, 'app.blade.php published no csrf-token meta tag.');
        $this->assertNotSame('', $token, 'The csrf-token meta tag is present but empty.');
        $this->assertSame(session()->token(), $token);
    }

    #[Test]
    public function every_screen_that_uploads_publishes_it(): void
    {
        // One layout, so one of these failing means all of them would — but
        // naming the three screens is what says *why* this matters, and the
        // day one of them gets its own template the list is already here.
        foreach (['/ingestion/import', '/assets/upload', '/settings'] as $screen) {
            $html = $this->actingAs($this->user())->get($screen)->assertOk()->getContent();

            $this->assertIsString($html);
            $this->assertNotSame(
                '',
                (string) $this->tokenIn($html),
                sprintf('[%s] renders without a usable csrf-token meta tag.', $screen),
            );
        }
    }

    // ----------------------------------------- the deposit, actually verified

    #[Test]
    public function a_relayed_deposit_carrying_the_published_token_is_accepted(): void
    {
        // The whole round trip, with the real middleware doing the real check:
        // read the token out of the rendered page exactly as the browser does,
        // then send it in the header exactly as the screen does.
        $this->enforceCsrfTokens();

        $store = $this->useInMemoryStore();
        $user = $this->user();

        $html = $this->actingAs($user)->get('/ingestion/import')->assertOk()->getContent();

        $this->assertIsString($html);

        $token = (string) $this->tokenIn($html);

        $this->assertNotSame('', $token);

        $response = $this->actingAs($user)->post(
            '/ingestion/import/relay',
            ['file' => $this->mp3('Armure de Lumière.mp3', frames: 200)],
            ['X-CSRF-TOKEN' => $token, 'Accept' => 'application/json'],
        );

        $response->assertOk();

        $reference = $response->json('reference');

        $this->assertIsString($reference);
        $this->assertContains($reference, $store->files('inbox/'));
    }

    #[Test]
    public function a_relayed_deposit_carrying_an_empty_token_is_refused_with_419(): void
    {
        // The production request, reproduced. This is what an installation
        // whose layout publishes nothing sends, and it must keep being
        // refused: the fix for UPL-005 was to supply the token, never to stop
        // checking it.
        $this->enforceCsrfTokens();
        $this->useInMemoryStore();

        $response = $this->actingAs($this->user())->post(
            '/ingestion/import/relay',
            ['file' => $this->mp3('Armure de Lumière.mp3', frames: 200)],
            ['X-CSRF-TOKEN' => '', 'Accept' => 'application/json'],
        );

        $response->assertStatus(419);

        // And it is refused *this* way: a message, no `code`. A screen reading
        // only `code` learns nothing here, which is why the reading now takes
        // the status into account. See `deposit-refusals.spec.ts`.
        $this->assertNull($response->json('code'));
        $this->assertIsString($response->json('message'));
    }

    #[Test]
    public function a_wrong_token_is_refused_as_firmly_as_no_token(): void
    {
        $this->enforceCsrfTokens();
        $this->useInMemoryStore();

        $this->actingAs($this->user())->post(
            '/ingestion/import/relay',
            ['file' => $this->mp3('Armure de Lumière.mp3', frames: 200)],
            ['X-CSRF-TOKEN' => 'a-token-from-somewhere-else', 'Accept' => 'application/json'],
        )->assertStatus(419);
    }

    #[Test]
    public function the_single_file_upload_and_the_connection_test_are_verified_too(): void
    {
        // The other two screens that sign their own requests. Both were broken
        // by the same missing tag, and both must still refuse an empty one.
        $this->enforceCsrfTokens();
        $this->useInMemoryStore();

        $user = $this->user();

        $this->actingAs($user)->post(
            '/assets/uploads/relay',
            ['kind' => 'AUDIO_MASTER', 'file' => $this->mp3('Armure de Lumière.mp3', frames: 200)],
            ['X-CSRF-TOKEN' => '', 'Accept' => 'application/json'],
        )->assertStatus(419);

        $this->actingAs($user)->post(
            '/settings/test',
            ['target' => 'storage'],
            ['X-CSRF-TOKEN' => '', 'Accept' => 'application/json'],
        )->assertStatus(419);
    }

    // ------------------------------------------------------- what leaks

    #[Test]
    public function a_refusal_names_no_path_and_no_secret(): void
    {
        // With `APP_DEBUG` on — the suite's default, and never a deployment's
        // — Laravel puts the exception class and the whole stack trace in the
        // body, and a stack trace is a list of absolute paths. So this is
        // asserted against the configuration production actually runs, which
        // is the configuration whose leaks would matter.
        config(['app.debug' => false]);

        $this->enforceCsrfTokens();
        $this->useInMemoryStore();

        $refusals = [
            // The framework's, unsigned.
            $this->actingAs($this->user())->post(
                '/ingestion/import/relay',
                ['file' => $this->mp3('Armure de Lumière.mp3', frames: 200)],
                ['X-CSRF-TOKEN' => '', 'Accept' => 'application/json'],
            ),
            // The application's own, signed and refused on the file itself.
            $this->actingAs($this->user())->post(
                '/ingestion/import/relay',
                ['file' => $this->text('notes.txt')],
                ['X-CSRF-TOKEN' => session()->token(), 'Accept' => 'application/json'],
            ),
        ];

        foreach ($refusals as $refusal) {
            $body = (string) $refusal->getContent();

            $this->assertStringNotContainsString(base_path(), $body);
            $this->assertStringNotContainsString(storage_path(), $body);
            $this->assertStringNotContainsString(sys_get_temp_dir(), $body);
            $this->assertStringNotContainsString((string) config('app.key'), $body);
            $this->assertStringNotContainsString(session()->token(), $body);
            // A stack trace names the file it was thrown from, and that is a
            // path whether or not it is one of the three above.
            $this->assertStringNotContainsString('vendor/laravel', $body);
        }
    }

    // ------------------------------------------------------------- helpers

    /** The token the layout published, or null when it published none. */
    private function tokenIn(string $html): ?string
    {
        if (preg_match('/<meta\s+name="csrf-token"\s+content="([^"]*)"/', $html, $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }

    private function useInMemoryStore(): InMemoryStorageProvider
    {
        $store = new InMemoryStorageProvider('memory');

        config(['storage.default' => 'memory']);
        $this->app->make(StorageManager::class)->register('memory', $store);

        return $store;
    }

    private function mp3(string $name, int $frames = 4500): UploadedFile
    {
        // ID3v2.3 header, then MPEG-1 Layer III frames at 44.1 kHz, so finfo
        // reads audio/mpeg from the bytes rather than from the name.
        $id3 = 'ID3'.chr(3).chr(0).chr(0).str_repeat(chr(0), 4);
        $frame = chr(0xFF).chr(0xFB).chr(0xB0).chr(0x00).str_repeat(chr(0), 1040);

        return $this->upload($name, $id3.str_repeat($frame, $frames));
    }

    private function text(string $name): UploadedFile
    {
        return $this->upload($name, "Not audio, and finfo will say so.\n");
    }

    private function upload(string $name, string $contents): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'sanitube-csrf-');

        self::assertIsString($path);
        file_put_contents($path, $contents);

        $this->temporary[] = $path;

        return new UploadedFile($path, $name, null, null, test: true);
    }

    private function user(UserRole $role = UserRole::Admin): User
    {
        return User::factory()->create(['role' => $role, 'is_active' => true]);
    }
}
