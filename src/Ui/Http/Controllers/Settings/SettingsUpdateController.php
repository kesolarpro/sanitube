<?php

declare(strict_types=1);

namespace SaniTube\Ui\Http\Controllers\Settings;

use App\Models\User;
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
 * USR-001. **A credential is an owner's to change, not an administrator's.**
 * The split is narrow and deliberate: an administrator changes how a provider
 * behaves — which one is selected, its quotas, its timeouts — and an owner
 * decides which account at a supplier this installation spends against. The
 * rule is passed to the writer rather than enforced here, so that it holds for
 * every caller rather than for this one door.
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
        /** @var User $actor */
        $actor = $request->user();

        try {
            $changed = $settings->apply(
                $request->settings(),
                mayWriteSecrets: $actor->role->canManageOwnership(),
            );
        } catch (SettingsWriteFailed $exception) {
            return back()->withErrors(['settings' => $exception->reason]);
        }

        // "Nothing changed" is a distinct outcome, not a quiet success. An
        // operator who typed into a secret field and then submitted a blank
        // one by accident needs to be told the difference.
        return back()->with('status', $changed === [] ? 'settings.unchanged' : 'settings.saved');
    }
}
