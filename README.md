# SaniTube

A portable, provider-independent platform for a music catalogue: ingestion,
metadata, rights, releases, distribution, royalties and analytics — with the
catalogue itself as the source of truth and every external service behind an
adapter.

> **Status: foundation.** The architecture, portability guarantees,
> internationalisation and capability detection are in place and tested. The
> domain model, dashboard and distributor integrations are the next tickets.
> See [`docs/architecture.md`](docs/architecture.md).

## Requirements

| | Minimum | Recommended |
|---|---|---|
| PHP | 8.2 | 8.3+ |
| Database | MySQL 8 / MariaDB 10.6 | — |
| Node (build only) | 20 | 22 |
| FFmpeg | optional | required for audio analysis |
| Redis | not required | optional on a VPS |
| Docker | not required | optional |

Required PHP extensions: `ctype`, `curl`, `dom`, `fileinfo`, `filter`, `hash`,
`json`, `mbstring`, `openssl`, `pcre`, `pdo`, `session`, `tokenizer`, `xml`,
`zip`. Plus `gd` or `imagick` for artwork.

Run `php artisan sanitube:health` at any time to see exactly what the current
server can and cannot do, and what to do about each gap.

## Installation

```bash
composer install
cp .env.example .env
php artisan key:generate

# Set DB_* in .env, then:
php artisan migrate

npm ci && npm run build
```

Nothing is hard-coded to a domain: set `APP_URL` and the install works on
`music.example.com`, `catalogue.example.ca`, `localhost` or anything else.

### Scheduler

One cron entry drives every recurring task, identically on cPanel, a VPS and a
dedicated server:

```cron
* * * * * php /path/to/sanitube/artisan schedule:run >> /dev/null 2>&1
```

A heartbeat runs every minute, so `sanitube:health` reports immediately if the
entry is missing or has stopped firing.

### Queue

The default is the `database` driver — no Redis, no Supervisor required.

- **VPS with a persistent worker:** run `php artisan queue:work` under
  Supervisor or systemd.
- **Shared hosting with no worker:** add
  `* * * * * php /path/to/sanitube/artisan queue:work --stop-when-empty`.
- **Redis available:** set `QUEUE_CONNECTION=redis`. The application code is
  identical.

### Storage

`SANITUBE_STORAGE_PROVIDER=local` keeps everything on this server's disk and
works out of the box. For S3, Cloudflare R2 or Backblaze B2, set the provider
and its credentials in `.env` and add the adapter:

```bash
composer require league/flysystem-aws-s3-v3
```

On shared hosting, put the storage root **outside** the web root — masters must
not be reachable over HTTP:

```
SANITUBE_LOCAL_STORAGE_ROOT=/home/youruser/sanitube-storage
```

Then check that the configuration actually works, rather than merely parses:

```bash
php artisan sanitube:storage:check     # real write, read-back and delete
php artisan sanitube:assets:verify     # confirm stored assets against their checksums
```

Local storage cannot sign expiring URLs, so audio is streamed through the
application instead. Everything else behaves identically. See
[`docs/storage.md`](docs/storage.md).

## Development

```bash
composer test      # test suite
composer lint      # code style check
composer fix       # apply code style
composer analyse   # static analysis (see docs/architecture.md § Known limitations)
composer check     # all of the above
```

## Documentation

- [`docs/architecture.md`](docs/architecture.md) — modules, boundaries,
  portability rules, provider contracts, known limitations.
- [`docs/domain-model.md`](docs/domain-model.md) — the catalogue core: entities,
  identity, invariants, the identifier lifecycle, the read-only API.
- [`docs/storage.md`](docs/storage.md) — providers, the upload workflow, object
  keys, duplicates, security, and cPanel/VPS deployment.
- [`docs/adr/`](docs/adr/README.md) — architecture decision records, including
  the decisions deliberately deferred to a later ticket and what unblocks them.

## Licence

Proprietary. All rights reserved.
