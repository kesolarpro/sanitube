<?php

declare(strict_types=1);

namespace SaniTube\MusicGeneration\Services;

use SaniTube\MusicGeneration\Contracts\Capabilities\SupportsExtend;
use SaniTube\MusicGeneration\Contracts\Capabilities\SupportsStems;
use SaniTube\MusicGeneration\Contracts\DeclaresCapabilities;
use SaniTube\MusicGeneration\Contracts\MusicGenerationProvider;
use SaniTube\MusicGeneration\Enums\GenerationCapability;

/**
 * What a provider can do, asked rather than assumed.
 *
 * Three sources, in this order, and the order is an argument about evidence.
 *
 *   1. **Structural.** Implementing {@see MusicGenerationProvider} at all means
 *      a prompt goes in and music comes out. It is the only thing the
 *      interface proves, so it is the only thing read off the interface.
 *   2. **Capability interfaces.** `instanceof SupportsExtend` is a method that
 *      exists and can be called. This is the strongest evidence available
 *      short of running it, which is why these are read even for adapters that
 *      also declare a list — a provider cannot forget to mention a method it
 *      implements.
 *   3. **Declaration.** {@see DeclaresCapabilities} is an adapter author saying
 *      what they read in a provider's documentation. Weaker than a method, and
 *      still far better than the platform guessing.
 *
 * Then one subtraction: an operator may **remove** capabilities in
 * configuration, and may not add any. Removal is the real case and it is not
 * rare — a self-hosted deployment with no stem model installed genuinely
 * cannot separate stems however capable the software is, and only the operator
 * knows that. Addition is refused because a capability granted in a config
 * file produces a failure at the provider that looks like a bug in SaniTube,
 * and because it would let an installation quietly overrule the one place that
 * has actually read the contract.
 *
 * **This class deliberately knows nothing about availability, quota or circuit
 * state.** Those are questions about whether a provider may be used now;
 * capability is a question about what it is. A provider with an expired key is
 * still capable, and an interface that hid a feature because a key lapsed
 * would be lying about the product. {@see SelectGenerationProvider} is where
 * the two meet.
 */
final readonly class ProviderCapabilities
{
    /**
     * @param  array<string, array<string, mixed>>  $configuration  the `generation.providers` block
     */
    public function __construct(private array $configuration = []) {}

    /**
     * Everything this provider supports, de-duplicated and in enum order.
     *
     * Enum order rather than discovery order: the set is what matters, and a
     * stable order means two providers can be compared, an interface can list
     * them without shuffling between requests, and a test can assert on them
     * without sorting first.
     *
     * @return list<GenerationCapability>
     */
    public function for(MusicGenerationProvider $provider): array
    {
        $declared = $provider instanceof DeclaresCapabilities ? $provider->capabilities() : null;

        // A provider that says it can do nothing has said something, and it
        // outranks every other source. `none` is not an unconfigured supplier;
        // it is the absence of one, and reporting that it can make music from
        // text would put a working button on a fresh install.
        if ($declared === []) {
            return [];
        }

        $found = [];

        foreach (GenerationCapability::structural() as $capability) {
            $found[$capability->value] = true;
        }

        if ($provider instanceof SupportsExtend) {
            $found[GenerationCapability::Extend->value] = true;
        }

        if ($provider instanceof SupportsStems) {
            $found[GenerationCapability::StemSeparation->value] = true;
        }

        foreach ($declared ?? [] as $capability) {
            $found[$capability->value] = true;
        }

        foreach ($this->withheld($provider->name()) as $capability) {
            unset($found[$capability->value]);
        }

        return array_values(array_filter(
            GenerationCapability::cases(),
            static fn (GenerationCapability $capability): bool => isset($found[$capability->value]),
        ));
    }

    public function supports(MusicGenerationProvider $provider, GenerationCapability $capability): bool
    {
        return in_array($capability, $this->for($provider), true);
    }

    /**
     * @param  list<GenerationCapability>  $required
     */
    public function supportsAll(MusicGenerationProvider $provider, array $required): bool
    {
        return $this->missing($provider, $required) === [];
    }

    /**
     * What the provider would have to gain to serve this request.
     *
     * Returned rather than a bare false, because a refusal that names the
     * missing capability is one an operator can act on — switch provider, or
     * stop asking for stems from one that has none.
     *
     * @param  list<GenerationCapability>  $required
     * @return list<GenerationCapability>
     */
    public function missing(MusicGenerationProvider $provider, array $required): array
    {
        $has = $this->for($provider);

        return array_values(array_filter(
            $required,
            static fn (GenerationCapability $capability): bool => ! in_array($capability, $has, true),
        ));
    }

    /**
     * Capabilities this installation has switched off for a provider.
     *
     * Unparseable names are ignored rather than fatal: a typo in a config file
     * must not stop generation from booting, and the capability it failed to
     * withhold is still checked by the provider itself.
     *
     * @return list<GenerationCapability>
     */
    public function withheld(string $provider): array
    {
        $configured = $this->configuration[$provider]['disabled_capabilities'] ?? null;

        if (! is_array($configured)) {
            return [];
        }

        $withheld = [];

        foreach ($configured as $value) {
            $capability = GenerationCapability::parse($value);

            if ($capability instanceof GenerationCapability) {
                $withheld[] = $capability;
            }
        }

        return $withheld;
    }
}
