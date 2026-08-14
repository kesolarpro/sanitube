# ADR-0004 — Distributor write-side contract deferred to DIST-001

- **Status:** Deferred — resolved by DIST-001
- **Date:** 2026-08-14
- **Ticket:** ARCH-001

## Context

The ARCH-001 specification called for a complete `DistributorInterface`:

```
createRelease()      updateRelease()      uploadAudio()
uploadArtwork()      validateRelease()    submitRelease()
getReleaseStatus()   getStores()          requestTakedown()
getRoyalties()       getAnalytics()
```

Every write method takes a release. The Release aggregate does not exist —
it arrives in REL-001, and the ARCH-002 domain model that precedes it is
itself still under revision.

So the signatures could only be written one of two ways:

- **`mixed`/`array` payloads.** An interface that documents nothing, checks
  nothing, and is a comment with syntax.
- **Invented DTOs.** Guesses at a release's shape for distribution, made
  before the domain model exists and before any real distributor API has been
  read.

The second is worse than it looks. Once written, the guess is what the first
real adapter is measured against, and there are only two ways out: bend the
domain to fit the guess, or rewrite the interface and everything typed against
it. The interface stops being a boundary and becomes a liability — the precise
failure ADR-0003 exists to prevent, arrived at from the opposite direction.

## Decision

**Fix the part that does not depend on the domain model. Defer the part that
does.**

Declared now:

```php
interface Distributor
{
    public function name(): string;
    public function isAvailable(): bool;
    public function isSandbox(): bool;
    public function deliveryStatus(string $externalReleaseId): DeliveryStatus;
}
```

Plus `DeliveryStatus` — SaniTube's own vocabulary for where a release stands,
which each adapter normalises into. That enum is the load-bearing part: it is
what delivery tracking, the distribution screens and the status-polling jobs
are written against, and none of it needs the Release aggregate to exist.

`isSandbox()` is not decoration. Submitting a real release through a sandbox,
or a test through production, is exactly the mistake that must be visible in
the UI.

Deferred to **DIST-001**, to be designed against Too Lost's actual API rather
than an imagined one: `createRelease`, `updateRelease`, `uploadAudio`,
`uploadArtwork`, `validateRelease`, `submitRelease`, `requestTakedown`,
`getStores`, `getRoyalties`, `getAnalytics`.

This is a deliberate, recorded deviation from the ARCH-001 specification.

## Consequences

**Accepted:**

- Delivery tracking, status polling and the distribution UI can be built now.
- The first real adapter shapes the write contract from evidence.
- No type in the codebase is a guess about a distributor's payloads.

**Costs:**

- The contract is visibly incomplete, and a reader may mistake that for an
  oversight. This ADR is the countermeasure.
- DIST-001 carries more design work than it would have if the interface had
  been fully declared — the work is moved, not removed.
- Anyone tempted to call a distributor SDK directly before DIST-001 has no
  contract stopping them. Review must catch it.

## Revisit when

**DIST-001**, once both hold:

1. The Release aggregate exists (REL-001).
2. Too Lost's official API documentation and sandbox access are in hand.

If Too Lost access is delayed, the contract may still be completed against a
second distributor's real API — but never against no API at all.
