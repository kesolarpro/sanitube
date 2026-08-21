# ADR-0021 — The work crosses with the recording

- **Status:** Accepted
- **Date:** 2026-08-21
- **Ticket:** DIST-008
- **Extends ADR-0020**, which made `ReleasePackage` the delivery boundary. This
  says what else has to be inside it.

## Context

ADR-0020 settled *where* the boundary is: a distributor receives the package and
never the aggregate. It did not ask whether the package described everything a
distributor needs.

It did not. A recording and the work it records are different things with
different rights, and SaniTube's catalogue models both:

- `Contributor` with `TrackContributorRole` — performer, producer, engineer,
  mixer, mastering, programmer. **The recording.**
- `Composition` with `CompositionContributorRole` — composer, lyricist,
  arranger, adapter, translator, publisher, administrator; a `share` per credit
  validated to 100% per side by `CompositionContributorObserver`; an ISWC
  through the ordinary identifier lifecycle. **The work.**

`PackageRelease` read only the first. Every delivery described who engineered
the master and said nothing about who wrote the song — on a platform that had
already built the writer side, normalised ISWCs and IPIs, and enforced the
splits.

The comment in `trackContributors()` is the tell. It explains itself by saying

> The legal name, not the display name. A distributor passes this to collecting
> societies, which match on the name a person is registered under.

which is the *writer* use case, applied to a relation that can only hold
engineers.

## Decision

`PackagedTrack` carries the work alongside the recording: `iswc`, and a list of
`PackagedWriter`.

**`PackagedWriter` is a separate type from `PackagedContributor`**, and that is
the decision rather than an implementation detail. One list would let a reader
take a mastering engineer for a rights holder, which is a mistake nobody notices
until a royalty statement is wrong. Only a writer credit carries a `share`, and
a shape with that field for everybody invites somebody to fill it in for a mixer.

Both are absent-by-default. A recording of a work nobody has entered yields a
null ISWC and an empty writer list, for the same reason `isrc` is nullable:
SaniTube never invents an identifier, and it does not invent a composition
either.

Writers are ordered by the position somebody entered them in. A list forwarded
to a society is one where the order was a decision, and re-sorting would
overrule it silently.

## On the share, and on money

A writer share is **rights metadata**, not earnings. It is what a society is
told about who wrote what, it was already captured and already validated, and
this ADR changes nothing about it except that it now reaches the distributor
that needs it.

Nothing computes anything from it. It is passed through exactly as the column
holds it — six decimal places included — because rounding here would be this
platform forming an opinion about a split it was merely told. SaniTube does not
handle money; earnings stay with the distributor, and that remains true.

## Consequences

The exported shape grows by two keys. `ReleasePackage::toArray()` is
hand-written precisely so that is a deliberate act, and
`the_serialised_shape_is_deliberate_and_not_reflected` refused the change until
the new keys were named in it. That is the guard working, not an obstacle.

The additions are backward-compatible for any adapter: two new fields on a
structure nobody outside the platform consumes yet, since the only adapters are
`none` and a fake.

A revoked ISWC is never presented as current, by the same active-identifier rule
the ISRC has had since REL-003 — asserted rather than assumed, because a
withdrawn code reappearing in a delivery would put it back into circulation.
