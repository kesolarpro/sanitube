# AUDIT-002 — telling the truth about who a token is

## Why AUDIT-001 left this out

Not an oversight, and the reason is the whole ticket: **a shared token names no
person.**

`src/Api` authenticates server-to-server calls with `SANITUBE_INTERNAL_API_TOKEN`.
Recording those calls as a *user* would invent somebody. Recording them as a
*guest* would say nobody authenticated when something did — and would file a
release submission next to a failed sign-in as though they were the same kind of
event. AUDIT-001 declared the gap rather than fill it with either lie.

## Why it had to be closed

**The API is not read-only.** It exposes, among others:

- `POST /releases/{release}/distribution/{provider}/submit` — the platform's one
  irreversible act;
- `POST /releases/{release}/ready`;
- candidate promote and reject;
- generation start, cancel and select.

Every one of those was audited when performed from the interface and invisible
when performed through the API. An operator reading the log would have seen a
catalogue that changed itself.

## The fourth answer

`AuditActorKind::ApiClient` — *something authenticated, and it was not a human.*

What it was goes in `actor_label` as the client's **configured name**
(`internal-api`, `health`). Never the token, never a prefix of it, never a hash
a guess could be checked against. `actor_id` and `actor_role` are null and say
so, rather than leaving "not a person" to be inferred from a missing name.

**No migration.** `actor_kind` is already `string(16)` and `api_client` is ten
characters; the enum is the schema, which is exactly what
`domain_tables_use_string_columns_for_enumerated_values` exists to keep true.

### Where the name comes from

`VerifiesSharedToken` sets a request attribute **only after `hash_equals` has
accepted the token**. Its presence is therefore a fact about authentication
rather than about what somebody sent, and `RecordAuditEvent` reads it in the
same place it already asks the guard about a user.

The resolution order is: signed-in user → API client → guest (arrived over a
matched route) → system. Each answer is true on its own terms and none of them
is a fallback for "could not tell".

## Mutation testing

Four injected, three killed, one withdrawn.

| # | Mutation | Result |
|---|---|---|
| M1 | API client demoted to `guest` — the lie AUDIT-001 refused | killed |
| M2 | Client label carries the token | killed |
| M3 | Attribute set *before* the token is checked | **withdrawn** |
| M4 | API refusal no longer recorded | killed |

**M3 is withdrawn rather than distorted.** Setting the attribute early changes
nothing observable: `abort(401)` ends the request before any controller runs, so
no audit line exists either way. The ordering is still correct and still worth
keeping — it would matter the moment anything audited from inside the middleware
— but it is not a risk today, and a test manufactured to catch it would be
asserting the implementation rather than a property.

M4 killed by erroring rather than failing: with the refusal unrecorded there is
no event for `firstOrFail` to find. Red for the right reason.

## Scope

Wired: the two irreversible API writes — distribution submit and release
readiness — both outcomes each.

Not wired, deliberately: the API's ingestion, generation and candidate writes.
They are audited by the same actions when performed from the interface, and the
recorder now resolves an API client correctly wherever it is called, so
extending coverage is a call-site edit rather than a design question. Listed as
`POST_V1_AUDIT_COVERAGE` rather than left implied.

## Gate

1233 tests · 5821 assertions · 1 skipped · PHPStan 6 clean, no baseline · Pint
clean.
