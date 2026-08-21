# ADR-0020 — A distributor receives the package, never the aggregate

- **Status:** Accepted
- **Date:** 2026-08-21
- **Ticket:** DIST-007
- **Supersedes nothing.** Completes DIST-006 by moving the same boundary onto
  the submission path, and materially changes the `Distributor` contract.

## Context

`ReleasePackage` was created by REL-003 to be one thing: the description of
what crosses to a distributor. Its own docblock says so —

> **The point of this type is that it is the only thing that crosses the
> boundary.** Before it, an adapter would have walked the Release aggregate
> itself — reaching into tracks, credits, identifiers and assets — which means
> every adapter reaches differently, every adapter has its own chance to read a
> revoked identifier as current, and "what did we actually send" is answerable
> only by re-running whatever that adapter happened to do.

DIST-004 then wrote the manual exporter and walked the aggregate anyway.
DIST-006 rewrote it to render the package, and recorded the defect.

The submission path was left as it was. `Distributor::validateRelease()`,
`prepareRelease()` and `submitRelease()` all took `Release`, so the first real
adapter — Too Lost, TuneCore, LabelGrid — would have repeated DIST-004's
mistake, in the one place where the consequence is a delivery rather than a
file on disk.

Nothing was broken. The only adapters were `none` and a fake, and neither read
the release at all. That is exactly why this was the moment: the contract is
cheap to change while no implementation depends on it, and expensive
afterwards.

## Decision

The three release-taking methods on `Distributor` take a `ReleasePackage`.

```
Release → PackageRelease → ReleasePackage → Distributor adapter
```

and never

```
Release → Distributor adapter re-queries domain state
```

`validateRelease()` changed with the other two deliberately. Leaving it taking
the aggregate would have reopened the same hole one method along, and the
question it asks — *would you accept this?* — is only meaningful about the
thing that would actually be sent.

`deliveryStatus()` and `requestTakedown()` are unchanged: they take an external
identifier and a key, and never needed catalogue state.

**What an adapter still owns.** Its configuration and its transport — an
endpoint, a credential, a wire format, a retry policy. Those are the provider's
and they never enter the package; `ReleasePackage` holds no secret, no disk, no
path and no URL, and a test walks the whole structure to keep that true. An
adapter needing bytes asks the storage service, which is the only thing that
knows where anything lives.

## Consequences

**The package is assembled once per submission**, after the delivery is claimed
and before either adapter call, and the same object is handed to both. Building
it twice would be two chances to build it differently, and *what did we send*
has to have one answer.

**A release that cannot be packaged is no longer submittable.** It never was
deliverable — the manual export path already refused it — but the submission
path did not check, because the adapter was handed the aggregate and never
looked. The two paths now agree.

This surfaced immediately. `DistributionTest`'s fixture built a track by
writing `TrackStatus::Ready` directly, with no master audio — the shape
`TrackFactory`'s own comment calls impossible — and the suite had been
submitting it for as long as the fixture existed. Packaging refuses it as
`TRACK_WITHOUT_MASTER`: there is nothing to deliver for such a track. The
fixture was corrected to `Track::factory()->ready()`.

**`ValidateDelivery` gained a code**, `RELEASE_NOT_PACKAGEABLE`, translated in
six languages like every other. It is distinct from `DISTRIBUTOR_UNREACHABLE`
and `DISTRIBUTOR_REFUSED` on purpose: "we could not build it", "we could not
ask" and "they said no" are three different things to a person deciding what to
fix, and collapsing them is how the platform previously turned an outage into a
verdict.

**What is preserved**, and asserted by the existing suite rather than by
assertion here: the fake's behaviour, the unknown-submission-outcome semantics
of DIST-001-H1, idempotency under a stable key, external-reference provenance,
`decided_by`, the structured validation codes, and the audit trail. The fake
never read the release, so swapping the parameter changed nothing it does.

## Enforcement

`PackageBoundaryTest` holds three claims:

- the contract takes no catalogue type, read by reflection;
- no class in `src/` implementing `Distributor` imports `Release`,
  `ReleaseTrack`, `Track`, `Contributor`, `ExternalIdentifier` or `Asset`;
- the production path assembles a package and submits *that* object.

The second is a source scan rather than an integration test, and deliberately:
an integration test proves what today's adapters do, not what tomorrow's may.
