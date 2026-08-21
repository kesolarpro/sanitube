<?php

declare(strict_types=1);

namespace SaniTube\Foundation\Contracts;

/**
 * An exception whose reason is safe to show an operator.
 *
 * The standing rule across the platform is that **nothing a provider or an HTTP
 * client says reaches a person** — see `ProviderFailure`. An engine's message quotes a
 * traceback, and a client exception quotes the request that produced it, which
 * on a configured deployment carries an address and a token header.
 *
 * That rule is about *text*. A **code from a closed set this repository
 * defines** is a different thing: it was not written by a supplier, it cannot
 * contain a credential, and it is the only useful thing an operator can be told
 * about a failure on the far side of a worker.
 *
 * So an adapter that has one implements this, and the caller passes it through
 * instead of flattening it to "could not be reached". Deliberately an interface
 * rather than a check for one class: the next adapter with a closed refusal set
 * should not require editing the caller.
 *
 * **In Foundation, not in a module.** It was born in MusicGeneration and moved
 * here the moment the worker boundary needed it too: a general boundary that
 * imported from one subsystem to describe a general idea would be a boundary
 * that is general only until the second subsystem arrives. Found by a test that
 * scans `src/Worker` for the names of things it must not know about.
 */
interface CarriesRefusalCode
{
    /**
     * A machine-readable code, never a sentence and never supplier text.
     */
    public function refusalCode(): string;
}
