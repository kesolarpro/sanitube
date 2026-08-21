# The SaniTube worker

SaniTube Core runs where an operator can host PHP: cPanel, a small VPS,
anywhere. Some work cannot run there — a music model needs a GPU, and shared
hosting frequently has no FFmpeg at all. A **worker** is the same SaniTube
application installed on a host that can, reached over one authenticated
boundary.

**One boundary, addressed by capability.** Music generation is the first thing a
worker can do and is not meant to be the last; adding another needs a handler
and a registration, not a second protocol, a second token and a second thing to
deploy.

This document is why the split exists and how to deploy it.

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

# The worker boundary. One address and one token, whatever capabilities the
# worker offers — generation today, media processing later.
SANITUBE_WORKER_URL=https://gpu.example.internal
SANITUBE_WORKER_TOKEN=<a long random secret>

SANITUBE_GENERATION_WORKER_MODEL_LABEL="ACE-Step 1.5"
```

Core also needs the **same object storage** as the worker. On a split
deployment that means R2 (or any S3-compatible bucket) configured identically on
both. On a single host it is already the same disk.

## What the worker needs

The same application, plus:

```dotenv
# Makes the worker routes exist. Unset, they answer 404.
SANITUBE_WORKER_TOKEN=<the same secret Core sends>

# What to call this machine. It appears in audit lines, so it is a label you
# chose and never a hostname — a rebuilt machine keeps its name here.
SANITUBE_WORKER_IDENTITY=gpu-one

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

Two routes need to be reachable from Core, and only two however many
capabilities the worker offers:

```
GET  /api/worker                        → who I am and what I can do
POST /api/worker/jobs/music-generation  → do this
X-SaniTube-Worker-Token: <secret>
```

The handshake is what Core asks first. It reports this worker's name, its
version, the capabilities it can carry out **right now**, the ones it knows how
to do at all, and the versions of its tools. It is cheap and cached, because
screens poll it — a studio deciding whether to offer a generate button asks the
worker rather than assuming from configuration.

That distinction matters on a bad day: a worker whose ACE-Step is switched off
still answers the handshake, omits `MUSIC_GENERATION` from its capabilities, and
Core stops offering generation instead of offering it and failing.

Use TLS. The token is a bearer credential and it buys GPU time and a write into
your storage — which is why it is a *different* token from the internal API's.

## Media processing on a worker

A worker can also probe files and take fingerprints, which is the answer for a
cPanel account with no FFmpeg. Nothing has to be migrated: local execution is
unchanged and remains right for a VPS that has the tools.

On Core, choose where the work runs:

```dotenv
# auto           prefer the worker when it advertises the capability, use this
#                machine otherwise, report unavailable when neither can. The
#                shipped default.
# local          always this machine. A worker offering the capability is not
#                an invitation — `local` means local.
# remote_worker  always the worker, and report the capability unavailable when
#                it cannot serve. It does not quietly fall back: an operator who
#                chose a worker usually had a reason a silent fallback defeats.
SANITUBE_MEDIA_EXECUTION=auto
```

The worker needs the binaries and nothing else — it registers `MEDIA_PROBE` and
`AUDIO_FINGERPRINT` automatically and reports them as runnable only when the
tools are actually present:

```bash
# Debian/Ubuntu
sudo apt install ffmpeg libchromaprint-tools

# AlmaLinux/Rocky
sudo dnf install ffmpeg chromaprint-tools
```

**The bytes never pass through Core.** The worker is handed a storage key and
reads the object from the storage both sides share, so an installation on cPanel
talking to R2 does not stream a master down only to send it back up.

`php artisan sanitube:doctor` reports what would actually happen rather than what
is configured:

| Reported | Means |
| --- | --- |
| `LOCAL_AVAILABLE` | the tools are here and being used |
| `REMOTE_WORKER_AVAILABLE` | the worker is doing it |
| `REMOTE_WORKER_UNAVAILABLE_LOCAL_AVAILABLE` | **degraded** — work is happening on the machine you did not choose |
| `UNAVAILABLE` | neither; assets are stored and catalogued but not measured |

A worker URL and token in a `.env` never produce a green tick on their own: the
handshake has to succeed and advertise the capability.

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
