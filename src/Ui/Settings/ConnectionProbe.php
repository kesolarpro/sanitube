<?php

declare(strict_types=1);

namespace SaniTube\Ui\Settings;

/**
 * What a settings screen may ask to have tested.
 *
 * CFG-001. **A closed vocabulary, and only one copy of it.** The alternative —
 * a target naming a provider, a URL or a disk — would turn a settings button
 * into a request the caller composes, which is how a settings screen becomes
 * an SSRF tool inside whatever network the platform runs in.
 *
 * The read model puts one of these words on a section, the form request
 * validates against these cases, and the controller matches on them. Three
 * places, one list: a section that offered a probe the endpoint rejects, or an
 * endpoint that accepted a word no section can produce, would need this file
 * to disagree with itself.
 *
 * Everything a probe needs to *reach* — an endpoint, a bucket, a token — is
 * read from configuration on the server. None of it travels either way.
 */
enum ConnectionProbe: string
{
    case Storage = 'storage';
    case Worker = 'worker';

    /**
     * The vocabulary as a validation rule, derived rather than retyped.
     *
     * @return list<string>
     */
    public static function words(): array
    {
        return array_map(static fn (self $probe): string => $probe->value, self::cases());
    }
}
