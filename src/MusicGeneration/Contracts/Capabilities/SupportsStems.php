<?php

declare(strict_types=1);

namespace SaniTube\MusicGeneration\Contracts\Capabilities;

use SaniTube\MusicGeneration\ProviderResult;

/**
 * A provider that can separate a generation into stems.
 *
 * Declared, unimplemented, and deliberately outside the base contract — see
 * {@see SupportsExtend} for why. Stems are how a track becomes remixable and
 * sync-licensable, so the capability is named now rather than invented later
 * inside whichever adapter needs it first.
 */
interface SupportsStems
{
    /**
     * @return list<ProviderResult>
     */
    public function fetchStems(string $providerJobId): array;
}
