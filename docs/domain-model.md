# SaniTube — Domain Model

Status: initial model (ARCH-002). This is the catalogue core — the part every
later module reads from and none of them may reshape.

## 1. The two things people conflate

**A Composition is the song as written. A Track is the song as recorded.**

One composition has many recordings: a studio take, a live version, someone
else's cover. Publishing and mechanical income follow the *composition*; master
income follows the *track*. Systems that collapse the two cannot account for
publishing at all, and the damage is discovered when a statement arrives that
nothing can be reconciled against.

**An Artist is a credit. A Contributor is a party.**

`Artist` is the name printed on a release — a stage name, a band, a project,
or "Various Artists", which is nobody. `Contributor` is the writer, producer or
publisher who actually did something and may need to be paid. Rights and
publishing attach to Contributors, never to Artists, because a payment has to
reach a legal person and a stage name is not one.

## 2. Entities

| Table | What it is | Soft deletes |
|---|---|---|
| `artists` | Public credit | yes |
| `contributors` | Creating or administering party | yes |
| `compositions` | The written work | yes |
| `tracks` | A recording | yes |
| `releases` | What is delivered to stores | yes |
| `assets` | A file the platform is responsible for | **no** |
| `external_identifiers` | An outside name for something | **no** |
| `external_identifier_revocations` | Withdrawal of one such name | **no** |

Link tables: `composition_contributor`, `track_artist`, `track_contributor`,
`release_artist`, `release_tracks`, `asset_links`.

Assets and identifiers are never soft deleted. An asset row records that bytes
exist somewhere; hiding it would not unwrite the file, it would only make the
file unaccounted for. An identifier that reached a distributor exists in store
metadata and royalty statements SaniTube does not control; the platform's job
is to remember it, not to be able to erase it.

## 3. Identity

Every public aggregate carries two identities:

- `id` — `BIGINT UNSIGNED AUTO_INCREMENT`, the storage key. Narrow indexes,
  cheap joins, never leaves the database.
- `uuid` — UUID v7, the public identity. Time-ordered so it clusters well when
  used for lookup, opaque so nothing outside can infer catalogue size or
  ordering from it.

Route binding resolves on `uuid`, which is what makes an internal id appearing
in a URL structurally impossible rather than merely discouraged. See
[ADR-0006](adr/ADR-0006-bigint-plus-uuid-v7.md).

## 4. Artists are relations, not columns

There is no `primary_artist_id` on `tracks` or on `releases`, and no
`display_artist` on `release_tracks`.

Collaborations are ordinary. A single-artist column forces one of two equal
credits to be demoted to fit the schema, and then every consumer has to decide
whether to trust the column or the relation. `track_artist` and
`release_artist` are the only sources of truth, and both support several
`PRIMARY` credits.

A denormalised read projection may well be added later — it is a legitimate
performance answer — but it will be derived, rebuildable and explicitly
non-authoritative. See [ADR-0011](adr/ADR-0011-read-projections-deferred.md).

## 5. One home per fact

A track's master is `tracks.master_asset_id`. A release's cover is
`releases.cover_asset_id`. Both are single-valued facts, so both live in a
foreign key.

`asset_links` exists for the genuinely multi-valued attachments — previews,
stems, lyrics, contracts, exports, alternate artwork — and `AssetLinkRole` has
no `MASTER` or `COVER` case at all. Offering a second way to say the same thing
is how two answers to "which file is the master?" come to exist, and there is
no principled way to pick between them afterwards. See
[ADR-0008](adr/ADR-0008-canonical-master-and-cover.md) and
[ADR-0009](adr/ADR-0009-asset-link-semantics.md).

## 6. External identifiers

```
external_identifiers
  identifiable_type + identifiable_id   what it names
  type                                  ISRC, UPC, ISWC, IPI, DSP_ID, …
  namespace                             '' for global registries, the
                                        counterparty for everything else
  value                                 normalised
  is_authoritative                      is this the one of record?
  source                                MANUAL | IMPORT | DISTRIBUTOR | GENERATED
  active_marker                         1 while active, NULL once revoked
```

Two unique indexes carry the guarantees:

| Index | Guarantees |
|---|---|
| `(type, namespace, value)` | one identifier value means one thing |
| `(identifiable_type, identifiable_id, type, namespace, active_marker)` | at most one **active** identifier of a kind per entity |

The second is the important one. Every engine in the support matrix treats
`NULL`s as distinct inside a unique index, so any number of revoked rows can
coexist while only one active row can. That makes "one active ISRC per track"
something the *database* enforces — no partial index, no `CHECK` constraint, no
engine-specific syntax, and no reliance on every future code path remembering
to ask.

`namespace` is part of both rules on purpose: two services each issue their own
id for the same recording, and neither may block the other.

Identifiers are immutable after creation. The only permitted mutation is
`active_marker` going 1 → NULL through `RevokeExternalIdentifier`, and deletion
is refused outright. See
[ADR-0010](adr/ADR-0010-external-identifier-lifecycle.md).

### Why this matters for the initial import

A large part of the initial catalogue was already distributed elsewhere and
carries ISRCs that exist in statements SaniTube cannot edit. Minting a second
ISRC for one of those recordings would not be a duplicate row — it would split
that recording's earnings between two identifiers, and nobody would notice
until a royalty report failed to reconcile months later.

## 7. Invariants

| | Rule | Enforced by |
|---|---|---|
| I1 | A `STORED`/`VERIFIED` asset cannot change `disk`, `path`, `sha256`, `byte_size`, `kind` | `AssetObserver` |
| I2a | `AUDIO_MASTER` has no parent | `AssetObserver` |
| I2b | `AUDIO_DERIVATIVE` requires a parent | `AssetObserver` |
| I2c | `PREVIEW` requires a parent | `AssetObserver` |
| I2d | No self-reference on parent or duplicate | `AssetObserver` |
| I2e | No cycles in either lineage | `AssetObserver` |
| I3 | Track READY needs title, ≥1 PRIMARY artist, valid language, a VERIFIED `AUDIO_MASTER` | `Track::markReady()` |
| I4 | Release READY needs ≥1 PRIMARY artist, ≥1 track, VERIFIED artwork cover, a date, all tracks releasable | `Release::markReady()` |
| I5 | Identifier assignment respects entity, format, namespace, uniqueness, legacy | `AssignExternalIdentifier` + unique indexes |
| I6 | A track on a committed release cannot be deleted; only DRAFT releases can | `TrackObserver`, `ReleaseObserver` |
| I7 | Credited shares per role ≤ 100% | `CompositionContributorObserver` |
| I8 | `is_instrumental` ⇔ `language_code = zxx` | `TrackObserver` |
| I9 | Track numbers contiguous from 1 per disc at READY | `Release::markReady()` |
| I10 | A `STORED`/`VERIFIED` asset has a `sha256`, a `byte_size` and a `mime_type` | `AssetObserver` |

The asset and track rules live in **observers**, not in services. An integrity
rule that only one code path respects is not an integrity rule: a seeder, a
factory, an import job or a future controller must all hit it.

I7 is checked per role rather than across the composition, because writers and
publishers each account to 100% of their own side.

## 8. Events

`ArtistCreated`, `CompositionCreated`, `TrackCreated`, `TrackMarkedReady`,
`TrackArchived`, `ReleaseCreated`, `ReleaseTrackAdded`, `ReleaseMarkedReady`,
`AssetStored`, `AssetVerified`, `AssetQuarantined`, `AssetMissing`,
`AssetDuplicateDetected`, `ExternalIdentifierAssigned`,
`ExternalIdentifierRevoked`.

No listeners yet, deliberately. The events name the moments other modules will
need; wiring cross-module reactions before those modules exist would be
guessing.

## 9. Read-only API

`GET /api/v1/{artists,compositions,tracks,releases}` and their `{uuid}`
variants, behind an internal token and the API throttle.

Read-only is a boundary rather than a stage of completion: writes carry the
invariants above, and exposing them before the Identity module can say *who is
calling* would mean a shared token could mutate the catalogue.

What never appears in a payload: database ids, foreign keys, `disk`, `path`,
any storage URL, `sha256`, revoked identifiers. An asset is described — uuid,
kind, duration, size, status — never located.

Pagination is cursor-based, ordered by primary key so the cursor stays stable
when a row is renamed. `per_page` outside 1–100 is a 422 rather than a silent
clamp, an unknown filter is a 422 rather than an ignored parameter, and an
undecodable cursor is a 422 rather than a silent restart from page one. In each
case the silent behaviour is indistinguishable from correct output, which is
what makes it worse than an error.

## 10. Not in this model yet

Rights ownership and splits, publishing administration, royalty ingestion,
distributor adapters, legacy import execution, music generation, the audio
ingestion pipeline, authentication and RBAC, UI, streaming playback, read
projections. The seams exist — `source = LEGACY_IMPORT`, `is_authoritative`,
the identifier namespace, the event set — but none of the implementations do.
