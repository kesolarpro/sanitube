# Certifying a worker

```
php artisan sanitube:worker:check
php artisan sanitube:worker:check --json
```

Run it once, from the machine that runs SaniTube, after you have set
`SANITUBE_WORKER_URL` and `SANITUBE_WORKER_TOKEN`. It exits non-zero if
anything failed, so a deployment script can gate on it.

## Why it exists

Everything about the worker boundary — the handshake, the refusals, the
containment — is tested against a **faked transport**. That is the right way to
test it, and it says nothing about the machine at the end of a URL you typed
into `.env`.

This is the counterpart to `sanitube:storage:check --certify`.

## What it proves

| Check | What a failure means |
| --- | --- |
| `configured` | No URL and token are set. Skipped, not failed — an installation with no worker is a supported one. |
| `handshake` | The worker did not answer, or answered in a shape this build does not understand. Check the URL, the token, and that it is running. |
| `capabilities` | It announced nothing it can do, so nothing would ever be sent to it. |
| `capabilities_runnable` | Never fails. Reports what it was **built with** but **cannot run** — the handler is installed and its tools are not. This is usually the most useful line in the report. |
| `refuses_unknown_work` | It accepted a capability it does not announce. A worker that answers whatever it is sent has a capability list that describes an intention rather than a boundary. |
| `token_enforced` | It answered a request carrying the wrong token. Anybody who can reach it can use it. |

## What it does not prove

**A real job.** Nothing here asks the worker to process a master, so nothing
here says it can reach your object storage or finish real work. Run one import
and watch a candidate appear.

It is also read-only against the worker's world: it writes nothing, uploads
nothing, and leaves no state behind.

## What it never prints

The token — not the value, not a prefix, not from an exception it caught. And
no address: the worker is named by the name it announces about itself, because
the URL is the one thing in a report that says where a private machine lives,
and this output is what gets pasted into a support thread.
