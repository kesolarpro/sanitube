<?php

declare(strict_types=1);

namespace SaniTube\Distribution\Contracts;

use SaniTube\Distribution\DistributorSubmission;

/**
 * A distributor that can be asked whether it already holds a submission.
 *
 * **A capability, not part of {@see Distributor}.** DIST-001 closed that
 * contract at five methods and said why: a contract demanding a half every
 * adapter cannot do forces every adapter to stub it, and a stub is where a
 * lie lives. An adapter that cannot search its provider's account for an
 * idempotency key should not be made to declare a method it will only ever
 * throw from — the *type* should say it cannot, so the service can ask before
 * it calls.
 *
 * That distinction matters more here than in most capability interfaces,
 * because the two "no" answers have opposite consequences:
 *
 *   - **"I looked and hold nothing."** The request never landed. Retrying is
 *     safe, and the delivery goes back to FAILED.
 *   - **"I cannot look."** Nothing is known. Retrying could be a *second*
 *     delivery, which is the outcome this module exists to prevent, and a
 *     person has to check the distributor's own dashboard.
 *
 * With `findSubmission()` on the main contract, the second answer had to
 * travel as an exception thrown from a method the adapter was obliged to
 * declare. It travels as a type now: an adapter that does not implement this
 * interface *is* the second answer, checked before any call is made.
 */
interface SupportsSubmissionLookup
{
    /**
     * Whether this distributor already holds a submission under this key.
     *
     * The answer to "did our request actually arrive" after a timeout, and the
     * only way out of `SUBMITTED_UNCONFIRMED` that does not need a person.
     *
     *   - a {@see DistributorSubmission} — it arrived. Adopt its reference.
     *   - `null` — the distributor looked and holds nothing under this key.
     *     Retrying is safe.
     *
     * There is no third return value. "I cannot look" is not implementing this
     * interface; returning `null` for it would turn "I cannot answer" into
     * "your package is not here", which is how a release gets delivered twice.
     *
     * A transport failure while asking is neither: it is an exception, and the
     * caller leaves the delivery unknown rather than inventing an answer from
     * a question that never completed.
     */
    public function findSubmission(string $idempotencyKey): ?DistributorSubmission;
}
