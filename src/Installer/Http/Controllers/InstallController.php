<?php

declare(strict_types=1);

namespace SaniTube\Installer\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use SaniTube\Installer\Services\EnvironmentFile;
use SaniTube\Installer\Services\InstallationLock;
use SaniTube\Installer\Services\InstallationService;
use SaniTube\Installer\StageResult;

/**
 * Installing SaniTube from a browser.
 *
 * **This is a terminal in front of {@see InstallationService}, exactly as the
 * console command is.** Not one stage is reimplemented here. A web installer
 * with its own copy of the stages is a second installer, and the second one is
 * the one that misses a fix — the one that regenerates APP_KEY on a re-run, or
 * creates a second owner, or drops the backup before writing `.env`. The
 * service already refuses all three and this class does not get an opinion.
 *
 * What it adds is the three things a browser needs and a terminal does not:
 *
 *   - **Proof that the person is on the server.** See {@see InstallationLock}.
 *     Before installation there is nobody to authenticate, so the guard is a
 *     token readable only from the filesystem.
 *   - **Somewhere to type the database settings.** The console installer
 *     assumes somebody has already edited `.env`, which on a cPanel account
 *     with no shell is the hard part rather than the easy one.
 *   - **A lock.** The installer closes behind itself.
 *
 * **No secret is ever rendered.** The database password is written to `.env`
 * and never read back into a field; the owner password is passed to the
 * service and never returned; neither survives a validation failure, because
 * `old()` would otherwise put both back into the HTML of the next response.
 */
final class InstallController
{
    /**
     * The keys this form may write, and the only ones.
     *
     * An allowlist rather than "whatever was posted". A form that can set an
     * arbitrary `.env` key can set `APP_DEBUG`, `APP_KEY` or the mail
     * credentials, and this one runs before anybody has authenticated.
     *
     * @var array<string, string>
     */
    private const WRITABLE = [
        'app_url' => 'APP_URL',
        'db_connection' => 'DB_CONNECTION',
        'db_host' => 'DB_HOST',
        'db_port' => 'DB_PORT',
        'db_database' => 'DB_DATABASE',
        'db_username' => 'DB_USERNAME',
        'db_password' => 'DB_PASSWORD',
    ];

    public function show(InstallationService $installation, InstallationLock $lock): View
    {
        // Minted here, so the file exists by the time the operator goes
        // looking for it. Its *value* is never passed to the view.
        $lock->token();

        return view('installer::install', [
            'status' => $installation->status()->toArray(),
            'tokenLocation' => $lock->tokenLocation(),
            'results' => session('installer.results', []),
        ]);
    }

    public function store(
        Request $request,
        InstallationService $installation,
        InstallationLock $lock,
        EnvironmentFile $environment,
    ): RedirectResponse {
        $validated = $request->validate([
            'token' => ['required', 'string', 'max:200'],
            'app_url' => ['required', 'url', 'max:255'],
            'db_connection' => ['required', 'string', 'in:mysql,mariadb,sqlite'],
            'db_host' => ['nullable', 'string', 'max:255'],
            'db_port' => ['nullable', 'string', 'max:8'],
            'db_database' => ['required', 'string', 'max:255'],
            'db_username' => ['nullable', 'string', 'max:255'],
            'db_password' => ['nullable', 'string', 'max:255'],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            // The installer's floor, so the two paths cannot disagree about
            // what a usable password is.
            'password' => ['required', 'string', 'min:12', 'confirmed'],
        ]);

        if (! $lock->accepts((string) $validated['token'])) {
            // The same refusal for a wrong token and an absent one. Which of
            // the two it was is a fact about this server that whoever is
            // guessing has not earned.
            throw ValidationException::withMessages(['token' => __('installer.token_refused')]);
        }

        $results = [];

        $results[] = $preflight = $installation->preflight();

        if ($preflight->hasFailed()) {
            return $this->stopped($results);
        }

        $results[] = $file = $installation->environmentFile();

        if ($file->hasFailed()) {
            return $this->stopped($results);
        }

        if (! $this->writeConfiguration($environment, $validated)) {
            return $this->stopped([...$results, StageResult::failed('configuration', __('installer.configuration_failed'))]);
        }

        $results[] = StageResult::done('configuration', __('installer.configuration_written'));

        // The connection was resolved from the old values when the request
        // booted. Forgetting it makes the next stage open a fresh one from
        // what was just written, rather than reporting on what used to be.
        $this->reconnect($validated);

        foreach ([$installation->applicationKey(), $installation->database(), $installation->migrate()] as $stage) {
            $results[] = $stage;

            if ($stage->hasFailed()) {
                return $this->stopped($results);
            }
        }

        $results[] = $owner = $installation->createOwner(
            (string) $validated['name'],
            (string) $validated['email'],
            (string) $validated['password'],
        );

        if ($owner->hasFailed()) {
            return $this->stopped($results);
        }

        $results[] = $verify = $installation->verify();

        if ($verify->hasFailed()) {
            return $this->stopped($results);
        }

        // Last, and only after verification asked the installation itself
        // rather than trusting the stages to have succeeded. Locking a broken
        // install would leave nobody a way back in.
        $lock->lock();

        return redirect()->route('login')->with('status', __('installer.completed'));
    }

    /**
     * Write the allowlisted keys, and nothing else.
     *
     * @param  array<string, mixed>  $validated
     */
    private function writeConfiguration(EnvironmentFile $environment, array $validated): bool
    {
        foreach (self::WRITABLE as $field => $key) {
            $value = $validated[$field] ?? null;

            if ($value === null) {
                // An absent optional field leaves the existing line alone.
                // Writing an empty string would erase a value the operator did
                // not ask to change.
                continue;
            }

            if (! $environment->set($key, (string) $value)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Point the default connection at what was just written.
     *
     * The connection was resolved from the *old* values when the request
     * booted, so without this the database and migration stages would report
     * on whatever `.env` used to say.
     *
     * **Only when something actually changed.** Purging a connection that is
     * already correct throws away a working handle to open an identical one —
     * pointless on a first install, and on a re-run it drops the connection
     * the previous stages just proved was good.
     *
     * @param  array<string, mixed>  $validated
     */
    private function reconnect(array $validated): void
    {
        $connection = (string) $validated['db_connection'];

        $settings = ['database.default' => $connection];

        foreach (['host' => 'db_host', 'port' => 'db_port', 'database' => 'db_database', 'username' => 'db_username', 'password' => 'db_password'] as $key => $field) {
            if (($validated[$field] ?? null) !== null) {
                $settings['database.connections.'.$connection.'.'.$key] = (string) $validated[$field];
            }
        }

        $changed = false;

        foreach ($settings as $key => $value) {
            if ((string) config($key) !== $value) {
                $changed = true;
            }

            config([$key => $value]);
        }

        if ($changed) {
            app('db')->purge($connection);
        }
    }

    /**
     * Report where it stopped, without carrying anything typed back with it.
     *
     * @param  list<StageResult>  $results
     */
    private function stopped(array $results): RedirectResponse
    {
        // Deliberately not `withInput()`. It would put the database password
        // and the owner's password into the session and then into the HTML of
        // the next response, which is the one thing this screen must not do —
        // and re-typing seven fields is a smaller cost than that.
        return back()->with('installer.results', array_map(
            static fn (StageResult $result): array => $result->toArray(),
            $results,
        ));
    }
}
