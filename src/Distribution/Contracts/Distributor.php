<?php

declare(strict_types=1);

namespace SaniTube\Distribution\Contracts;

use SaniTube\Distribution\DeliveryStatus;
use SaniTube\Distribution\DistributorSubmission;
use SaniTube\Distribution\DistributorValidation;
use SaniTube\Releases\Models\Release;

/**
 * A music distributor, seen from inside SaniTube.
 *
 * Too Lost, TuneCore and LabelGrid are adapters behind this contract. Their
 * DTOs, their identifiers and their status wording never cross it: the
 * catalogue is the source of truth and a distributor is an outbound channel.
 *
 * ARCH-001 fixed the identity and read-side and deferred the write-side to
 * DIST-001, when the Release aggregate would exist. It does now, and this is
 * that finalisation.
 *
 * **Five methods.** `validateRelease` / `prepareRelease` / `submitRelease` /
 * `deliveryStatus` / `requestTakedown`, and nothing else. Royalties and
 * analytics are *not* here: a distributor that reports earnings and one that
 * only delivers are both distributors, and a contract demanding both would
 * force every adapter to stub the half it does not do. Those arrive as
 * capability interfaces when a real adapter needs them.
 *
 * Validation and submission are separate because a label needs to know a
 * package will be accepted *before* handing it over — every distributor's
 * checks are stricter than SaniTube's, and discovering that during submission
 * means discovering it on a delivery that has already half-happened.
 *
 * `prepareRelease` exists between them because uploading audio and artwork is
 * the slow, resumable part, and a contract that folded it into `submitRelease`
 * would make every retry re-upload a set of masters.
 *
 * Every method takes an idempotency key. A retry after a timeout — where
 * SaniTube does not know whether the distributor received the package — must
 * be recognisable as a repeat rather than a second delivery.
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

    /**
     * Ask the distributor whether it would accept this package.
     *
     * Read-only and repeatable: it must change nothing at the distributor. A
     * label runs it while still assembling, and a validation that quietly
     * created a draft release upstream would leave abandoned records behind on
     * every attempt.
     */
    public function validateRelease(Release $release): DistributorValidation;

    /**
     * Upload what the distributor needs before it can be given the release —
     * masters, artwork — and return its handle on the prepared package.
     *
     * Separate from submission because this is the slow part, and because a
     * retry must not re-upload masters it already sent. Repeatable under the
     * same key.
     */
    public function prepareRelease(Release $release, string $idempotencyKey): DistributorSubmission;

    /**
     * Hand the release over.
     *
     * The one irreversible call in this contract. Under the same idempotency
     * key it must be safe to repeat: a distributor that honours the key
     * returns the original submission rather than creating a second.
     */
    public function submitRelease(Release $release, string $idempotencyKey): DistributorSubmission;

    /**
     * Ask for the release to be removed from stores.
     */
    public function requestTakedown(string $externalReleaseId, string $idempotencyKey): DistributorSubmission;
}
