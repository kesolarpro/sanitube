# ADR-0019 — A generation provider may be synchronous

- **Status:** Accepted
- **Date:** 2026-08-21
- **Ticket:** GEN-007
- **Supersedes nothing.** Corrects an assumption stated in GEN-001's
  `MusicGenerationProvider` docblock and extends GEN-006's capability model.

## Context

GEN-001 wrote the music provider contract around one sentence:

> Generation is asynchronous by nature: `createGeneration()` starts a job and
> returns immediately; results are collected later by polling.

That was true of every supplier then in view, and it was never verified against
one, because none was reachable. GEN-006's research changed that. Of four
candidates, exactly one had a contract this environment could read from a
primary source — ACE-Step 1.5, read from its own repository — and it works the
other way:

```
POST /generate  →  { status, output_path, message }
GET  /health    →  { status }
```

The call **blocks and returns the audio**. There is no job identifier, no status
endpoint, no webhook and no cancellation, because there is nothing left running
to ask about or to stop.

Under the old contract, an adapter for such a supplier had exactly one road: mint
an identifier for a job that had already finished, store it, and then answer
polls about it from memory. Every part of that is fiction. Worse, it is fiction
an adapter would have to maintain — a fabricated identifier is a value other
code will eventually be written against.

The general problem is larger than one supplier. A contract that describes one
integration's shape as though it were the nature of the domain will keep
producing adapters that lie, and each lie is load-bearing by the time anybody
notices.

## Decision

**Execution shape is a property of a provider, discovered from its interface,
and never assumed.**

Concretely:

1. `GenerationProvider` holds only what every supplier shares — `name()` and
   `isAvailable()`. Anything that *resolves* a provider works at this level:
   the manager, capability discovery, selection.
2. Two sub-contracts describe how audio comes back:
   - `MusicGenerationProvider` — asynchronous. Unchanged, and every existing
     adapter satisfies it without modification.
   - `SynchronousMusicGenerationProvider` — `generate()` returns the audio.
3. A provider implements one, or both if its supplier genuinely offers both. It
   **never implements a method it has no answer for.**
4. `GenerationExecution` is the single value both roads produce: finished with
   results, or in flight with a reference. It is constructible only through two
   named factories, so the states this design exists to exclude — finished *and*
   holding a reference, or neither — cannot be built.
5. `ExecutionMode` is recorded on the generation row. Provenance, not routing:
   *"why does this row have no provider job identifier"* must be answerable long
   after the supplier that produced it has been removed from the installation.
6. `SYNCHRONOUS` and `ASYNC_POLLING` join GEN-006's capability vocabulary, and
   are **structural** — read off the sub-contract, not declared. Implementing an
   interface is a method that exists, which is the strongest evidence available
   short of running it.

**A synchronous generation never gets a provider job identifier.**
`provider_job_id` stays null for the life of the row, and nothing polls it.

## Consequences

### What this costs

A synchronous call holds a queue worker for as long as the generation takes —
minutes, for music. Nothing calls one from a web request; submission was already
a queued job. Implementations must bound their own wait, because a supplier that
never answers would otherwise hold a worker until the process is killed, and a
killed worker is what PROD-005 then has to clean up.

`MusicGenerationManager` now resolves the wider type, so the two services that
actually run a generation narrow with `instanceof`. That is the correct place
for the branch and the only place it exists: `SubmitMusicGeneration::execute()`.

### What it fixes on the way

Idempotency moves from `provider_job_id` to **`submitted_at`**. The old guard
was keyed on a column a synchronous provider never fills, so every redelivery
would have called such a supplier again. `submitted_at` is written exactly when
a request leaves this server — which is the question being asked, and already
what the spend guard counts.

### What it does not decide

`ASYNCHRONOUS_WEBHOOK` and `ASYNC_HYBRID` are named and unreachable. SaniTube has
no generation webhook endpoint; the modes exist so that the day one is built,
the vocabulary already has a word for what it produces rather than one being
invented inside whichever adapter needs it first.

Nothing here authorises an adapter. ADR-0018 still governs that, and it is
unchanged: a contract must be published by its supplier and read from a primary
source.
