<?php

declare(strict_types=1);

namespace SaniTube\Distribution\Enums;

/**
 * Where a delivery's external reference came from.
 *
 * The identifier a distributor uses for a package is the handle every later
 * poll and takedown goes through, and until this existed the platform could
 * not tell three very different things apart:
 *
 *   - **`PROVIDER_RESPONSE`** — the distributor returned it when the release
 *     was handed over. The ordinary case, and the only one where SaniTube
 *     watched the value arrive.
 *   - **`PROVIDER_LOOKUP`** — the distributor returned it when asked what it
 *     held under our idempotency key. Also from the provider, but *after* a
 *     lost response, which is worth being able to see when reconstructing what
 *     happened.
 *   - **`MANUAL_OPERATOR`** — a person read it off the distributor's dashboard
 *     and typed it in. **The platform never received this value.**
 *
 * The third is the reason the column exists. A hand-typed reference that looks
 * exactly like a received one invites everything downstream to treat it as
 * provider-verified, and a typo in it produces a delivery nobody can poll or
 * take down — which is discovered months later, by a release that will not
 * come off a store.
 *
 * The interface says so where it shows the reference. This is not a warning
 * about the operator; it is the difference between a fact and a report of one.
 */
enum ExternalReferenceSource: string
{
    case ProviderResponse = 'PROVIDER_RESPONSE';
    case ProviderLookup = 'PROVIDER_LOOKUP';
    case ManualOperator = 'MANUAL_OPERATOR';

    /**
     * Whether the platform saw this value come from the distributor itself.
     */
    public function isFromProvider(): bool
    {
        return $this !== self::ManualOperator;
    }
}
