<?php

declare(strict_types=1);

namespace SaniTube\Ui\Http\Controllers\Settings;

use Inertia\Inertia;
use Inertia\Response;
use SaniTube\Observability\Certification\ProviderStandings;
use SaniTube\Ui\Queries\SettingsQuery;
use SaniTube\Ui\Settings\WritableSettings;

/**
 * What this installation is configured with.
 *
 * Administrator-only even though it publishes no secret. The list of which
 * credentials are present is itself a map of where the soft spots are.
 *
 * SET-002 made a subset of it editable. The payload carries the *names* of the
 * writable variables so the screen can put a field beside them, and still
 * carries no value for any of them — a secret's field renders empty on every
 * load, which is the same fact the read side has always published, expressed
 * as an input rather than a word.
 *
 * CFG-003 adds the standings. The six-state certification vocabulary existed
 * for a phase and could be read from exactly one place — `sanitube:providers`,
 * over SSH — which made "has anybody actually proved this works?" a question
 * only somebody with a shell could ask, on the screen whose entire subject is
 * what is configured. It is a read: nothing here probes anything, and a
 * standing says what the *ledger* recorded, never what is reachable now.
 */
final class SettingsController
{
    public function __invoke(
        SettingsQuery $settings,
        WritableSettings $writable,
        ProviderStandings $standings,
    ): Response {
        return Inertia::render('Settings/Index', [
            'settings' => $settings->overview(),
            // Names only. Whether a value exists is already in `settings`;
            // what it is has never been sent and is not sent now.
            'writable' => $writable->variables(),
            'standings' => array_map(
                static fn (object $standing): array => $standing->toArray(),
                $standings->assess(),
            ),
        ]);
    }
}
