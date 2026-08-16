<?php

declare(strict_types=1);

namespace SaniTube\Ui\Settings;

use Illuminate\Contracts\Console\Kernel as Artisan;
use SaniTube\Audit\Enums\AuditAction;
use SaniTube\Audit\Services\RecordAuditEvent;
use SaniTube\Installer\Services\EnvironmentFile;
use Throwable;

/**
 * Changing configuration from the interface.
 *
 * The read side of this screen has always been careful: a credential is
 * reported as **configured** or **not configured** and never as a value, a
 * mask, or a length. The write side has to keep that promise while doing the
 * opposite thing, and four rules do it.
 *
 * **A blank field never clears anything.** For a secret that is the point:
 * the field renders empty because the browser is never told what is in it, so
 * a blank submission is what an operator sends when they edited something
 * *else* on the page. For a plain setting it is a consequence — Laravel turns
 * empty strings into null before they arrive, so "cleared" and "untouched"
 * are the same request. Removal is therefore not offered through this form at
 * all; it stays a `.env` edit, which is visible and deliberate.
 *
 * **One write, or none.** Four calls to `set()` are four rewrites, and a
 * failure on the third leaves an installation configured half one way and half
 * the other. {@see EnvironmentFile::setMany()} does it in a single write, over
 * a backup taken first.
 *
 * **The config cache is rebuilt, or the whole thing was theatre.** An
 * installation that has run `config:cache` — which every production install
 * should — reads nothing from `.env` at runtime. Writing the file and
 * reporting success would leave an operator staring at a screen that says the
 * new key is configured while the platform keeps using the old one. If the
 * rebuild fails, the previous `.env` is restored: a cache that no longer
 * matches the file is a worse state than the one we started in.
 *
 * **Nothing that was written is reported back.** The audit line names the
 * variables that changed, never their values, and the response says how many.
 * A confirmation message quoting the new API key would undo the entire read
 * side of this feature in one line.
 */
final readonly class UpdateSettings
{
    public function __construct(
        private WritableSettings $writable,
        private EnvironmentFile $environment,
        private RecordAuditEvent $audit,
        private Artisan $artisan,
    ) {}

    /**
     * Apply a submission.
     *
     * @param  array<string, mixed>  $submitted  already validated against {@see WritableSettings::rules()}
     * @return list<string> the variables that changed, in declaration order
     *
     * @throws SettingsWriteFailed
     */
    public function apply(array $submitted): array
    {
        $changes = [];

        foreach ($this->writable->all() as $setting) {
            $value = $submitted[$setting->variable] ?? null;
            $value = is_scalar($value) ? (string) $value : null;

            if ($setting->isUnchanged($value)) {
                continue;
            }

            // Unchanged in the other sense: submitted, and identical to what
            // is already there. Writing it would take a backup, rebuild the
            // config cache and record an audit line about nothing.
            if (! $setting->secret && $this->environment->get($setting->variable) === ($value === '' ? null : $value)) {
                continue;
            }

            $changes[$setting->variable] = (string) $value;
        }

        if ($changes === []) {
            return [];
        }

        if (! $this->environment->setMany($changes)) {
            throw SettingsWriteFailed::notWritten();
        }

        $this->rebuildConfigurationCache();

        // The variable names, never the values — and the audit log's own
        // redaction would refuse them anyway, which is the point of putting
        // the rule in one place rather than trusting each call site.
        $this->audit->record(
            AuditAction::SettingsChanged,
            context: ['variables' => implode(', ', array_keys($changes)), 'count' => count($changes)],
        );

        return array_keys($changes);
    }

    /**
     * Rebuild the cache, or put the file back.
     *
     * Only when a cache exists. Building one where there was none would change
     * how the installation behaves on every subsequent `.env` edit — an
     * operator who has always edited the file and restarted would find their
     * changes silently ignored from then on, because of a settings save.
     *
     * @throws SettingsWriteFailed
     */
    private function rebuildConfigurationCache(): void
    {
        if (! app()->configurationIsCached()) {
            return;
        }

        try {
            $exitCode = $this->artisan->call('config:cache');
        } catch (Throwable) {
            $exitCode = 1;
        }

        if ($exitCode === 0) {
            return;
        }

        // A cache that no longer matches the file is worse than the state we
        // started in: the screen would report the new value and the platform
        // would use neither.
        $this->environment->restoreBackup();

        throw SettingsWriteFailed::cacheNotRebuilt();
    }
}
