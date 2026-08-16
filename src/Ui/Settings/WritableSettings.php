<?php

declare(strict_types=1);

namespace SaniTube\Ui\Settings;

use SaniTube\Ui\Queries\SettingsQuery;

/**
 * Everything the settings screen may write, and nothing else.
 *
 * **What is absent is the interesting part.** `APP_KEY` is not here: replacing
 * it makes existing sessions and any encrypted column unreadable, and the
 * installer refuses to regenerate it for the same reason. `APP_ENV` and
 * `APP_DEBUG` are not here: debug on in production puts stack traces, queries
 * and environment values in front of whoever triggers an error, and that is a
 * deployment decision rather than a form field. `DB_*` is not here either —
 * repointing a running installation's database from a web form is not a
 * setting, it is a migration, and the installer is where that belongs.
 *
 * What is here is the credentials and endpoints a label genuinely has to
 * rotate without a shell: provider keys, bucket credentials, the API tokens,
 * and the handful of numeric limits that change with usage.
 *
 * The variable names are the same ones {@see SettingsQuery}
 * publishes. `SettingsRegistryTest` asserts that every writable variable
 * actually appears on the screen — two lists that can drift are two lists that
 * will, and the failure mode is a field an operator can change and cannot see.
 */
final readonly class WritableSettings
{
    /**
     * @return list<WritableSetting>
     */
    public function all(): array
    {
        return [
            // --- storage. The credentials, and how long a preview lives.
            new WritableSetting('AWS_ACCESS_KEY_ID', 'filesystems.disks.s3.key', true, ['string', 'max:255']),
            new WritableSetting('AWS_SECRET_ACCESS_KEY', 'filesystems.disks.s3.secret', true, ['string', 'max:255']),
            new WritableSetting('AWS_BUCKET', 'filesystems.disks.s3.bucket', true, ['string', 'max:255']),
            // Clamped by AssetPreviewPolicy at read time regardless of what is
            // stored, so a bad value here degrades rather than breaks. The
            // bound is repeated as a rule so the form can say so.
            new WritableSetting('SANITUBE_TEMPORARY_URL_TTL', 'storage.temporary_url_ttl', false, ['integer', 'min:300', 'max:900']),

            // --- AI. The base URL is a setting, not a credential: which
            // endpoint an installation talks to is exactly what an operator
            // needs to see when an integration is pointed somewhere wrong.
            new WritableSetting('SANITUBE_OPENAI_KEY', 'ai.providers.openai.key', true, ['string', 'max:255']),
            new WritableSetting('SANITUBE_OPENAI_BASE_URL', 'ai.providers.openai.base_url', false, ['url', 'max:255']),
            new WritableSetting('SANITUBE_CLAUDE_KEY', 'ai.providers.claude.key', true, ['string', 'max:255']),
            new WritableSetting('SANITUBE_CLAUDE_BASE_URL', 'ai.providers.claude.base_url', false, ['url', 'max:255']),

            // --- generation. Both bound the polling loop; a generation that
            // polls forever is the failure GEN-001 exists to prevent.
            new WritableSetting('SANITUBE_GENERATION_MAX_POLLS', 'generation.poll.max_polls', false, ['integer', 'min:1', 'max:500']),
            new WritableSetting('SANITUBE_GENERATION_POLL_INTERVAL', 'generation.poll.interval_seconds', false, ['integer', 'min:5', 'max:3600']),

            // --- API. Both are bearer credentials for server-to-server calls.
            new WritableSetting('SANITUBE_INTERNAL_API_TOKEN', 'sanitube.api.internal_token', true, ['string', 'min:32', 'max:255']),
            new WritableSetting('SANITUBE_HEALTH_TOKEN', 'sanitube.health.token', true, ['string', 'min:32', 'max:255']),
            new WritableSetting('SANITUBE_API_RATE_LIMIT', 'sanitube.api.rate_limit_per_minute', false, ['integer', 'min:1', 'max:10000']),
        ];
    }

    public function find(string $variable): ?WritableSetting
    {
        foreach ($this->all() as $setting) {
            if ($setting->variable === $variable) {
                return $setting;
            }
        }

        return null;
    }

    /**
     * The validation rules, keyed by variable.
     *
     * Every field is `nullable`: an absent one means "not submitted" and, for
     * a secret, a blank one means "unchanged". Requiredness is not a property
     * of a settings form — nothing on it has to be filled in to save the rest.
     *
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        $rules = [];

        foreach ($this->all() as $setting) {
            $rules[$setting->variable] = ['nullable', ...$setting->rules];
        }

        return $rules;
    }

    /**
     * The variables, for a screen that needs to know which fields to render.
     *
     * @return list<string>
     */
    public function variables(): array
    {
        return array_map(static fn (WritableSetting $setting): string => $setting->variable, $this->all());
    }
}
