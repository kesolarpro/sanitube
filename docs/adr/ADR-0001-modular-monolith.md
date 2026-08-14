# ADR-0001 — Modular monolith over microservices

- **Status:** Accepted
- **Date:** 2026-08-14
- **Ticket:** ARCH-001

## Context

SaniTube spans a wide surface: ingestion, media processing, catalogue, rights,
publishing, releases, distribution, royalties, analytics, and eventually a
public streaming API. Written as a set of services, that reads like a natural
decomposition — each of those is a plausible service boundary.

Three forces push the other way, hard:

1. **The deployment target.** cPanel shared hosting is a first-class target
   (ADR-0002). It offers one PHP application, one database, one cron entry, no
   container runtime and no service mesh. A microservice architecture is not
   merely awkward there; it is unrunnable.
2. **The boundaries are not known yet.** The domain model does not exist. Any
   service split drawn now would be a guess, and a wrong service boundary is
   an order of magnitude more expensive to move than a wrong module boundary —
   it becomes a network protocol, a deployment unit and a data-ownership
   argument all at once.
3. **One operator.** The platform is run by its owner, not by a team per
   service. Operational complexity is a direct tax with nobody to absorb it.

The genuine risk of a monolith is that boundaries erode: modules reach into
each other's internals until nothing can be extracted or reasoned about
separately.

## Decision

A **modular monolith**: one deployable application, with module boundaries
that are explicit and mechanically enforced.

- Each module lives in `src/<Module>/` under the `SaniTube\` namespace.
- Modules are declared in `config/sanitube.php`. The loader wires up each
  module's service provider, routes, migrations, translations and views by
  convention, and only what exists on disk.
- `Foundation` is the shared kernel — the module system itself — and holds
  nothing domain-specific.
- Cross-module communication goes through published contracts and events, not
  by reaching into another module's classes.

Erosion is guarded mechanically, not by discipline alone: a test asserts that
every directory in `src/` other than `Foundation` is declared in
configuration, so a module cannot be created and silently left unwired.

## Consequences

**Accepted:**

- One deployment unit, one database, one cron entry. Runs on cPanel.
- Refactoring across modules is a compiler-and-test problem, not a versioned
  API migration.
- No network hop, no serialisation, no distributed tracing for what is
  currently a single request.

**Costs:**

- Nothing physically prevents a module from importing another's internals.
  The convention is enforced by review and by the boundary test, not by the
  language.
- The whole application scales as one unit. If media processing ever needs
  hardware the rest does not, that is solved by moving *jobs* to a dedicated
  worker, not by splitting the codebase.
- A module cannot be deployed independently. This is deliberate.

## Revisit when

- A single module develops resource needs the rest cannot be scaled with —
  and moving its queue workers to separate hardware has already been tried.
- The platform gains more than one team, and deployment coordination becomes
  the bottleneck.
- Not before both the domain model and real production load exist. Extraction
  is possible precisely because modules communicate through contracts; the
  option is preserved, not exercised.
