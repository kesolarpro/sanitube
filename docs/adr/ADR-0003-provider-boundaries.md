# ADR-0003 — Every external service sits behind a SaniTube-owned contract

- **Status:** Accepted
- **Date:** 2026-08-14
- **Ticket:** ARCH-001

## Context

SaniTube depends on a long list of third parties: Too Lost, TuneCore and
LabelGrid for distribution; Suno for generation; OpenAI and Claude for
metadata assistance; S3, Cloudflare R2 and Backblaze B2 for storage. The list
will grow, and parts of it will be replaced.

The failure mode is well known and easy to walk into. A distributor's SDK
types spread from the adapter into services, then into controllers, then into
the database schema. At that point the distributor *is* the domain model: its
identifiers are the catalogue's identifiers, its release states are the
catalogue's states, and replacing it means rewriting the platform.

For a catalogue of roughly 900 owned recordings, part of it already
distributed elsewhere, that is not an abstract risk. The catalogue outlives
every supplier it will ever use.

A second, more immediate problem: development cannot wait on API access. Suno
may have no usable official API. Distributor sandbox credentials arrive when
they arrive. If any module can only be built once its provider is reachable,
the whole schedule is hostage to other people's onboarding.

## Decision

**The catalogue is the source of truth. Every external service is a supplier
behind an interface SaniTube owns.**

Four rules:

1. **The contract is written in SaniTube's vocabulary.** `DeliveryStatus` is
   SaniTube's enum; each adapter normalises its provider's wording into it. No
   provider DTO, identifier or status string crosses the boundary.
2. **No domain code calls a provider directly.** Not controllers, not domain
   services. They express intent; the module routes it.
3. **Unconfigured is a normal state, not an error.** Every contract carries
   `isAvailable()`. A missing API key degrades one screen and is reported by
   capability detection — it never throws mid-workflow and never blocks the
   catalogue.
4. **Every contract ships with a working no-op from day one.**
   `FakeMusicGenerationProvider` models the full asynchronous lifecycle;
   `NullAiProvider` and `NullDistributor` are resolvable with no credentials.
   The Studio, campaigns and ingestion are therefore buildable and testable
   with no external music API in existence.

Contract tests are written against the *interface*, not the fake, so the same
file becomes the specification a real adapter must satisfy.

Storage follows the same shape: `StorageProvider` with local, S3, R2 and B2
implementations. The local provider refuses to fake temporary URLs rather than
handing out a permanent URL to a master.

## Consequences

**Accepted:**

- A provider outage degrades one screen; the catalogue keeps working.
- Replacing a provider is an adapter plus a config value.
- Development never blocks on credentials, onboarding or an API that may not
  exist.
- Provenance and audit have a natural home at the boundary.

**Costs:**

- An adapter and a translation layer per provider, and a genuine risk of a
  lowest-common-denominator interface that cannot express what any single
  provider does well. Mitigated by declining to guess: see ADR-0004 and
  ADR-0005.
- Provider-specific features need a deliberate extension point rather than a
  direct call.
- More classes than calling an SDK inline.

## Revisit when

- A provider capability turns out to be genuinely inexpressible through the
  contract. The answer is to widen the contract, or add a capability probe —
  not to bypass it.
- Never for a single feature's convenience. Every bypass is what the erosion
  above looks like on day one.
