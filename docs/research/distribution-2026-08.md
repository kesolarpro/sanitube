# Distribution and DDEX — research, August 2026

**DIST-003. Research only. No adapter and no DDEX document were built, and the
reason is recorded rather than implied.**

Items 16, 17 and 18 of the Phase 2 order are distribution research, DDEX, and a
first viable distributor adapter. This document is item 16 and explains why 17
and 18 cannot be started **from this environment**.

---

## The finding, in one line

Unlike Suno, at least one distributor **does** publish a developer contract. It
cannot be read from here: every distributor and DDEX host is refused by this
environment's network egress policy.

That is a different blocker from GEN-005's, and the difference matters. Suno has
published nothing, so nobody could supply it. These specifications exist and are
public; this session simply cannot reach them.

---

## Evidence

Every host below was probed directly. `raw.githubusercontent.com` is included as
a control, because a probe where everything fails proves only that probing
fails.

| Host | Result |
|---|---|
| `ddex.net` | `CONNECT tunnel failed, response 403` |
| `service.ddex.net` | `CONNECT tunnel failed, response 403` |
| `kb.ddex.net` | `CONNECT tunnel failed, response 403` |
| `developer.toolost.com` | `CONNECT tunnel failed, response 403` |
| `api.toolost.com` | `CONNECT tunnel failed, response 403` |
| `www.tunecore.com` | `CONNECT tunnel failed, response 403` |
| `raw.githubusercontent.com` *(control)* | **`HTTP 200`** |

The control is what makes the rest meaningful — and it is also how ART-002 was
possible: the OpenAI specification is mirrored there, so it could be read in
full rather than summarised.

---

## What the search index says, and how far that goes

Web *search* is available even where fetching is not, so the following is known
at second hand and is **not** a basis for code:

- **Too Lost publishes a developer portal** at `developer.toolost.com/docs`,
  with a REST API, OAuth credentials, and a stated scope covering catalogue and
  release management, distribution, analytics, webhooks — and royalties, splits
  and payouts.
- **TuneCore** does not surface a public self-serve developer portal in the same
  way; its integrations appear to be commercial arrangements.

**A search summary is not a contract.** §5 forbids guessing endpoints, accepted
formats, file limits, response fields and rate limits, and ADR-0018 requires an
adapter be written only against a contract the supplier has published *and that
we can read*. Second-hand descriptions of an API satisfy neither.

---

## A constraint that must be settled before anyone writes the adapter

Too Lost's API is reported to cover **royalties, splits and payouts**.

SaniTube must never implement any of those. The standing scope is explicit and
the platform enforces it with tests that scan source for currency vocabulary.
When an adapter is eventually written, the boundary has to be stated in the
adapter itself, not assumed:

- **The adapter uses the delivery half only** — validate, prepare, submit,
  status, takedown. That is exactly the five-method `Distributor` contract, and
  it is already the right shape.
- **A distributor endpoint that returns earnings is not called**, and no field
  carrying an amount is stored, mapped or displayed. `DistributorSubmission`
  has nowhere to put one, which is the correct design and should stay that way.
- The temptation is real precisely because the data will be *right there* in
  responses the adapter already receives. Dropping it must be deliberate and
  visible, not incidental.

This is written down now so that whoever writes the adapter inherits the
decision rather than re-making it under pressure.

---

## DDEX

DDEX ERN is a **published standard**, which puts it in a different position from
Suno's absent API — but the authoritative schemas live on hosts this environment
refuses.

Building an ERN document from recollection is not an option. A wrong element
name, a wrong namespace version or a wrong cardinality produces a document a
distributor rejects, and the failure surfaces at delivery — the one operation
this platform must never get wrong twice. There is no partial credit in schema
conformance.

Community mirrors of the schemas exist on hosts that *are* reachable. They were
deliberately not used: a mirror is not the authoritative source, and ADR-0018's
first condition is a contract the *supplier* publishes. Reading a copy somebody
else uploaded and treating it as the specification is the same mistake as
reading a reverse-engineered wrapper's documentation and treating it as the
vendor's.

**REL-003 is the groundwork that survives this.** `ReleasePackage` is the
normalised, validated description an ERN document would be generated *from*, and
it was built without needing the schema. When the schema is readable, the
remaining work is a mapping, not an archaeology of the catalogue.

---

## What would unblock items 17 and 18

Any one of:

1. **An environment whose egress policy allows `ddex.net`, `kb.ddex.net` and the
   chosen distributor's developer host.** This is a configuration of the
   execution environment, not of the project.
2. **The specifications supplied into the repository** — the ERN XSDs and the
   distributor's OpenAPI document committed under `docs/`, exactly as the OpenAI
   specification could be read because it is mirrored somewhere reachable.
3. **Distributor credentials plus a sandbox**, which would be needed for
   certification regardless and which DIST-002 already tracks.

The first two unblock the *code*. The third unblocks *certification*, and they
are not the same thing — DIST-002 has always been about the third, and this
document adds the first two as distinct.

---

## Recorded blockers

| ID | What is blocked | What would lift it |
|---|---|---|
| **ENVIRONMENT_EGRESS** | Reading any distributor or DDEX specification, and therefore items 17 and 18 of the order. | An environment allowing those hosts, or the specifications committed to the repository. Not a project decision. |
| **DIST-002** *(existing)* | Certifying a delivery against a real distributor. | Credentials and a sandbox. |

---

## Sources

Search-result summaries only. None of these pages was fetched — see the evidence
table above.

- [Too Lost — Developer Portal](https://developer.toolost.com/docs) (blocked)
- [Too Lost — Developers](https://toolost.com/developers) (blocked)
- [DDEX](https://ddex.net) (blocked)
