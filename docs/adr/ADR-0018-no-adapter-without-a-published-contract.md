# ADR-0018 — No provider adapter without a published contract

- **Status:** Accepted
- **Date:** 2026-08-20
- **Ticket:** GEN-005
- **Supersedes nothing.** Extends ADR-0003 (provider boundaries) and gives
  ADR-0005's deferral a stated end condition.

## Context

GEN-005 researched Suno, the intended first music generation provider. The
finding is in `docs/research/suno-2026-08.md`: as of August 2026 there is **no
public API** — no console, no documentation, no published request or response
shapes, no rate limits, no pricing. Suno's CPO said in July 2026 that a
developer API is being *explored* with a curated set of partners. No timeline.

Meanwhile every commercial search result for "Suno API" is a third party that
reverse-engineers Suno's web client and resells access.

The same shape recurs elsewhere in this platform. `src/Distribution/Providers/`
holds only `NullDistributor`; Too Lost and TuneCore have no published contract
this platform can read either. The question is therefore general, not about one
vendor.

## Decision

**An adapter is written only against a contract its supplier has published.**

Concretely, all four must hold before a vendor adapter is started:

1. The supplier publishes endpoints, authentication, request and response
   shapes, error codes and limits, readable without an NDA.
2. Access is obtainable **without reverse engineering** — self-serve or via an
   official programme.
3. The **terms for output produced through that access** are stated, so a terms
   snapshot records something real.
4. Any **retrieval or download cap** is stated, so it can be modelled rather
   than discovered in production.

Until then the provider stays absent, the fake provider drives every path end to
end, and the gap is recorded as an external blocker naming *what* is missing.

### Reverse-engineered wrappers are excluded permanently, not pending review

This is not a scheduling decision to be revisited under pressure:

- Such a wrapper works by holding **a supplier account's session credentials**.
  Handing an operator's account to a third party is the mechanism, not a side
  effect.
- The account is what holds the **commercial rights** to the output. A route
  that risks the account risks the rights to everything made through it.
- SaniTube asserts commercial rights to a distributor. Audio obtained through a
  channel the supplier has not sanctioned makes that assertion one nobody can
  stand behind.
- It breaks whenever the supplier changes its client, which cannot underpin a
  catalogue that must still be distributable next year.

The same reasoning already forbids driving a distributor's web interface with
automation, and for the same reason: what looks like an integration is an
unsanctioned use of somebody's account.

## Consequences

**Accepted:** generation and distribution stay unavailable on real providers for
as long as suppliers choose. That is a real cost and it is not being minimised —
it is the price of a catalogue whose provenance and rights can be stated
truthfully.

**Also accepted:** the fake provider is *shipped*, not test-only. It is what
makes the Studio demonstrable, reviewable and fully exercised without a vendor,
and this decision is what makes that non-negotiable rather than a convenience.

**Required:** an external blocker must name what is missing — "no published API
contract; output terms unverified" — rather than the vaguer "no credentials".
"We have no key" implies somebody could hand one over. For Suno, nobody can.

**Rejected alternative — build against a reverse-engineered wrapper and swap it
later.** The adapter would be shaped by an undocumented private client, so the
"swap" would be a rewrite; every generation made in the meantime would carry
rights nobody could defend; and the catalogue would contain recordings that
cannot honestly be distributed. The cost lands at the distributor, long after
the code was written.

**Rejected alternative — guess the contract from the wrappers' documentation.**
Third-party docs describe *their* service, not the supplier's. §5 forbids
guessing model names, formats, limits and response fields, and a guess dressed
in someone else's schema is still a guess.
