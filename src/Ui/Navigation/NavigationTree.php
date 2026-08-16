<?php

declare(strict_types=1);

namespace SaniTube\Ui\Navigation;

use App\Models\User;

/**
 * What this person may see in the sidebar.
 *
 * Built on the server, never in the bundle. Which sections exist is not a
 * secret, but which ones *this user* may reach is a function of their role —
 * and a client-side list filtered in JavaScript is a list an attacker reads in
 * full before the filter runs.
 *
 * Sections with no screen yet are returned with `available: false` rather than
 * omitted. A navigation that grows as features land is disorienting; one that
 * shows the shape of the product and marks what is not ready is honest, stable
 * to design against, and does not move under a user between releases.
 *
 * `available` is presentation only. It is never the authorisation — that is
 * the route middleware's job, and a disabled link is not a permission check.
 */
final readonly class NavigationTree
{
    /**
     * @return list<array<string, mixed>>
     */
    public function for(?User $user): array
    {
        if (! $user instanceof User) {
            return [];
        }

        return [
            $this->item('dashboard', '/', 'dashboard', available: true),
            $this->item('studio', '/studio', 'studio', available: true),

            $this->group('library', 'library', [
                $this->item('ingestion', '/ingestion/batches', 'ingestion', available: true),
                $this->item('candidates', '/ingestion/candidates', 'ingestion', available: true),
                $this->item('catalog', '/catalog/tracks', 'catalog', available: true),
                $this->item('artists', '/catalog/artists', 'catalog', available: true),
                $this->item('contributors', '/catalog/contributors', 'catalog', available: true),
                $this->item('compositions', '/catalog/compositions', 'catalog', available: true),
                $this->item('assets', '/catalog/assets', 'assets', available: true),
            ]),

            $this->item('releases', '/releases', 'releases', available: true),
            $this->item('distribution', null, 'distribution', available: false),
            $this->item('rights', null, 'rights', available: false),
            $this->item('publishing', null, 'publishing', available: false),
            $this->item('royalties', null, 'royalties', available: false),
            $this->item('analytics', null, 'analytics', available: false),
            // Administration only. `available` is presentation — the route
            // middleware is the authorisation — but showing an operator a link
            // they cannot open is not honesty, it is noise.
            $this->item('jobs', '/system/jobs', 'jobs', available: $user->role->canAdminister()),
            $this->item('operations', '/system/operations', 'system', available: $user->role->canAdminister()),

            // The one screen UI-001 ships. It exists to prove the design
            // system holds together, and it is deliberately not a business
            // screen — those wait for the design system to be reviewed.
            $this->item('design_system', '/design-system', 'system', available: true),

            $this->item('settings', null, 'settings', available: $user->role->canAdminister()),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function item(string $key, ?string $href, string $icon, bool $available): array
    {
        return [
            'key' => $key,
            'label' => __('ui.navigation.'.$key),
            'href' => $available ? $href : null,
            'icon' => $icon,
            'available' => $available && $href !== null,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $children
     * @return array<string, mixed>
     */
    private function group(string $key, string $icon, array $children): array
    {
        return [
            'key' => $key,
            'label' => __('ui.navigation.'.$key),
            'href' => null,
            'icon' => $icon,
            'available' => false,
            'children' => $children,
        ];
    }
}
