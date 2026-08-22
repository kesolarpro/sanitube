<?php

declare(strict_types=1);

namespace SaniTube\Ui\Settings;

use Illuminate\Contracts\Validation\ValidationRule;

/**
 * One thing the settings screen is allowed to change.
 *
 * The list of these is the authorisation boundary for SET-002. A settings form
 * that writes whatever key it was posted can write `APP_DEBUG`, `APP_KEY` or
 * the mail credentials — so what may be written is declared here, once, and
 * anything not on the list is not a validation failure but a key the writer
 * has never heard of.
 *
 * **`secret` changes the semantics of an empty value, not just its display.**
 * For a plain setting, blank means "set this to nothing". For a secret, blank
 * means **unchanged** — because the field it came from was rendered empty, the
 * browser never having been told what is in there. Treating the two the same
 * would erase a provider key every time somebody edited the field next to it.
 */
final readonly class WritableSetting
{
    /**
     * @param  string  $variable  the environment variable, which is what an operator edits
     * @param  string  $configPath  where the value lands after the config cache is rebuilt
     * @param  bool  $secret  whether the browser is told only configured/not configured
     * @param  list<string|ValidationRule>  $rules  validation, applied before anything is written
     */
    public function __construct(
        public string $variable,
        public string $configPath,
        public bool $secret,
        public array $rules,
    ) {}

    /**
     * Whether this submission means "leave it alone".
     *
     * A secret arrives blank unless somebody typed into it, because the field
     * is rendered empty on every load. That is the whole reason a secret
     * cannot be cleared by submitting the form — removing one is a separate,
     * explicit act, and making it the accidental outcome of an unrelated edit
     * would be the worst possible default.
     *
     * **Nothing can be cleared through this form, secret or not.** Laravel's
     * `ConvertEmptyStringsToNull` turns every empty field into null before it
     * arrives, so a blank plain setting is indistinguishable from one nobody
     * touched. Rather than defeat that middleware to tell them apart, removal
     * is simply not offered: a settings form whose blank fields erase
     * configuration is one where saving a rate limit can silently unset a
     * provider endpoint. Emptying a value stays a `.env` edit, which is
     * deliberate and visible.
     */
    public function isUnchanged(?string $submitted): bool
    {
        // Null or blank, and the two are the same request in practice:
        // Laravel converts empty strings before they arrive. The rule is
        // written for both anyway, because a rule that depends on a global
        // middleware staying installed is a rule with a fuse in it.
        //
        // Deliberately not qualified by `secret`. An earlier version treated a
        // blank secret as unchanged and a blank plain setting as empty, and
        // the distinction turned out to be unreachable — which is a worse kind
        // of wrong than being uniform, because it reads as a decision.
        return $submitted === null || trim($submitted) === '';
    }
}
