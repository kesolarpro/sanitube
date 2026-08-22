<?php

declare(strict_types=1);

namespace SaniTube\Ui\Settings;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Validation\ValidationRule;
use SaniTube\Storage\ProviderConfiguration;
use SaniTube\Ui\Queries\SettingsQuery;
use SaniTube\Ui\Settings\Rules\OutsideTheWebRoot;

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
    public function __construct(
        private ProviderConfiguration $storageProviders,
        private SelectableProviders $providers,
        private Repository $config,
    ) {}

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
            ...$this->ai(),

            // --- generation. CFG-002: the engine itself is chosen here, from
            // what this build declares. Both polling bounds exist so that a
            // provider which never answers costs a fixed amount of work — the
            // failure GEN-001 exists to prevent.
            new WritableSetting('SANITUBE_GENERATION_PROVIDER', 'generation.default', false, ['string', $this->providers->rule('generation')]),
            // CFG-005. The same ceilings for the supplier that costs the most
            // per call. Zero means no ceiling here too.
            new WritableSetting('SANITUBE_GENERATION_DAILY_LIMIT', 'generation.limits.daily', false, ['integer', 'min:0', 'max:100000']),
            new WritableSetting('SANITUBE_GENERATION_WEEKLY_LIMIT', 'generation.limits.weekly', false, ['integer', 'min:0', 'max:100000']),
            new WritableSetting('SANITUBE_GENERATION_MONTHLY_LIMIT', 'generation.limits.monthly', false, ['integer', 'min:0', 'max:100000']),
            new WritableSetting('SANITUBE_GENERATION_CIRCUIT_FAILURES', 'generation.circuit.consecutive_failures', false, ['integer', 'min:1', 'max:1000']),
            new WritableSetting('SANITUBE_GENERATION_CIRCUIT_COOLDOWN', 'generation.circuit.cooldown_minutes', false, ['integer', 'min:1', 'max:1440']),
            new WritableSetting('SANITUBE_GENERATION_MAX_POLLS', 'generation.poll.max_polls', false, ['integer', 'min:1', 'max:500']),
            new WritableSetting('SANITUBE_GENERATION_POLL_INTERVAL', 'generation.poll.interval_seconds', false, ['integer', 'min:5', 'max:3600']),

            // --- artwork. CFG-006: the provider, its credential, what a
            // cover has to be, and what generating one may cost.
            ...$this->artwork(),

            // --- transcription. CFG-006: the provider, its own credential,
            // and the switch that decides whether every stored master is
            // transcribed on arrival.
            ...$this->transcription(),

            // --- distribution. A distributor with no credentials of its own
            // yet, so the choice is the whole surface.
            new WritableSetting('SANITUBE_DISTRIBUTOR', 'distribution.default', false, ['string', $this->providers->rule('distribution')]),

            // --- API. Both are bearer credentials for server-to-server calls.
            // CFG-001. The worker, addressable from the dashboard rather than
            // only over SSH. The URL is validated as a URL and nothing more:
            // a worker legitimately lives on a private address, and a rule
            // that demanded a public one would refuse the deployment this
            // platform documents.
            new WritableSetting('SANITUBE_WORKER_URL', 'worker.url', false, ['url', 'max:255']),
            new WritableSetting('SANITUBE_WORKER_TOKEN', 'worker.token', true, ['string', 'min:32', 'max:255']),
            new WritableSetting('SANITUBE_WORKER_IDENTITY', 'worker.identity', false, ['string', 'max:64']),

            // CFG-002. Which execution path media work takes. It is a
            // preference, not a capability: config/media.php documents forcing
            // `local` on a host whose CPU limit ffprobe trips, and that is a
            // decision an operator makes and then wants to see. Availability is
            // never inferred from it -- a worker is used only when its
            // handshake advertises the capability -- so the worst a wrong value
            // can do is refuse work rather than attempt something impossible.
            new WritableSetting('SANITUBE_MEDIA_EXECUTION', 'media.execution', false, ['string', 'in:auto,local,remote_worker']),

            // Mail, which password resets depend on. The password is a secret
            // like any other; the rest is what an operator needs to see to
            // know which relay they are looking at.
            new WritableSetting('MAIL_HOST', 'mail.mailers.smtp.host', false, ['string', 'max:255']),
            new WritableSetting('MAIL_PORT', 'mail.mailers.smtp.port', false, ['integer', 'min:1', 'max:65535']),
            new WritableSetting('MAIL_USERNAME', 'mail.mailers.smtp.username', false, ['string', 'max:255']),
            new WritableSetting('MAIL_PASSWORD', 'mail.mailers.smtp.password', true, ['string', 'max:255']),
            new WritableSetting('MAIL_FROM_ADDRESS', 'mail.from.address', false, ['email', 'max:255']),
            new WritableSetting('MAIL_FROM_NAME', 'mail.from.name', false, ['string', 'max:255']),

            // --- audio and media. CFG-004. What this installation will accept
            // and how patiently it inspects it. The upload ceilings were
            // configurable and invisible, which is the combination UPL-004 was
            // about: an operator on a host with a small post_max_size needs to
            // see the number the application promises.
            //
            // The binary *paths* are absent on purpose, and named in
            // SettingsWriteTest with the reason: writing an executable path
            // from a web form turns a stolen session into arbitrary command
            // execution.
            new WritableSetting('SANITUBE_MAX_MASTER_BYTES', 'assets.max_upload_bytes.AUDIO_MASTER', false, ['integer', 'min:0', 'max:17179869184']),
            new WritableSetting('SANITUBE_MAX_DERIVATIVE_BYTES', 'assets.max_upload_bytes.AUDIO_DERIVATIVE', false, ['integer', 'min:0', 'max:17179869184']),
            new WritableSetting('SANITUBE_MAX_ARTWORK_BYTES', 'assets.max_upload_bytes.ARTWORK', false, ['integer', 'min:0', 'max:17179869184']),
            new WritableSetting('SANITUBE_ASSET_PREVIEW_TTL', 'assets.preview.ttl_seconds', false, ['integer', 'min:60', 'max:86400']),
            new WritableSetting('SANITUBE_STAGING_TTL_HOURS', 'assets.staging.ttl_hours', false, ['integer', 'min:1', 'max:720']),
            new WritableSetting('SANITUBE_MEDIA_ANALYSIS_REQUIRED', 'media.analysis_required', false, ['string', 'in:true,false']),
            new WritableSetting('SANITUBE_FFPROBE_TIMEOUT', 'media.ffprobe.timeout', false, ['integer', 'min:5', 'max:3600']),
            new WritableSetting('SANITUBE_FPCALC_TIMEOUT', 'media.fingerprint.timeout', false, ['integer', 'min:5', 'max:3600']),

            // --- queue. The *connection* is absent, and named with its
            // reason: repointing a running installation's queue does not move
            // the jobs already sitting in the old one. They are simply never
            // run again, and the screen would report success.
            new WritableSetting('SANITUBE_BACKLOG_CEILING', 'operations.backlog.ceiling', false, ['integer', 'min:1', 'max:10000000']),

            // --- backup. The destination is checked here as well as at backup
            // time: a form that accepts a path the next run will refuse is a
            // form that lets somebody save a configuration which fails at two
            // in the morning.
            new WritableSetting('SANITUBE_BACKUP_PATH', 'backup.destination', false, ['string', 'max:4096', new OutsideTheWebRoot(public_path())]),
            new WritableSetting('SANITUBE_BACKUP_KEEP', 'backup.keep', false, ['integer', 'min:1', 'max:365']),
            // An empty value is how an operator turns the scheduled backup off,
            // and blank means unchanged on this form — so switching it off
            // stays a .env edit, like every other removal here.
            new WritableSetting('SANITUBE_BACKUP_AT', 'backup.schedule_at', false, ['date_format:H:i']),

            // --- production automation. How long a claimed occasion stays
            // claimed. What a plan is *allowed* to do is not here: that belongs
            // to the plan, per plan, and a global switch would change what
            // every plan may do at once.
            new WritableSetting('SANITUBE_PRODUCTION_CLAIM_LEASE_SECONDS', 'production.claim_lease_seconds', false, ['integer', 'min:60', 'max:86400']),
            new WritableSetting('SANITUBE_PRODUCTION_RECLAIM_BATCH', 'production.reclaim_batch', false, ['integer', 'min:1', 'max:10000']),

            // --- system. The thresholds the health screens judge against.
            new WritableSetting('SANITUBE_DISK_WARN_MB', 'operations.disk.warn_below_mb', false, ['integer', 'min:1', 'max:1048576']),
            new WritableSetting('SANITUBE_DISK_BLOCKER_MB', 'operations.disk.blocker_below_mb', false, ['integer', 'min:1', 'max:1048576']),

            new WritableSetting('SANITUBE_INTERNAL_API_TOKEN', 'sanitube.api.internal_token', true, ['string', 'min:32', 'max:255']),
            new WritableSetting('SANITUBE_HEALTH_TOKEN', 'sanitube.health.token', true, ['string', 'min:32', 'max:255']),
            new WritableSetting('SANITUBE_API_RATE_LIMIT', 'sanitube.api.rate_limit_per_minute', false, ['integer', 'min:1', 'max:10000']),
        ];
    }

    /**
     * The AI fields, which depend on which provider is in use.
     *
     * CFG-002. Gated on the selection for the same reason storage is: the
     * screen only publishes the selected provider's variables, and a writer
     * that owned all of them made the two lists disagree in every
     * configuration — writable, invisible, and therefore impossible to change
     * from the one screen that offers to change it.
     *
     * @return list<WritableSetting>
     */
    private function ai(): array
    {
        $settings = [
            new WritableSetting(
                'SANITUBE_AI_PROVIDER',
                'ai.default',
                false,
                ['string', $this->providers->rule('ai')],
            ),
        ];

        // Declared per provider rather than assumed: a provider that arrives
        // with different variable names gets them here, and one that needs
        // none — the null provider — honestly offers none.
        $variables = match ((string) $this->config->get('ai.default', 'none')) {
            'openai' => [
                new WritableSetting('SANITUBE_OPENAI_KEY', 'ai.providers.openai.key', true, ['string', 'max:255']),
                new WritableSetting('SANITUBE_OPENAI_BASE_URL', 'ai.providers.openai.base_url', false, ['url', 'max:255']),
                // CFG-005. Never a closed list. Vendors publish new models
                // faster than this platform ships, and an `in:` rule here
                // would mean a release is required before an operator can use
                // the model they are already paying for.
                new WritableSetting('SANITUBE_OPENAI_MODEL', 'ai.providers.openai.model', false, ['string', 'max:128']),
            ],
            'claude' => [
                new WritableSetting('SANITUBE_CLAUDE_KEY', 'ai.providers.claude.key', true, ['string', 'max:255']),
                new WritableSetting('SANITUBE_CLAUDE_BASE_URL', 'ai.providers.claude.base_url', false, ['url', 'max:255']),
                new WritableSetting('SANITUBE_CLAUDE_MODEL', 'ai.providers.claude.model', false, ['string', 'max:128']),
            ],
            default => [],
        };

        // CFG-005. The ceilings, which apply whichever provider is selected.
        // **Zero has to stay sayable**: it means no ceiling, it is the shipped
        // default, and a `min:1` here would quietly make the shipped
        // configuration unrepresentable on the form that edits it.
        $limits = [
            new WritableSetting('SANITUBE_AI_DAILY_CALLS', 'ai.limits.daily', false, ['integer', 'min:0', 'max:1000000']),
            new WritableSetting('SANITUBE_AI_WEEKLY_CALLS', 'ai.limits.weekly', false, ['integer', 'min:0', 'max:1000000']),
            new WritableSetting('SANITUBE_AI_MONTHLY_CALLS', 'ai.limits.monthly', false, ['integer', 'min:0', 'max:1000000']),
            new WritableSetting('SANITUBE_AI_TIMEOUT', 'ai.timeout', false, ['integer', 'min:1', 'max:600']),
            new WritableSetting('SANITUBE_AI_MAX_OUTPUT_TOKENS', 'ai.max_output_tokens', false, ['integer', 'min:1', 'max:100000']),
            new WritableSetting('SANITUBE_AI_CIRCUIT_FAILURES', 'ai.circuit.consecutive_failures', false, ['integer', 'min:1', 'max:1000']),
            new WritableSetting('SANITUBE_AI_CIRCUIT_COOLDOWN_MINUTES', 'ai.circuit.cooldown_minutes', false, ['integer', 'min:1', 'max:1440']),
        ];

        return [...$settings, ...$variables, ...$limits];
    }

    /**
     * Covers, and what one is allowed to be.
     *
     * CFG-006. The requirement thresholds are writable because they are
     * settings rather than facts — every store publishes its own specification
     * and they disagree at the edges, so an operator delivering somewhere with
     * different rules changes a number here instead of patching a validator.
     *
     * **Zero has to stay sayable** in every one of them. It means "no
     * requirement" for the thresholds and "no ceiling" for the limits, it is
     * the shipped default for four of them, and a `min:1` anywhere here would
     * make the configuration this platform ships unrepresentable on the form
     * that edits it. `minimum_pixels` is the exception and is bounded below by
     * 0 rather than by a real minimum, because a cover requirement of zero is
     * a legitimate decision for an installation that validates artwork
     * elsewhere.
     *
     * @return list<WritableSetting>
     */
    private function artwork(): array
    {
        $settings = [
            new WritableSetting('SANITUBE_ARTWORK_PROVIDER', 'artwork.default_provider', false, ['string', $this->providers->rule('artwork')]),
            new WritableSetting('SANITUBE_ARTWORK_GENERATION_ENABLED', 'artwork.generation_enabled', false, ['string', 'in:true,false']),
        ];

        $variables = match ((string) $this->config->get('artwork.default_provider', 'none')) {
            'openai' => [
                new WritableSetting('SANITUBE_ARTWORK_OPENAI_KEY', 'artwork.providers.openai.key', true, ['string', 'max:255']),
                new WritableSetting('SANITUBE_ARTWORK_OPENAI_BASE_URL', 'artwork.providers.openai.base_url', false, ['url', 'max:255']),
                new WritableSetting('SANITUBE_ARTWORK_OPENAI_MODEL', 'artwork.providers.openai.model', false, ['string', 'max:128']),
                // A closed list here, unlike the model, and the difference is
                // real: the three formats are what the platform's own
                // validator accepts, not what a vendor may add to next week.
                new WritableSetting('SANITUBE_ARTWORK_OPENAI_FORMAT', 'artwork.providers.openai.output_format', false, ['string', 'in:png,jpeg,webp']),
                new WritableSetting('SANITUBE_ARTWORK_TIMEOUT', 'artwork.providers.openai.timeout', false, ['integer', 'min:1', 'max:600']),
            ],
            default => [],
        };

        $requirements = [
            new WritableSetting('SANITUBE_ARTWORK_MINIMUM_PIXELS', 'artwork.requirements.minimum_pixels', false, ['integer', 'min:0', 'max:20000']),
            new WritableSetting('SANITUBE_ARTWORK_MAXIMUM_PIXELS', 'artwork.requirements.maximum_pixels', false, ['integer', 'min:0', 'max:20000']),
            new WritableSetting('SANITUBE_ARTWORK_MAXIMUM_BYTES', 'artwork.requirements.maximum_bytes', false, ['integer', 'min:0']),
            new WritableSetting('SANITUBE_ARTWORK_REQUIRE_SQUARE', 'artwork.requirements.must_be_square', false, ['string', 'in:true,false']),
            new WritableSetting('SANITUBE_ARTWORK_REFUSE_CMYK', 'artwork.requirements.refuse_cmyk', false, ['string', 'in:true,false']),
        ];

        $ceilings = [
            new WritableSetting('SANITUBE_ARTWORK_DAILY_LIMIT', 'artwork.limits.daily', false, ['integer', 'min:0', 'max:100000']),
            new WritableSetting('SANITUBE_ARTWORK_WEEKLY_LIMIT', 'artwork.limits.weekly', false, ['integer', 'min:0', 'max:100000']),
            new WritableSetting('SANITUBE_ARTWORK_MONTHLY_LIMIT', 'artwork.limits.monthly', false, ['integer', 'min:0', 'max:100000']),
            // Zero disables the breaker entirely, which config/artwork.php
            // documents as a legitimate setting for an operator who would
            // rather see every failure than have the platform stop asking.
            new WritableSetting('SANITUBE_ARTWORK_CIRCUIT_FAILURES', 'artwork.circuit.consecutive_failures', false, ['integer', 'min:0', 'max:1000']),
            new WritableSetting('SANITUBE_ARTWORK_CIRCUIT_COOLDOWN', 'artwork.circuit.cooldown_minutes', false, ['integer', 'min:1', 'max:1440']),
            // Bounded below by a minute. A claim shorter than one provider
            // call is a request two workers take at once.
            new WritableSetting('SANITUBE_ARTWORK_CLAIM_SECONDS', 'artwork.submission_claim_seconds', false, ['integer', 'min:60', 'max:86400']),
        ];

        return [...$settings, ...$variables, ...$requirements, ...$ceilings];
    }

    /**
     * Transcripts, and the switch that decides how many get paid for.
     *
     * CFG-006. `SANITUBE_TRANSCRIPTION_AUTOMATIC` is the reason this section
     * had to become writable. It is the one setting on this screen that turns
     * a per-file cost on for every master an installation already holds, and
     * it was reachable only over SSH — so an operator who wanted it could not
     * switch it on, and an operator who regretted it could not switch it off.
     *
     * @return list<WritableSetting>
     */
    private function transcription(): array
    {
        $settings = [
            new WritableSetting('SANITUBE_TRANSCRIPTION_PROVIDER', 'transcription.provider', false, ['string', $this->providers->rule('transcription')]),
            new WritableSetting('SANITUBE_TRANSCRIPTION_AUTOMATIC', 'transcription.automatic', false, ['string', 'in:true,false']),
        ];

        $variables = match ((string) $this->config->get('transcription.provider', 'none')) {
            'openai' => [
                // Its own key rather than the shared one. The configuration
                // falls back to the AI module's credentials when this is
                // unset, so an installation wanting a separate project or a
                // separate budget sets this and the fallback stops applying.
                new WritableSetting('SANITUBE_TRANSCRIPTION_OPENAI_KEY', 'transcription.providers.openai.key', true, ['string', 'max:255']),
                new WritableSetting('SANITUBE_TRANSCRIPTION_OPENAI_BASE_URL', 'transcription.providers.openai.base_url', false, ['url', 'max:255']),
                new WritableSetting('SANITUBE_TRANSCRIPTION_OPENAI_MODEL', 'transcription.providers.openai.model', false, ['string', 'max:128']),
                new WritableSetting('SANITUBE_TRANSCRIPTION_TIMEOUT', 'transcription.providers.openai.timeout', false, ['integer', 'min:1', 'max:3600']),
                // Zero removes the ceiling and leaves the supplier's own
                // refusal as the authority, which config/transcription.php
                // documents as the deliberate meaning of the value.
                new WritableSetting('SANITUBE_TRANSCRIPTION_MAX_BYTES', 'transcription.providers.openai.max_bytes', false, ['integer', 'min:0']),
            ],
            default => [],
        };

        return [...$settings, ...$variables];
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
     * @return array<string, list<string|ValidationRule>>
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
