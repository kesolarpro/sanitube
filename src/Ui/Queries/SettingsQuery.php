<?php

declare(strict_types=1);

namespace SaniTube\Ui\Queries;

use Illuminate\Contracts\Config\Repository;

/**
 * What this installation is configured with — and never what it is configured
 * *to*.
 *
 * Every credential on this screen is reported as one of two words: **configured**
 * or **not configured**. Not masked, not truncated, not four asterisks and the
 * last two characters, not a length. A masked secret is still a secret with
 * information removed, and the removed part is the part an attacker was going
 * to guess anyway — the length of an API key narrows a search, and the last
 * four characters of a token confirm one.
 *
 * What *is* published is provenance, and it is not a secret:
 *
 *   - which provider is selected — a label has to know whose service holds
 *     their masters;
 *   - which environment variable a value comes from — that is documentation,
 *     and it is in the repository already;
 *   - whether a value is present.
 *
 * The three vocabularies are kept apart with the same care the health screens
 * use, because they answer different questions:
 *
 *   - **Not configured** — the variable is empty. A fact about this machine.
 *   - **Unavailable** — a provider is named that this installation has no code
 *     for. A configuration mistake, not a missing credential.
 *   - **Unknown** — never used here. This screen reads configuration, which is
 *     always readable; it is the *health* screens that have to say "unknown",
 *     because they report on things that can be unreachable.
 *
 * **Nothing here probes anything.** No provider call, no bucket round trip.
 * Whether a configured key actually works is a question for the operations
 * screen, and answering it here would mean an outage takes the settings page
 * down with it — the one page somebody opens to find out what is set.
 */
final readonly class SettingsQuery
{
    public function __construct(private Repository $config) {}

    /**
     * @return array<string, mixed>
     */
    public function overview(): array
    {
        return [
            'application' => $this->application(),
            'sections' => [
                $this->storage(),
                $this->ai(),
                $this->generation(),
                $this->distribution(),
                $this->api(),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function application(): array
    {
        return [
            'name' => (string) $this->config->get('app.name'),
            'environment' => (string) $this->config->get('app.env'),

            // Published because it is the single setting whose being wrong is
            // both invisible and serious: debug on in production puts stack
            // traces, queries and environment values in front of whoever
            // triggers an error.
            'debug' => (bool) $this->config->get('app.debug'),

            'locale' => (string) $this->config->get('app.locale'),
            'locales' => array_values((array) $this->config->get('localization.supported', [])),

            // Whether `config:cache` has been run. It changes what editing a
            // .env file does — nothing, until the cache is rebuilt — and
            // somebody looking at this screen after an edit needs to know.
            'config_cached' => $this->configIsCached(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function storage(): array
    {
        $selected = (string) $this->config->get('storage.default', 'local');
        /** @var array<string, mixed> $providers */
        $providers = (array) $this->config->get('storage.providers', []);

        return $this->section(
            key: 'storage',
            selected: $selected,
            known: array_key_exists($selected, $providers),
            options: array_keys($providers),
            settings: [
                $this->plain('SANITUBE_TEMPORARY_URL_TTL', (string) $this->config->get('storage.temporary_url_ttl')),
            ],
            // Local storage needs no credential at all, which is a fact worth
            // showing rather than an empty list to puzzle over.
            //
            // The disk is looked up rather than assumed: r2 and b2 are
            // S3-compatible but configured on their own disks, and reading
            // s3's credentials for all three would report a b2 installation
            // as unconfigured while it works.
            secrets: $selected === 'local' ? [] : $this->diskSecrets(
                (string) $this->config->get('storage.providers.'.$selected.'.disk', $selected),
            ),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function ai(): array
    {
        $selected = (string) $this->config->get('ai.default', 'none');
        /** @var array<string, mixed> $providers */
        $providers = (array) $this->config->get('ai.providers', []);

        return $this->section(
            key: 'ai',
            selected: $selected,
            known: array_key_exists($selected, $providers),
            options: array_keys($providers),
            // The base URL is a *setting*, not a credential. Which endpoint
            // an installation talks to is exactly the thing an operator needs
            // to see, and hiding it behind "configured" would make a
            // misdirected integration impossible to diagnose from here.
            settings: match ($selected) {
                'openai' => [$this->plain('SANITUBE_OPENAI_BASE_URL', (string) $this->config->get('ai.providers.openai.base_url'))],
                'claude' => [$this->plain('SANITUBE_CLAUDE_BASE_URL', (string) $this->config->get('ai.providers.claude.base_url'))],
                default => [],
            },
            secrets: match ($selected) {
                'openai' => [$this->secret('SANITUBE_OPENAI_KEY', 'ai.providers.openai.key')],
                'claude' => [$this->secret('SANITUBE_CLAUDE_KEY', 'ai.providers.claude.key')],
                default => [],
            },
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function generation(): array
    {
        $selected = (string) $this->config->get('generation.default', 'none');
        /** @var array<string, mixed> $providers */
        $providers = (array) $this->config->get('generation.providers', []);

        return $this->section(
            key: 'generation',
            selected: $selected,
            known: array_key_exists($selected, $providers),
            options: array_keys($providers),
            settings: [
                $this->plain('SANITUBE_GENERATION_MAX_POLLS', (string) $this->config->get('generation.poll.max_polls')),
                $this->plain('SANITUBE_GENERATION_POLL_INTERVAL', (string) $this->config->get('generation.poll.interval_seconds')),
            ],
            secrets: [],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function distribution(): array
    {
        $selected = (string) $this->config->get('distribution.default', 'none');
        /** @var array<string, mixed> $providers */
        $providers = (array) $this->config->get('distribution.distributors', []);

        return $this->section(
            key: 'distribution',
            selected: $selected,
            known: array_key_exists($selected, $providers),
            options: array_keys($providers),
            settings: [],
            secrets: [],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function api(): array
    {
        return $this->section(
            key: 'api',
            selected: (string) $this->config->get('sanitube.api.default_version', 'v1'),
            known: true,
            options: [],
            settings: [
                $this->plain('SANITUBE_API_PREFIX', (string) $this->config->get('sanitube.api.prefix')),
                $this->plain('SANITUBE_API_RATE_LIMIT', (string) $this->config->get('sanitube.api.rate_limit_per_minute')),
            ],
            secrets: [
                $this->secret('SANITUBE_INTERNAL_API_TOKEN', 'sanitube.api.internal_token'),
                $this->secret('SANITUBE_HEALTH_TOKEN', 'sanitube.health.token'),
            ],
        );
    }

    /**
     * @param  list<string>  $options
     * @param  list<array<string, mixed>>  $settings
     * @param  list<array<string, mixed>>  $secrets
     * @return array<string, mixed>
     */
    private function section(
        string $key,
        string $selected,
        bool $known,
        array $options,
        array $settings,
        array $secrets,
    ): array {
        return [
            'key' => $key,
            'selected' => $selected,
            // A named provider this installation has no code for. Distinct
            // from a missing credential, and a different thing to fix.
            'known' => $known,
            'options' => $options,
            'settings' => $settings,
            'secrets' => $secrets,
            'complete' => $known && ! $this->anyMissing($secrets),
        ];
    }

    /**
     * The credentials a disk needs, whichever S3-compatible disk it is.
     *
     * @return list<array<string, mixed>>
     */
    private function diskSecrets(string $disk): array
    {
        return [
            $this->secret('AWS_ACCESS_KEY_ID', 'filesystems.disks.'.$disk.'.key'),
            $this->secret('AWS_SECRET_ACCESS_KEY', 'filesystems.disks.'.$disk.'.secret'),
            $this->secret('AWS_BUCKET', 'filesystems.disks.'.$disk.'.bucket'),
        ];
    }

    /**
     * A credential, reported as present or absent and nothing else.
     *
     * Presence is read through **config, never `env()`**. An installation that
     * has run `config:cache` — which every production install should — gets
     * null from `env()` for everything, and this screen would report a working
     * platform as entirely unconfigured. The variable name travels only as
     * documentation: it is what an operator edits, and it is in the repository
     * already.
     *
     * @return array<string, mixed>
     */
    private function secret(string $variable, string $configPath): array
    {
        $value = $this->config->get($configPath);

        return [
            'variable' => $variable,
            'secret' => true,
            // The whole point of this class. No value, no mask, no length —
            // a length narrows a search and a suffix confirms a guess.
            'configured' => is_string($value) && trim($value) !== '',
        ];
    }

    /**
     * A setting whose value is not a credential and is useful to see.
     *
     * @return array<string, mixed>
     */
    private function plain(string $variable, string $value): array
    {
        return [
            'variable' => $variable,
            'secret' => false,
            'configured' => trim($value) !== '',
            'value' => $value,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $secrets
     */
    private function anyMissing(array $secrets): bool
    {
        foreach ($secrets as $secret) {
            if ($secret['configured'] === false) {
                return true;
            }
        }

        return false;
    }

    private function configIsCached(): bool
    {
        return file_exists(base_path('bootstrap/cache/config.php'));
    }
}
