<?php

declare(strict_types=1);

namespace SaniTube\Ui\Settings;

use SaniTube\Storage\ProviderConfiguration;
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
 * **The storage half of the list is not a list.** It is read from the selected
 * provider, because the variable names differ per provider and STO-004 is what
 * happened when they were assumed not to: an R2 installation was told to set
 * `AWS_ACCESS_KEY_ID`, and writing it landed the credential on the s3 disk,
 * which R2 never reads.
 *
 * The variable names are the same ones {@see SettingsQuery}
 * publishes. `SettingsWriteTest` asserts that every writable variable
 * actually appears on the screen — two lists that can drift are two lists that
 * will, and the failure mode is a field an operator can change and cannot see.
 */
final readonly class WritableSettings
{
    public function __construct(private ProviderConfiguration $storageProviders) {}

    /**
     * @return list<WritableSetting>
     */
    public function all(): array
    {
        return [
            // --- storage. The provider, its own credentials, and how long a
            // preview lives.
            ...$this->storage(),
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

    /**
     * The storage fields, which depend on which provider is in use.
     *
     * **Only the selected provider's.** Offering all four at once would put
     * twelve fields on the screen, eleven of which are read by nothing, and
     * the whole point of STO-004 is that a settings screen naming a variable
     * nothing reads is worse than a screen naming none.
     *
     * Moving an installation to object storage is therefore two saves, and
     * deliberately so: set `SANITUBE_STORAGE_PROVIDER`, and the screen comes
     * back asking for that provider's own variables. In between it honestly
     * reports the new provider as unconfigured, which is exactly what it is.
     *
     * @return list<WritableSetting>
     */
    private function storage(): array
    {
        $settings = [
            // Constrained to the providers this build actually has an adapter
            // for. A free-text provider name is a storage layer that resolves
            // to nothing on the next request.
            new WritableSetting(
                'SANITUBE_STORAGE_PROVIDER',
                'storage.default',
                false,
                ['string', 'in:'.implode(',', $this->storageProviders->names())],
            ),
        ];

        foreach ($this->storageProviders->selectedFields() as $field) {
            $settings[] = new WritableSetting(
                $field->variable,
                $field->configPath,
                $field->isSecret(),
                $field->field->rules(),
            );
        }

        return $settings;
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
