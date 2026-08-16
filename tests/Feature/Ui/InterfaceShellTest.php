<?php

declare(strict_types=1);

namespace Tests\Feature\Ui;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use SaniTube\Identity\Enums\UserRole;
use SaniTube\Ui\Navigation\NavigationTree;
use Tests\TestCase;

/**
 * The application shell, and the one screen UI-001 ships.
 *
 * Two things are being asserted here above all: that no interface route is
 * reachable without a session, and that nothing secret rides along in the
 * props every page receives.
 */
final class InterfaceShellTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function every_interface_route_is_behind_authentication(): void
    {
        // SEC-001 exists so this is true. A design system page is harmless on
        // its own, but the assertion is about the *route group* — the business
        // screens land in it next, and they are not harmless.
        $this->get('/design-system')->assertRedirect(route('login'));
    }

    #[Test]
    public function a_deactivated_account_loses_the_interface_at_the_next_request(): void
    {
        $user = $this->user();
        $this->actingAs($user);

        $this->get('/design-system')->assertOk();

        $user->forceFill(['is_active' => false])->save();

        $this->get('/design-system')->assertRedirect(route('login'));
    }

    #[Test]
    public function the_showcase_renders_for_a_signed_in_person(): void
    {
        $this->actingAs($this->user())
            ->get('/design-system')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('DesignSystem/Index'));
    }

    // ------------------------------------------------------- what is shared

    #[Test]
    public function no_credential_is_shared_with_the_browser(): void
    {
        // The assertion that matters most in this file. The internal API
        // token, storage keys and provider keys are server-to-server secrets;
        // anything in these props is in the page source and readable by any
        // browser extension.
        config([
            'sanitube.api.internal_token' => 'the-internal-api-token-value',
            'ai.providers.openai.key' => 'sk-a-real-looking-openai-key',
        ]);

        $response = $this->actingAs($this->user())->get('/design-system')->assertOk();
        $body = $response->content();

        foreach (['the-internal-api-token-value', 'sk-a-real-looking-openai-key'] as $secret) {
            $this->assertStringNotContainsString($secret, $body, sprintf('[%s] reached the browser.', $secret));
        }

        $response->assertInertia(function (AssertableInertia $page): void {
            foreach ($this->keysIn((array) $page->toArray()['props']) as $key) {
                // Named precisely. A bare `key` would match the navigation
                // items' own `key` field — the same false positive ING-001
                // hit with `path`, and a check that cries wolf is one people
                // start deleting.
                $this->assertNotContains($key, [
                    'internal_token', 'api_token', 'api_key', 'secret', 'password',
                    'access_key', 'secret_key', 'private_key', 'credentials',
                ], sprintf('[%s] is shared with every page.', $key));
            }
        });
    }

    #[Test]
    public function the_signed_in_person_is_shared_without_their_password_hash(): void
    {
        $this->actingAs($this->user())
            ->get('/design-system')
            ->assertInertia(
                fn (AssertableInertia $page) => $page
                    ->where('auth.user.role', 'OWNER')
                    ->where('auth.user.can.administer', true)
                    ->missing('auth.user.password')
                    ->missing('auth.user.id'),
            );
    }

    #[Test]
    public function translations_are_shared_resolved_for_the_active_locale(): void
    {
        // Resolved server-side so the interface cannot be in one language
        // while the server thinks it is in another.
        $this->actingAs($this->user())
            ->get('/design-system?lang=fr')
            ->assertInertia(function (AssertableInertia $page): void {
                $props = $page->toArray()['props'];

                $this->assertSame('fr', $props['app']['locale']);

                // Read from the array rather than through Inertia's dotted
                // `where()`: the map is intentionally *flat*, with literal
                // dotted keys, so `trans('ui.actions.save')` is a single
                // lookup in the browser instead of a walk down an object.
                // `where()` would try to traverse it and find nothing.
                $this->assertSame('Enregistrer', $props['translations']['ui.actions.save']);
                $this->assertSame('Se connecter', $props['translations']['identity.auth.sign_in']);
            });
    }

    // ---------------------------------------------------------- navigation

    #[Test]
    public function the_navigation_is_built_on_the_server_and_marks_what_is_not_ready(): void
    {
        // Sections without a screen are returned as unavailable rather than
        // omitted: a navigation that grows as features land is disorienting.
        $this->actingAs($this->user())
            ->get('/design-system')
            ->assertInertia(function (AssertableInertia $page): void {
                $navigation = $page->toArray()['props']['navigation'];

                $this->assertIsArray($navigation);
                $this->assertNotSame([], $navigation);

                // Flattened, because Library is a group and Catalog is one of
                // its children — the tree has two levels by design.
                $byKey = [];

                foreach ($navigation as $item) {
                    $byKey[$item['key']] = $item;

                    foreach ($item['children'] ?? [] as $child) {
                        $byKey[$child['key']] = $child;
                    }
                }

                $this->assertTrue($byKey['design_system']['available']);
                $this->assertSame('/design-system', $byKey['design_system']['href']);

                // Built, and pointing somewhere real. This assertion moves as
                // screens land — it was `catalog` that was unbuilt until UI-003.
                $this->assertTrue($byKey['catalog']['available']);
                $this->assertSame('/catalog/tracks', $byKey['catalog']['href']);

                $this->assertTrue($byKey['releases']['available']);
                $this->assertSame('/releases', $byKey['releases']['href']);

                $this->assertTrue($byKey['distribution']['available']);
                $this->assertSame('/distribution', $byKey['distribution']['href']);

                // Not built yet, and honest about it.
                $this->assertFalse($byKey['analytics']['available'] ?? true, 'Analytics has no screen yet.');
                $this->assertNull($byKey['analytics']['href']);
            });
    }

    #[Test]
    public function a_member_is_not_offered_the_administration_section(): void
    {
        // Presentation only — the route middleware is the actual guard — but
        // offering a link that will 403 is a way to make people distrust the
        // interface.
        $this->actingAs($this->user(UserRole::Member, 'member@example.test'))
            ->get('/design-system')
            ->assertInertia(function (AssertableInertia $page): void {
                foreach ($page->toArray()['props']['navigation'] as $item) {
                    if ($item['key'] === 'settings') {
                        $this->assertFalse($item['available']);
                    }
                }
            });
    }

    #[Test]
    public function a_guest_receives_no_navigation_at_all(): void
    {
        $this->assertSame([], app(NavigationTree::class)->for(null));
    }

    // --------------------------------------------------------------- helpers

    /**
     * @param  array<array-key, mixed>  $payload
     * @return list<string>
     */
    private function keysIn(array $payload): array
    {
        $keys = [];

        foreach ($payload as $key => $value) {
            if (is_string($key)) {
                $keys[] = $key;
            }

            if (is_array($value)) {
                $keys = array_merge($keys, $this->keysIn($value));
            }
        }

        return array_values(array_unique($keys));
    }

    private function user(UserRole $role = UserRole::Owner, string $email = 'owner@example.test'): User
    {
        $user = User::query()->create([
            'name' => 'Owner',
            'email' => $email,
            'password' => 'a-long-enough-passphrase',
        ]);

        return $user->forceFill(['role' => $role, 'is_active' => true]);
    }
}
