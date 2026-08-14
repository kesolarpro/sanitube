# ADR-0005 — Generation extend/remix/stems deferred to GEN-001

- **Status:** Deferred — resolved by GEN-001
- **Date:** 2026-08-14
- **Ticket:** ARCH-001

## Context

The ARCH-001 specification called for a `MusicGenerationProviderInterface`
covering `generate`, `getStatus`, `getResult`, `extend`, `remix`, `getStems`
and `cancel`.

The first four are provider-independent: every asynchronous generation API
has a way to start a job, poll it, collect a result and abandon it. The last
three are not.

- **`extend`** — continue an existing generation. Providers disagree about
  whether this yields a new job, a new version of the same job, or a
  continuation identified by an offset.
- **`remix`** — a marketing term more than a technical one. It variously
  means re-generation with a modified prompt, style transfer over existing
  audio, or a variation seeded from a previous result. These have different
  inputs and different rights implications.
- **`getStems`** — stem separation. Some providers return a fixed set, some a
  requested subset, some do it asynchronously as a separate job with its own
  lifecycle, and some do not offer it at all.

Suno is the intended first implementation, and it may have no usable official
API. The specification is explicit that an unofficial API must not be used as
a foundation. So these three methods would have been designed against no
provider whatsoever.

There is a rights dimension that makes guessing worse than usual. Whether a
derived generation inherits the commercial-use terms of its parent is a
question about a *specific provider's* terms, not a modelling preference. A
contract that assumes the wrong answer produces a catalogue with unclear
commercial rights, and that damage surfaces at distribution — long after the
code was written.

## Decision

Declare the lifecycle that is genuinely provider-independent:

```php
interface MusicGenerationProvider
{
    public function name(): string;
    public function isAvailable(): bool;
    public function generate(GenerationRequest $request): GenerationResult;
    public function status(string $providerJobId): GenerationResult;
    public function cancel(string $providerJobId): bool;
}
```

Two things this pins down deliberately:

- **Generation is asynchronous.** `generate()` returns a pending result with a
  provider job id; the audio is collected later. `FakeMusicGenerationProvider`
  models the same lifecycle, so no caller can quietly assume a synchronous
  provider and then break against a real one.
- **A completed generation is not a track.** `GenerationResult::audioUrl` is
  where the provider is currently serving bytes — a transfer detail. Nothing
  is catalogue until it has been fetched, hashed and registered in the Asset
  Registry. `hasDownloadableAudio()` exists so that a provider reporting
  success with no file is treated as a failure rather than an empty track.

Deferred to **GEN-001**: `extend`, `remix`, `stems`, and the provenance
question of whether a derived generation inherits its parent's
commercial-rights status.

## Consequences

**Accepted:**

- The Studio, campaign engine and ingestion pipeline are buildable and fully
  testable today, with no external music API in existence.
- The asynchronous contract is fixed early, which is the part that is
  expensive to retrofit.
- No rights assumption is baked into a type.

**Costs:**

- A provider offering stems cannot expose them through the contract until
  GEN-001.
- GEN-001 must extend an interface that already has callers, so the addition
  has to be backwards-compatible or coordinated.

## Revisit when

**GEN-001**, once one holds:

1. An official, commercially usable generation API is available and its terms
   have been read — including what they say about derived works; or
2. A different provider is selected as the first real implementation.

Until then the fake remains the only implementation, and that is a working
state, not a blocked one.
