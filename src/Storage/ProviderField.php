<?php

declare(strict_types=1);

namespace SaniTube\Storage;

use SaniTube\Storage\Enums\StorageField;

/**
 * One configurable part of one storage provider, resolved.
 *
 * Three facts that were previously assumed to line up and did not: **which**
 * part this is, **which environment variable** carries it on this provider,
 * and **where the value lands** in configuration once it is set.
 *
 * The middle one is the whole point. A settings screen that reads presence
 * from `filesystems.disks.r2.key` and tells the operator to set
 * `AWS_ACCESS_KEY_ID` is not slightly wrong — it is a screen that cannot be
 * obeyed, because the variable it names is read by a disk R2 never touches.
 */
final readonly class ProviderField
{
    /**
     * @param  StorageField  $field  which part of the provider this is
     * @param  string  $variable  the environment variable an operator edits
     * @param  string  $configPath  where the value is read back from
     */
    public function __construct(
        public StorageField $field,
        public string $variable,
        public string $configPath,
    ) {}

    public function isSecret(): bool
    {
        return $this->field->isSecret();
    }
}
