<?php

declare(strict_types=1);

namespace SaniTube\MusicGeneration\Services;

use SaniTube\MusicGeneration\Contracts\MusicGenerationProvider;
use SaniTube\MusicGeneration\Enums\GenerationCapability;
use SaniTube\MusicGeneration\Exceptions\GenerationException;
use SaniTube\MusicGeneration\Exceptions\UnknownGenerationProvider;
use SaniTube\MusicGeneration\MusicGenerationManager;

/**
 * Which supplier this request should go to.
 *
 * Until GEN-006 the answer was "the default one", which is the same answer as
 * "the only one" and reads identically right up to the day it is wrong. The
 * research that preceded this ticket found candidate providers differing in
 * whether they are asynchronous at all, so a platform that means to survive a
 * supplier has to be able to choose between several — and choosing means
 * having reasons.
 *
 * Four of them, applied in this order:
 *
 *   1. **Capability.** A provider that cannot sing lyrics must not be handed
 *      lyrics. See {@see ProviderCapabilities}.
 *   2. **Availability.** Credentials present, terms accepted.
 *   3. **Circuit state.** A provider that has just failed repeatedly is passed
 *      over *while another one can serve the request* — which is the point.
 *      GEN-005 made the breaker refuse; this makes it re-route.
 *   4. **Operator preference.** Configured order, default first.
 *
 * **Ceilings are not here.** They are a property of the installation rather
 * than of a supplier, so no provider is more or less viable for having reached
 * one, and moving a request to a second provider to get around a ceiling would
 * defeat the ceiling. {@see StartMusicGeneration} checks it separately.
 *
 * The refusal is chosen to be the most actionable one available: when nothing
 * is viable, the caller is told what is wrong with the *best* candidate rather
 * than with the last one examined, because "no provider is capable" is a
 * sentence nobody can act on.
 */
final readonly class SelectGenerationProvider
{
    public function __construct(
        private MusicGenerationManager $providers,
        private ProviderCapabilities $capabilities,
        private GenerationCircuitBreaker $breaker,
        /** @var list<string> operator preference order, most preferred first */
        private array $preference = [],
    ) {}

    /**
     * The provider to use, or a refusal explaining why there is none.
     *
     * A named provider is honoured or refused, never silently replaced. An
     * operator who asked for one supplier and got another would have no way to
     * know it happened, and the generation row would record a decision nobody
     * made.
     *
     * @param  list<GenerationCapability>  $required
     * @param  list<string>  $except  providers already tried for this piece of work
     *
     * @throws GenerationException
     * @throws UnknownGenerationProvider when a named provider is not configured
     */
    public function select(array $required = [], ?string $preferred = null, array $except = []): MusicGenerationProvider
    {
        if ($preferred !== null) {
            $provider = $this->providers->provider($preferred);

            $this->assertUsable($provider, $required);

            return $provider;
        }

        $refusal = null;

        foreach ($this->order() as $name) {
            if (in_array($name, $except, true)) {
                continue;
            }

            try {
                $provider = $this->providers->provider($name);
            } catch (UnknownGenerationProvider) {
                // Named in the preference list and not configured. A typo in
                // one entry must not stop the entries after it from being
                // considered.
                continue;
            }

            try {
                $this->assertUsable($provider, $required);
            } catch (GenerationException $exception) {
                // The first refusal encountered is the one from the most
                // preferred provider, and therefore the one worth reporting.
                $refusal ??= $exception;

                continue;
            }

            return $provider;
        }

        throw $refusal ?? GenerationException::noCapableProvider($required);
    }

    /**
     * Every provider that could serve this request right now, most preferred
     * first.
     *
     * This is what makes a failed generation recoverable rather than lost: a
     * caller holding a piece of work whose provider failed can ask what else
     * would do, passing the ones already tried as `$except`. It is also what an
     * interface lists when it offers a choice.
     *
     * @param  list<GenerationCapability>  $required
     * @param  list<string>  $except
     * @return list<string>
     */
    public function candidates(array $required = [], array $except = []): array
    {
        $viable = [];

        foreach ($this->order() as $name) {
            if (in_array($name, $except, true)) {
                continue;
            }

            try {
                $provider = $this->providers->provider($name);
                $this->assertUsable($provider, $required);
            } catch (GenerationException|UnknownGenerationProvider) {
                continue;
            }

            $viable[] = $name;
        }

        return $viable;
    }

    /**
     * What each configured provider can do, for a screen that has to explain
     * why an option is missing.
     *
     * Unresolvable providers are reported as capable of nothing rather than
     * omitted, because a provider named in configuration and absent from the
     * interface is the kind of discrepancy an operator spends an afternoon on.
     *
     * @return array<string, list<string>>
     */
    public function inventory(): array
    {
        $inventory = [];

        // Every configured provider, not just the authorised ones -- this
        // answers "what could this installation use", which is the question a
        // settings screen is for. {@see order()} answers the other one.
        foreach ([...$this->order(), ...$this->providers->names()] as $name) {
            if (isset($inventory[$name])) {
                continue;
            }

            try {
                $capabilities = $this->capabilities->for($this->providers->provider($name));
            } catch (UnknownGenerationProvider) {
                $capabilities = [];
            }

            $inventory[$name] = array_map(
                static fn (GenerationCapability $capability): string => $capability->value,
                $capabilities,
            );
        }

        return $inventory;
    }

    /**
     * @param  list<GenerationCapability>  $required
     *
     * @throws GenerationException
     */
    public function assertUsable(MusicGenerationProvider $provider, array $required = []): void
    {
        // Availability first. On a fresh install every provider is
        // unconfigured, and "generation is not set up" is a more useful thing
        // to be told than "nothing here can sing".
        if (! $provider->isAvailable()) {
            throw GenerationException::providerUnavailable($provider->name());
        }

        $missing = $this->capabilities->missing($provider, $required);

        if ($missing !== []) {
            throw GenerationException::providerIncapable($provider->name(), $missing);
        }

        if ($this->breaker->isOpenFor($provider->name())) {
            throw GenerationException::providerCircuitOpen($provider->name());
        }
    }

    /**
     * The providers this installation has actually authorised, most preferred
     * first: the operator's preference list, then the default.
     *
     * **Everything else configured is deliberately absent, and this is the
     * sharpest edge in the class.** `generation.providers` ships with `fake`
     * in it, because the fake is what makes the Studio demonstrable without a
     * supplier account. Treating that entry as a fallback would mean a fresh
     * installation -- whose default is `none` -- quietly answering a request
     * for music with synthetic audio, and the operator would have no reason to
     * suspect it. A configured entry says a provider *can* be selected; it
     * does not say it *may* be used.
     *
     * So falling back is opt-in. An operator who wants a second supplier tried
     * when the first is failing names it in `generation.preference`, which is
     * a sentence somebody wrote on purpose.
     *
     * The default is appended even when the preference list omits it: a
     * preference is a statement about order, and an installation that lost its
     * only working provider by expressing one would be a trap of the opposite
     * kind.
     *
     * @return list<string>
     */
    public function order(): array
    {
        $ordered = [];

        foreach ([...$this->preference, $this->providers->defaultName()] as $name) {
            $name = MusicGenerationManager::normaliseProviderName($name);

            if (! in_array($name, $ordered, true)) {
                $ordered[] = $name;
            }
        }

        return $ordered;
    }
}
