<?php

declare(strict_types=1);

namespace SaniTube\Distribution\Contracts;

use SaniTube\Distribution\DeliveryStatus;

/**
 * A music distributor, seen from inside SaniTube.
 *
 * Too Lost, TuneCore and LabelGrid are adapters behind this contract. Their
 * DTOs, their identifiers and their status wording never cross it: the
 * catalogue is the source of truth and a distributor is an outbound channel.
 *
 * Scope note (ARCH-001): only the identity and read-side of the contract is
 * fixed here. The write-side — createRelease, uploadAudio, uploadArtwork,
 * validateRelease, submitRelease, requestTakedown — is deliberately deferred
 * to DIST-001, when the Release aggregate exists. Typing those methods today
 * would mean inventing payloads, and the first real adapter would then either
 * bend the domain to fit or force the interface to be rewritten. The read-side
 * below is already enough to build delivery tracking, status polling and the
 * distribution screens against.
 */
interface Distributor
{
    /**
     * Configuration name, e.g. "toolost", "tunecore".
     */
    public function name(): string;

    /**
     * False when credentials are missing or the integration is switched off.
     * An unavailable distributor must degrade the Distribution screens, never
     * the catalogue.
     */
    public function isAvailable(): bool;

    /**
     * Whether this instance talks to the provider's sandbox. Submitting a real
     * release through a sandbox — or a test through production — is exactly the
     * kind of mistake that must be visible in the UI.
     */
    public function isSandbox(): bool;

    /**
     * Current state of a delivery, normalised to SaniTube's vocabulary.
     */
    public function deliveryStatus(string $externalReleaseId): DeliveryStatus;
}
