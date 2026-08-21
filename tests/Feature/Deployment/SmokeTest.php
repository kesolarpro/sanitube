<?php

declare(strict_types=1);

namespace Tests\Feature\Deployment;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use SaniTube\Deployment\Smoke\SmokeCheck;
use SaniTube\Deployment\Smoke\SmokeTestRunner;
use Tests\TestCase;

/**
 * The smoke test's verdicts, proven against faked HTTP.
 *
 * DEP-014. The command exists to be run against a real deployment, which CI
 * cannot be; what CI *can* hold is the verdict logic — that a served `.env`
 * fails however cheerful its status line, that an unauthenticated dashboard
 * answering 200 is treated as the fire it is, and that an unreachable host
 * is a failure whose message names nothing about the address.
 */
final class SmokeTest extends TestCase
{
    private string $publicRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->publicRoot = storage_path('framework/testing/smoke-'.uniqid());
        mkdir($this->publicRoot.'/build', 0755, true);
    }

    protected function tearDown(): void
    {
        @unlink($this->publicRoot.'/build/manifest.json');
        @rmdir($this->publicRoot.'/build');
        @rmdir($this->publicRoot);

        parent::tearDown();
    }

    #[Test]
    public function a_healthy_installation_passes_every_check(): void
    {
        $this->manifest(['resources/js/app.ts' => ['file' => 'assets/app-abc.js']]);

        Http::fake([
            'https://label.example/login' => Http::response('form', 200),
            'https://label.example/' => Http::response('', 302, ['Location' => '/login']),
            'https://label.example/up' => Http::response('', 200),
            'https://label.example/build/assets/app-abc.js' => Http::response('js', 200),
            'https://label.example/*' => Http::response('', 404),
        ]);

        $checks = $this->smoke();

        foreach ($checks as $check) {
            $this->assertNotSame(SmokeCheck::FAILED, $check->outcome, $check->name.': '.$check->detail);
        }
    }

    #[Test]
    public function a_served_env_file_fails_whatever_its_status_line_says(): void
    {
        $this->manifest(['a' => ['file' => 'assets/a.js']]);

        Http::fake([
            'https://label.example/.env' => Http::response('APP_KEY=base64:leaked', 200),
            'https://label.example/*' => Http::response('', 404),
        ]);

        $failures = $this->failures();

        $this->assertArrayHasKey('forbidden:/.env', $failures);
        $this->assertStringContainsString('must never leave the machine', $failures['forbidden:/.env']);
    }

    #[Test]
    public function an_unauthenticated_dashboard_answering_200_is_a_fire_not_a_pass(): void
    {
        $this->manifest(['a' => ['file' => 'assets/a.js']]);

        Http::fake([
            'https://label.example/' => Http::response('the catalogue', 200),
            'https://label.example/*' => Http::response('', 404),
        ]);

        $this->assertArrayHasKey('auth_redirect', $this->failures());
    }

    #[Test]
    public function a_missing_frontend_is_a_skip_with_instructions_not_a_failure(): void
    {
        // No manifest written: a Core-only install that has not fetched the
        // artifact yet is incomplete, not broken.
        Http::fake(['https://label.example/*' => Http::response('', 404)]);

        $checks = $this->smoke();

        foreach ($checks as $check) {
            if ($check->name === 'frontend_asset') {
                $this->assertSame(SmokeCheck::SKIPPED, $check->outcome);
                $this->assertStringContainsString('install the frontend', $check->detail);

                return;
            }
        }

        $this->fail('No frontend_asset check ran.');
    }

    #[Test]
    public function a_bundle_the_server_does_not_serve_is_named_by_its_path(): void
    {
        $this->manifest(['resources/js/app.ts' => ['file' => 'assets/app-abc.js']]);

        Http::fake([
            'https://label.example/build/assets/app-abc.js' => Http::response('', 404),
            'https://label.example/*' => Http::response('', 404),
        ]);

        $failures = $this->failures();

        $this->assertArrayHasKey('frontend_asset', $failures);
        $this->assertStringContainsString('/build/assets/app-abc.js', $failures['frontend_asset']);
    }

    #[Test]
    public function an_unreachable_host_fails_without_echoing_anything_about_it(): void
    {
        $this->manifest(['a' => ['file' => 'assets/a.js']]);

        Http::fake(static function (): void {
            throw new ConnectionException('cURL error 7: Failed to connect to 10.1.2.3 port 443');
        });

        foreach ($this->failures() as $detail) {
            $this->assertStringNotContainsString('10.1.2.3', $detail);
        }
    }

    #[Test]
    public function the_command_fails_loudly_and_json_carries_the_same_verdicts(): void
    {
        $this->swapRunner();
        config(['app.url' => 'https://label.example']);

        Http::fake([
            'https://label.example/.env' => Http::response('leak', 200),
            'https://label.example/*' => Http::response('', 404),
        ]);

        $this->artisan('sanitube:smoke')->assertFailed();

        $this->artisan('sanitube:smoke', ['--json' => true])
            ->expectsOutputToContain('"forbidden:/.env"')
            ->assertFailed();
    }

    #[Test]
    public function no_url_anywhere_is_a_refusal_not_a_guess(): void
    {
        config(['app.url' => '']);

        $this->artisan('sanitube:smoke')
            ->expectsOutputToContain('No usable base URL')
            ->assertFailed();
    }

    // ----------------------------------------------------------- fixtures

    /**
     * @param  array<string, array{file: string}>  $entries
     */
    private function manifest(array $entries): void
    {
        file_put_contents($this->publicRoot.'/build/manifest.json', (string) json_encode($entries));
    }

    private function runner(): SmokeTestRunner
    {
        return new SmokeTestRunner(
            http: $this->app->make(HttpFactory::class),
            publicPath: $this->publicRoot,
        );
    }

    private function swapRunner(): void
    {
        $this->app->bind(SmokeTestRunner::class, fn (): SmokeTestRunner => $this->runner());
    }

    /**
     * @return list<SmokeCheck>
     */
    private function smoke(): array
    {
        return $this->runner()->run('https://label.example');
    }

    /**
     * @return array<string, string> failed check name => detail
     */
    private function failures(): array
    {
        $failures = [];

        foreach ($this->smoke() as $check) {
            if ($check->hasFailed()) {
                $failures[$check->name] = $check->detail;
            }
        }

        return $failures;
    }
}
