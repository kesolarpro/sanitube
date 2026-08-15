<?php

declare(strict_types=1);

namespace SaniTube\Ui\Http\Middleware;

use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Middleware;
use SaniTube\Localization\LocaleRegistry;
use SaniTube\Ui\Navigation\NavigationTree;

/**
 * What every page receives.
 *
 * Shared once rather than fetched per screen: the navigation, the signed-in
 * user and the translations are needed everywhere, and three extra requests on
 * every navigation is how a fast interface stops being one.
 *
 * **Nothing here is a credential.** The internal API token, the storage keys
 * and the provider keys stay on the server — they are server-to-server
 * secrets, and anything placed in these props is in the page source, readable
 * by any browser extension. The first-party interface authenticates with the
 * session cookie it already has.
 */
final class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    /**
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();
        $registry = app(LocaleRegistry::class);

        return [
            ...parent::share($request),

            'app' => [
                'name' => config('app.name'),
                'locale' => app()->getLocale(),
                'locales' => array_map(
                    static fn (string $code): array => ['code' => $code, 'label' => $code],
                    $registry->codes(),
                ),
            ],

            'auth' => [
                'user' => $user instanceof User ? [
                    'uuid' => $user->uuid,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $user->role->value,
                    // Sent so the interface can hide what a person cannot do.
                    // Hiding is courtesy; the route middleware is the actual
                    // guard, and this is never a substitute for it.
                    'can' => [
                        'catalogue' => $user->role->canWriteCatalogue(),
                        'distribute' => $user->role->canDistribute(),
                        'administer' => $user->role->canAdminister(),
                    ],
                ] : null,
            ],

            'navigation' => app(NavigationTree::class)->for($user instanceof User ? $user : null),

            'flash' => [
                'success' => $request->session()->get('success'),
                'error' => $request->session()->get('error'),
            ],

            // Resolved server-side for the active locale, so the interface
            // cannot be in one language while the server thinks it is in
            // another — and so no language pack is fetched separately.
            'translations' => $this->translations(),

            'csrf_token' => csrf_token(),
        ];
    }

    /**
     * Every `ui.*` and `identity.*` line, flattened to dotted keys.
     *
     * Only those two files. Shipping every translation the application owns
     * would send distributor error copy to a screen that shows none of it.
     *
     * @return array<string, string>
     */
    private function translations(): array
    {
        $lines = [];

        foreach (['ui', 'identity'] as $file) {
            /** @var array<string, mixed> $group */
            $group = (array) trans($file);

            $lines = [...$lines, ...$this->flatten($group, $file)];
        }

        return $lines;
    }

    /**
     * @param  array<array-key, mixed>  $lines
     * @return array<string, string>
     */
    private function flatten(array $lines, string $prefix): array
    {
        $flat = [];

        foreach ($lines as $key => $value) {
            $path = $prefix.'.'.$key;

            if (is_array($value)) {
                $flat = [...$flat, ...$this->flatten($value, $path)];

                continue;
            }

            if (is_string($value)) {
                $flat[$path] = $value;
            }
        }

        return $flat;
    }
}
