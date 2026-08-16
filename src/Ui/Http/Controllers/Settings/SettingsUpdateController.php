<?php

declare(strict_types=1);

namespace SaniTube\Ui\Http\Controllers\Settings;

use Illuminate\Http\RedirectResponse;
use SaniTube\Ui\Http\Requests\Settings\UpdateSettingsRequest;
use SaniTube\Ui\Settings\SettingsWriteFailed;
use SaniTube\Ui\Settings\UpdateSettings;

/**
 * Saving a settings change.
 *
 * Behind `can.role:administer` on the route: this is the platform's only path
 * from a browser to a `.env` file, and a MEMBER may read the settings screen
 * and change nothing on it.
 *
 * **The response names no value it wrote.** It says how many variables changed
 * and lets the screen re-render from configuration — which reports a secret as
 * configured or not configured and nothing else. A confirmation message
 * quoting the new API key would undo the entire read side of this feature in
 * one line.
 */
final class SettingsUpdateController
{
    public function __invoke(UpdateSettingsRequest $request, UpdateSettings $settings): RedirectResponse
    {
        try {
            $changed = $settings->apply($request->settings());
        } catch (SettingsWriteFailed $exception) {
            return back()->withErrors(['settings' => $exception->reason]);
        }

        // "Nothing changed" is a distinct outcome, not a quiet success. An
        // operator who typed into a secret field and then submitted a blank
        // one by accident needs to be told the difference.
        return back()->with('status', $changed === [] ? 'settings.unchanged' : 'settings.saved');
    }
}
