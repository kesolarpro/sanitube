# Storage

Everything SaniTube holds — masters, derivatives, artwork, documents,
distribution exports — is an **asset**: a database row that records that bytes
exist somewhere, plus the object those bytes live in.

The two are deliberately separate, and the whole of this document is about what
happens when they disagree.

---

## The rule that shapes everything else

**No domain code ever touches a disk.** Not `Storage::disk(...)`, not an SDK,
not a path. Catalogue, Assets and everything downstream go through
`SaniTube\Storage\Contracts\StorageProvider`, and an adapter under
`src/Storage/Providers` decides what that means.

That is what makes changing provider a configuration change. It is enforced by
`StorageBoundaryTest`, which fails the build if a vendor name or the filesystem
facade appears in the domain.

---

## Choosing a provider

| Provider | Set `SANITUBE_STORAGE_PROVIDER` to | Expiring URLs | Notes |
| --- | --- | --- | --- |
| Local disk | `local` | ✗ | The default. Works on any host, including shared cPanel. |
| Amazon S3 | `s3` | ✓ | |
| Cloudflare R2 | `r2` | ✓ | S3-compatible; set `R2_ENDPOINT`. |
| Backblaze B2 | `b2` | ✓ | Through B2's S3-compatible endpoint. |

The three S3-compatible providers need one package that is **not** a hard
dependency:

```
composer require league/flysystem-aws-s3-v3
```

An installation that never leaves the local disk should not have to carry the
AWS SDK, which is why it is not in `composer.json`.

### Local storage cannot sign URLs

This is the one behavioural difference that matters. A signed, expiring URL is
the only sanctioned way an audio asset reaches a browser or a distributor, and
a local disk cannot mint one. `LocalStorageProvider::supportsTemporaryUrls()`
returns `false` and `temporaryUrl()` throws rather than quietly handing back a
permanent URL to a master.

On local storage, audio has to be streamed through the application instead:
slower, and every byte crosses the web server. It is a correct configuration,
not a broken one — but it is why object storage is the recommended production
target.

---

## How bytes become an asset

```
register()                PENDING     row created, key reserved, nothing written
  │
  ├─ upload to staging/               bytes land under a reserved prefix
  ├─ read back size + SHA-256         from storage, not from the upload
  ├─ sniff the content type           the declared type is never trusted
  ├─ promote to the canonical key     server-side move where supported
  │
store()                   STORED      the row now describes real bytes
  │
verify()                  VERIFIED    read back and confirmed
                       or QUARANTINED  present but not what it should be
                       or MISSING      not there at all
```

### Why the checksum is taken *after* the write

Hashing bytes as they stream past answers "did we send the right thing".
Hashing the object afterwards answers "is the right thing in storage" — and
only the second question matters when a master turns out to be missing a year
later. The extra read of the staging object catches truncated writes, dropped
multipart parts, and endpoints that accept a write and store nothing.

### Object keys

```
masters/{asset_uuid}/original.wav
artwork/{asset_uuid}/original.jpg
stems/{asset_uuid}/original.wav
documents/{asset_uuid}/original.pdf
exports/{asset_uuid}/original.xml
staging/{asset_uuid}/original.wav
```

**The original filename is never part of an object's identity.** Two people
uploading `master.wav` are not uploading the same thing. The key comes from the
asset's UUID, which the server generates; the uploaded name is kept in
`original_filename` purely so a human recognises their own file.

The trailing extension is cosmetic — it exists so an object pulled out of
storage by hand opens in the right application — and comes from a character
allowlist. It never decides anything.

Because the key is derived from a UUID, path traversal is designed out rather
than filtered: `../../etc/passwd.wav` has nowhere to go before it is stripped to
`passwd.wav`.

---

## When the database and storage disagree

There is **no transaction across MySQL and object storage**, and SaniTube does
not pretend otherwise. What it does instead is make every disagreement
survivable.

| What failed | State afterwards | How it resolves |
| --- | --- | --- |
| Upload dies part-way | Asset `PENDING`; partial object in `staging/` | Swept by `sanitube:assets:cleanup-staging` |
| Checksum or size rejected | Asset `PENDING`; staging object deleted immediately | Retry |
| Promotion succeeded, database write failed | Asset `PENDING`; object at its canonical key | Retry writes the same key — deterministic, so it converges |
| Same upload runs twice | Unchanged | Second call sees matching bytes and returns |
| Retry with *different* bytes | Refused | Stored bytes are never replaced |

The property that makes retries safe is that **the canonical key is
deterministic**. There is exactly one address for an asset's bytes, so a second
attempt overwrites the first rather than accumulating alongside it.

See `docs/adr/ADR-0012-asset-storage-compensation.md`.

---

## Duplicates

The same bytes legitimately arrive twice — a re-upload after a browser crash,
the same master placed on two releases. SaniTube **records** that, and never
rejects it. An import that errors because a file is already known has lost the
fact that it happened.

When an upload's SHA-256 matches an existing `STORED` or `VERIFIED` asset:

- the new asset is stored normally
- `duplicate_of_asset_id` points at the earlier one
- `AssetDuplicateDetected` is emitted

**Both objects are kept.** Physical deduplication is deliberately not
implemented: pointing two assets at one object turns deletion into a
reference-counting problem spanning releases and distributions, and getting it
wrong once means losing a master. Storage is cheaper than that.

Set `SANITUBE_DETECT_DUPLICATES=false` to skip the lookup entirely.

---

## Security

- **Private by default.** The `sanitube`, `r2` and `b2` disks set
  `visibility: private` and `throw: true`. Losing a master to a silently failed
  write is not an acceptable outcome.
- **No permanent URL for a master.** `AssetStorageService` has no `url()`
  method, and a test asserts it never grows one.
- **Short-lived signed URLs.** `SANITUBE_TEMPORARY_URL_TTL`, 900 seconds by
  default.
- **The declared MIME type is never trusted or stored.** The content type is
  sniffed from the first bytes of the stored object.
- **Extensions are never a security control.** They are cosmetic, allowlisted
  by character, and decide nothing.
- **Keys are generated server-side.** Nothing a client sends reaches an object
  key.
- **Size limits are enforced while streaming**, not after the write, so an
  oversized upload cannot occupy storage first and be rejected second.
- **Credentials are never returned or printed.** Failure details in
  `StorageHealth` pass through `CredentialRedactor`, which masks the values
  this installation is configured with.

Configure limits per asset kind in `config/assets.php`. Note that PHP's own
`upload_max_filesize` and `post_max_size` still cap browser uploads and are
usually the lower of the two on shared hosting — raising a SaniTube limit does
not raise those.

---

## Commands

```bash
# Can this install actually store a master? Real write, read-back and delete.
php artisan sanitube:storage:check
php artisan sanitube:storage:check s3 --json

# Read assets back out of storage and confirm checksum and size.
php artisan sanitube:assets:verify
php artisan sanitube:assets:verify --status=all --limit=500 --json

# Remove uploads that were started and never finished.
php artisan sanitube:assets:cleanup-staging --dry-run
php artisan sanitube:assets:cleanup-staging
```

`sanitube:storage:check` is the one to run first on a new install. Credentials
that parse are not credentials that work: a key with `PutObject` and no
`DeleteObject` looks perfect right up until the first staging cleanup.

### Before you enable the cleanup cron

**Run it as a dry run first, and read what it says it would delete:**

```bash
php artisan sanitube:assets:cleanup-staging --dry-run
```

It should list only objects under `staging/`, and only ones whose upload
plainly failed. If it names anything else, stop and report it — that is a bug
in the sweep, not a configuration problem.

### What the sweep will and will not delete

An object is removed only when **all five** of these hold at once:

1. the provider returned it inside the exact `staging/` scope
2. the key parses as one this platform could have written —
   `staging/{uuid}/original[.ext]`, exactly
3. no asset of record claims that path
4. it is older than the age threshold
5. this is not a dry run

Conditions 1 and 3 are unreachable given 2. They are checked anyway. This is
the only scheduled task whose bug would read "deleted a master", and redundant
checks cost a comparison and an indexed query.

`staging/foo/bar` is under the right prefix and is **not** deleted: it is not a
key SaniTube wrote, so it belongs to something else.

The age threshold has a **floor of one hour that no configuration can lower**.
Setting `SANITUBE_STAGING_TTL_HOURS=0` is clamped, not obeyed — otherwise a
typo would mean "delete every upload currently in flight". Only the explicit
`--hours` option goes below the floor, it warns when it does, and the scheduler
passes no options at all.

---

## Deployment

### Shared hosting (cPanel)

Works with no object storage, no Redis, no Docker, no root and no Supervisor.

1. Leave `SANITUBE_STORAGE_PROVIDER=local`.
2. Put the storage root **outside** `public_html`:
   ```
   SANITUBE_LOCAL_STORAGE_ROOT=/home/youruser/sanitube-storage
   ```
   Masters must not be reachable over HTTP. This is the single most important
   line in a cPanel install.
3. Keep `QUEUE_CONNECTION=database`.
4. One cron entry drives every scheduled task, cleanup included:
   ```
   * * * * * php /home/youruser/sanitube/artisan schedule:run >> /dev/null 2>&1
   ```
5. Check the account's disk quota against the size limits in
   `config/assets.php`. The defaults allow a 2 GB master, which a small shared
   plan cannot hold many of.

Upgrading to object storage later is a change of environment variables; no
code, no schema, and existing rows keep working because each asset records the
provider it was stored with in `disk`.

### VPS or dedicated

The same application, with more options available:

- Set `SANITUBE_STORAGE_PROVIDER` to `s3`, `r2` or `b2` and install
  `league/flysystem-aws-s3-v3`.
- Signed URLs become available, so audio no longer streams through PHP.
- Redis and a queue worker are optimisations, never requirements. The database
  queue and the cron scheduler remain fully supported.
- Run `sanitube:assets:verify` on a schedule if the egress cost is acceptable;
  the entry is in `routes/console.php`, commented out.

### Changing provider at runtime

The storage manager reads `storage.default` once, when it is constructed. A
default that changes under a running process is harder to reason about than one
that does not, and an upload half-written to one provider and half to another is
not a state worth being able to reach.

So changing that value after the container has resolved the manager has no
effect on it: rebuild or rebind the singleton. Addressing a *specific* provider
needs nothing special — `provider(name)` works at any time, and
`register(name, provider)` adds one.

### Migrating between providers

`disk` is recorded per asset, so a catalogue can hold assets on more than one
provider at once. Point `SANITUBE_STORAGE_PROVIDER` at the new one and new
uploads land there while existing assets continue to be read from where they
are. A bulk mover is not part of AST-001.

---

## Browser and mobile uploads

AST-001 ships the server-side path: the stream reaches PHP and PHP writes it to
storage.

The architecture does not have to change to support direct uploads later. A
client would ask for a signed upload URL, `PUT` the bytes straight to the
provider, and then call back; the server would run exactly the same read-back,
checksum and promotion it runs today, because that step already reads from
storage rather than from the request. Large masters would stop transiting PHP
twice — which is the reason to do it.
