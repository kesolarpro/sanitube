<?php

declare(strict_types=1);

namespace SaniTube\Storage;

use Illuminate\Contracts\Config\Repository;
use SaniTube\Storage\Enums\StorageField;

/**
 * Which environment variables configure the selected storage provider.
 *
 * **STO-004 exists because this question had no owner.** The settings screen
 * answered it by assuming every S3-compatible provider reads Amazon's
 * variables: it checked presence on the right disk — `filesystems.disks.r2.key`
 * — and then printed `AWS_ACCESS_KEY_ID` as the thing to set. On an R2
 * installation that variable is read by nothing. An operator following the
 * screen would set it, watch the same "not configured" come back, set it
 * again, and reasonably conclude the platform was broken. The credentials were
 * right there in `R2_ACCESS_KEY_ID`, named nowhere the operator could see.
 *
 * So the names became data — declared per provider in `config/storage.php` —
 * and this class is what reads them. Two rules make that safe:
 *
 *   - **The vocabulary is closed.** A declaration naming anything outside
 *     {@see StorageField} is dropped, not rendered. Configuration does not get
 *     to add `url` and turn the bucket public.
 *   - **Nothing is inferred from a driver.** A provider declares its variables
 *     or it has none, which is exactly the local disk's honest answer.
 *
 * Nothing here probes or connects. Whether a configured key works is the
 * operations screen's question; answering it here would take the settings page
 * down with the storage service — the one page somebody opens to find out why.
 */
final readonly class ProviderConfiguration
{
    public function __construct(private Repository $config) {}

    /**
     * The provider this installation stores masters on.
     */
    public function selected(): string
    {
        return (string) $this->config->get('storage.default', 'local');
    }

    /**
     * Every provider this build has an adapter for.
     *
     * @return list<string>
     */
    public function names(): array
    {
        /** @var array<string, mixed> $providers */
        $providers = (array) $this->config->get('storage.providers', []);

        return array_values(array_filter(array_keys($providers), 'is_string'));
    }

    /**
     * Whether a name is one of them.
     *
     * A provider that is named and absent is a configuration mistake, and a
     * different thing to fix than a missing credential.
     */
    public function isKnown(string $provider): bool
    {
        return in_array($provider, $this->names(), true);
    }

    /**
     * The Laravel disk a provider stores on.
     *
     * Looked up rather than assumed: R2 and B2 are S3-compatible but live on
     * their own disks, and reading s3's would report a working B2 install as
     * unconfigured.
     */
    public function disk(string $provider): string
    {
        return (string) $this->config->get('storage.providers.'.$provider.'.disk', $provider);
    }

    /**
     * What an operator can set for a provider, in declaration order.
     *
     * Credentials first, then addresses, because that is the order they are
     * declared in and the order somebody fills a form in. An unknown provider
     * — or the local disk, which genuinely needs no credential — yields an
     * empty list, and an empty list is a fact worth showing rather than a form
     * to puzzle over.
     *
     * @return list<ProviderField>
     */
    public function fields(string $provider): array
    {
        $disk = $this->disk($provider);
        $fields = [];
        $seen = [];

        foreach (['credentials', 'settings'] as $group) {
            /** @var array<mixed, mixed> $declared */
            $declared = (array) $this->config->get('storage.providers.'.$provider.'.'.$group, []);

            foreach ($declared as $role => $variable) {
                if (! is_string($role) || ! is_string($variable) || trim($variable) === '') {
                    continue;
                }

                // The closed vocabulary. `url` is not in it, and a provider
                // entry that names one is ignored rather than offered.
                $field = StorageField::tryFrom($role);

                if ($field === null || isset($seen[$field->value])) {
                    continue;
                }

                $seen[$field->value] = true;

                $fields[] = new ProviderField(
                    $field,
                    $variable,
                    'filesystems.disks.'.$disk.'.'.$field->value,
                );
            }
        }

        return $fields;
    }

    /**
     * The same, for whichever provider is in use.
     *
     * @return list<ProviderField>
     */
    public function selectedFields(): array
    {
        return $this->fields($this->selected());
    }
}
