# The generation worker

SaniTube Core does not generate music. A **generation worker** does, and it is
the same SaniTube application installed on a host that has a GPU.

This document is why that split exists and how to deploy it.

---

## Why there is a worker at all

ACE-Step's published contract answers `POST /generate` like this:

```json
{ "status": "success", "output_path": "/somewhere/on/my/disk/take.wav", "message": "..." }
```

It returns **a path on its own filesystem** — not bytes, not a URL. There is no
download endpoint in the published contract, and SaniTube does not invent one
(see ADR-0018).

So *something* has to open that file, and that something must be on the engine's
host. Making SaniTube Core do it would mean requiring Core and the GPU to share
a filesystem — a shared mount between, say, a cPanel account and a GPU server.
SaniTube must run on cPanel, on a small VPS, on Alma/Rocky/Debian, on whatever
an operator has. **That requirement is not acceptable**, so the worker exists
instead.

```
SaniTube Core                     (cPanel, VPS, anywhere — no GPU)
      │  authenticated HTTPS
      ▼
Generation Worker                 (the GPU host — SaniTube with a worker token)
      │  local HTTP
      ▼
ACE-Step                          (localhost, no public exposure needed)
      │  writes output_path
      ▼
Worker opens the file, validates it, streams it out
      │
      ▼
Shared object storage (R2, or a local disk on a one-host install)
      │  storage key
      ▼
Core ingests → Asset → analysis → fingerprint → dedup → Candidate → review
```

**`output_path` never crosses a boundary.** It is not persisted, not sent to a
browser, not stored as a SaniTube storage key, and it is not part of the
generation domain. Core is handed a *storage key*, which it already knows how to
read from anywhere.

## What Core needs

Nothing, to boot. An installation with no worker has no ACE-Step, the studio
says so, and everything that is not generation carries on. This is the ordinary
case.

To use one:

```dotenv
SANITUBE_GENERATION_PROVIDER=acestep
SANITUBE_GENERATION_WORKER_URL=https://gpu.example.internal
SANITUBE_GENERATION_WORKER_TOKEN=<a long random secret>
SANITUBE_GENERATION_WORKER_MODEL_LABEL="ACE-Step 1.5"
```

Core also needs the **same object storage** as the worker. On a split
deployment that means R2 (or any S3-compatible bucket) configured identically on
both. On a single host it is already the same disk.

## What the worker needs

The same application, plus:

```dotenv
# Makes the worker endpoint exist. Unset, it answers 404.
SANITUBE_GENERATION_WORKER_TOKEN=<the same secret Core sends>

# Where ACE-Step is listening, on this host. No default: inventing
# http://localhost:8000 would make a misconfigured worker look like a working
# one until it silently talked to whatever else was on that port.
SANITUBE_ACESTEP_ENDPOINT=http://127.0.0.1:8000

# The model this worker runs, and the GPU it runs on. Chosen here, by you.
SANITUBE_ACESTEP_CHECKPOINT_PATH=/opt/ace-step/checkpoints
SANITUBE_ACESTEP_DEVICE_ID=0

# The ONLY directory this worker will open a file from. Absolute, and it must
# exist. An unresolvable root refuses every generation rather than widening.
SANITUBE_ACESTEP_OUTPUT_ROOT=/var/lib/sanitube/generation
```

The worker does **not** need `SANITUBE_GENERATION_WORKER_URL`: it does not call
itself.

### The output root

Create it, own it, and put nothing else in it:

```bash
sudo mkdir -p /var/lib/sanitube/generation
sudo chown <the user ACE-Step and SaniTube run as> /var/lib/sanitube/generation
sudo chmod 750 /var/lib/sanitube/generation
```

Everything the worker writes there is deleted after it is staged, whether the
generation succeeded or failed.

## What the worker will not do

These are enforced in code and covered by tests, not merely intended:

- **It will not open a file outside the output root.** The path ACE-Step returns
  is resolved with `realpath()` *before* it is compared, so `../` traversal, an
  absolute path from elsewhere, and a symlink sitting inside the root pointing
  out of it all fail the same check.
- **It will not open something that is not a regular file.** A directory, a
  socket, a device.
- **It will not stage an unexpected type or an oversized file**, and the size is
  checked before the file is read rather than while it is streaming.
- **It will not let a request choose the checkpoint, the device, the output path,
  the step count or the guidance scale.** Those are yours, from this
  configuration. A request that could set them would be a request that can read
  a file, pick a GPU, or make one generation cost fifty times another.
- **It never shells out.** The engine contract is HTTP and the file is opened by
  path with PHP's own functions, so there is no command line for a filename to
  escape from.

## Exposure

ACE-Step's reference server takes `checkpoint_path` and `device_id` from its
client and has no authentication of its own. **Do not expose it.** Bind it to
`127.0.0.1` and let only the worker on the same host reach it.

The worker endpoint is the only thing that needs to be reachable from Core, over
one route:

```
POST /api/generation/worker/render
X-SaniTube-Worker-Token: <secret>
```

Use TLS. The token is a bearer credential and it buys GPU time and a write into
your storage — which is why it is a *different* token from the internal API's.

## What a single host looks like

Nothing changes structurally. One machine sets both halves of the configuration,
storage is the local disk, and Core's outbound call is a loopback request. The
same code runs; only the addresses are shorter.

## Cost and safety

Generation is the one thing in SaniTube that spends money without a person
present. The brakes are unchanged by this document and are worth knowing about:
a request ceiling per rolling window, a per-provider circuit breaker, an atomic
claim so two workers cannot pay twice for one request, the global background-work
pause, and `sanitube:production:run` being **unscheduled by default**.

See `docs/reports/GEN-004.md`, `PROD-004.md` and `PROD-005.md`.
