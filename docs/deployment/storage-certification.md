# Certifying storage

Configuration that parses is not configuration that works, and configuration
that writes an object is not configuration that runs a catalogue. This is the
procedure that takes an installation from "storage is set up" to "storage has
been proven", and the list of what it still cannot prove.

Run it once when you first point SaniTube at a bucket, and again after
rotating credentials, changing bucket, or moving the application to a new
domain.

---

## What certification adds to the health check

`sanitube:storage:check` writes an object, reads it back and deletes it. That
is the right question for a health screen: it is cheap, it is safe to run on a
schedule, and it catches a bucket that has gone away.

It is the wrong question to certify a deployment on, because three of the
operations the platform depends on are not among them — and each fails on its
own, for its own reason:

| Operation | Where production uses it | What a write/read/delete probe sees |
|---|---|---|
| `move` | Promoting a completed upload from its staging key to its canonical one, server-side | Nothing. A credential with `PutObject` and no `CopyObject` passes the probe and loses every upload at the last step. |
| Signed read | Every time a browser or a distributor is handed audio | Only that the provider *claims* it can sign. Whether the service honours the signature depends on endpoint addressing, clock skew and credential scope. |
| Presigned write | The direct-upload path — the only way a large master gets in on a shared account | Nothing. It is signed by a different code path than the read. |

`--certify` performs all three against the real service, plus a `checksum`,
which streams the object back out and hashes what is actually there.

---

## Before you start

- The bucket exists and the credentials are set. The settings screen names the
  variables **this** installation reads — an R2 install is asked for
  `R2_ACCESS_KEY_ID`, not `AWS_ACCESS_KEY_ID`.
- `league/flysystem-aws-s3-v3` is installed. It is deliberately not a hard
  dependency: an install that never leaves the local disk should not carry the
  AWS SDK.
- `SANITUBE_STORAGE_PROVIDER` names the provider you intend to certify.
- If the installation has run `config:cache`, rebuild it after any `.env`
  edit — otherwise you will certify the old configuration.

---

## Step 1 — the cheap check

```
php artisan sanitube:storage:check
```

Fix anything here before going further. A failure at this stage is a
credential, an endpoint or a bucket name, and the deeper checks will only
repeat it three more times.

## Step 2 — certification

```
php artisan sanitube:storage:check --certify
```

Add `--json` for something to paste into a ticket. Nothing in either output
contains a credential or a signed URL: the signature *is* the permission, and a
report is read later and by more people than the operator who ran it.

Every object the run writes lives under `.sanitube-health/` and is deleted
afterwards, including when a check fails.

### Reading the report

| Check | Passed means | Usually fails because |
|---|---|---|
| `write` | The object was accepted. | Wrong credential, wrong bucket, or the credential has no `PutObject`. |
| `read` | The bytes came back identical. | A read permission that is missing, or an endpoint serving a different bucket. |
| `checksum` | Streaming the object back produces the digest of what was written. | Rare, and worth investigating rather than retrying: it means the service returned different bytes than it accepted. |
| `move` | The object was promoted server-side and is no longer at its origin. | The credential can `PutObject` but not `CopyObject` or `DeleteObject`. **This is the failure the cheap probe cannot see.** |
| `signed_read` | A plain HTTP `GET`, with no credentials, was accepted and served the right bytes. | Path-style versus virtual-host addressing, a server clock several minutes out, or a credential scoped to a different bucket. |
| `presigned_write` | A presigned `PUT` was accepted **and** the object is readable at the key it was signed for. | The same causes as above, plus a signed header the client did not send. |
| `delete` | The object is gone, and reported as gone. | A credential without `DeleteObject`. Staging cleanup will silently accumulate objects. |

**`skipped` is not `passed`.** A local disk cannot sign URLs and cannot accept a
direct upload; both checks are skipped, the report says why, and the
installation is still certified for what it does. A report of nothing but skips
is not a certification at all — the command fails.

---

## Step 3 — CORS, which no command can prove

A browser refuses a cross-origin `PUT` **before the request is sent**. Nothing
reaches the service, so nothing can observe it from the server side: not this
command, not the logs, not a health probe. `--certify` says so in its own
output rather than letting a green table imply coverage it does not have.

The only proof is an upload from the interface:

1. Open the upload screen and add one small audio file.
2. Watch it complete and become a candidate.

If it fails with an opaque network error and nothing appears in the application
log, that is CORS. On R2, set a rule on the bucket:

```json
[
  {
    "AllowedOrigins": ["https://your-app-domain"],
    "AllowedMethods": ["PUT"],
    "AllowedHeaders": ["content-type"],
    "MaxAgeSeconds": 3600
  }
]
```

Use your real origin, not `*`. The signed URL is already scoped to one key for
a few minutes; a wildcard origin adds nothing but lets any page attempt the
request.

---

## Step 4 — lifecycle rules

Two kinds of litter, and the second one is invisible until the bill:

- **Abandoned staging objects.** An upload that starts and never completes
  leaves an object under the staging prefix. Expire that prefix after a day.
  `sanitube:assets:cleanup-staging` does this from the application side as well,
  daily, with an age floor — the bucket rule is the backstop for whatever the
  application never learns about.
- **Incomplete multipart uploads.** These **occupy storage and do not appear in
  an object listing**. Configure the provider to abort them after a day.

Neither rule may touch canonical keys. Scope both to the staging prefix.

---

## Sign-off

An installation's storage is certified when all of these are true:

- [ ] `sanitube:storage:check` passes.
- [ ] `sanitube:storage:check --certify` passes, with `signed_read` and
      `presigned_write` either passed or skipped for a reason you recognise.
- [ ] One file has been uploaded through the interface and became a candidate.
- [ ] A signed preview of that file plays in the browser.
- [ ] Lifecycle rules exist for the staging prefix and for incomplete multipart
      uploads.
- [ ] The settings screen reports every credential for the selected provider as
      *configured*.

Until the third and fourth boxes are ticked, the direct-upload path is
unproven, whatever the command says.

## Migrating an existing catalogue

Once the target provider is certified, the catalogue moves with:

```
php artisan sanitube:assets:relocate r2 --limit=50
```

Run it, read the report, run it again — done is detected (the asset already
names the target disk), not remembered, so stopping at any point loses
nothing but the file in flight. Every copy is verified against the asset's
recorded checksum **on the target** before the switch, a failed copy removes
its own unverified object and the run continues, and sources are never
deleted: retiring local copies after a verified migration is a separate,
explicit decision. ADR-0022 records why `disk` may move when nothing else
frozen ever does.
