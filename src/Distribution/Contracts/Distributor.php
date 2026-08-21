<?php

declare(strict_types=1);

namespace SaniTube\Distribution\Contracts;

use SaniTube\Distribution\DeliveryStatus;
use SaniTube\Distribution\DistributorSubmission;
use SaniTube\Distribution\DistributorValidation;
use SaniTube\Releases\Packaging\ReleasePackage;

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
 * **An adapter is handed a {@see ReleasePackage} and never the aggregate.**
 * That is the whole boundary, and DIST-007 moved it here after DIST-004 proved
 * the defect it prevents: the manual exporter walked the release itself —
 * tracks, credits, identifiers, assets — and had to be rewritten in DIST-006 to
 * render the package instead. An adapter that reaches into the aggregate is a
 * second place that decides what a delivery contains, and every one of them
 * reaches differently: its own chance to read a revoked identifier as current,
 * its own answer to "what did we actually send".
 *
 * The package is assembled once, from a release that has already validated,
 * and is immutable. It carries no secret and no location — assets are named by
 * uuid, and an adapter needing bytes asks the storage service, which is the
 * only thing that knows where anything lives.
 *
 * What an adapter *may* still hold is its own configuration and its own
 * transport: an endpoint, a credential, a wire format. Those are the
 * provider's, and they never enter the package. `ADR-0020` records the
 * boundary.
 *
 * **Five methods.** `validateRelease` / `prepareRelease` / `submitRelease` /
 * `deliveryStatus` / `requestTakedown`, and nothing else. Royalties and
 * analytics are *not* here: a distributor that reports earnings and one that
 * only delivers are both distributors, and a contract demanding both would
 * force every adapter to stub the half it does not do. Those arrive as
 * capability interfaces when a real adapter needs them.
 *
 * DIST-001-H1 needed one and took that route rather than widening this:
 * {@see SupportsSubmissionLookup} asks a distributor whether it already holds
 * a submission. An adapter whose provider has no such endpoint simply does not
 * implement it, and "I cannot look" is answered by the type rather than by an
 * exception thrown from a method the adapter was obliged to declare.
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
    public function validateRelease(ReleasePackage $package): DistributorValidation;

    /**
     * Upload what the distributor needs before it can be given the release —
     * masters, artwork — and return its handle on the prepared package.
     *
     * Separate from submission because this is the slow part, and because a
     * retry must not re-upload masters it already sent. Repeatable under the
     * same key.
     */
    public function prepareRelease(ReleasePackage $package, string $idempotencyKey): DistributorSubmission;

    /**
     * Hand the release over.
     *
     * The one irreversible call in this contract. Under the same idempotency
     * key it must be safe to repeat: a distributor that honours the key
     * returns the original submission rather than creating a second.
     */
    public function submitRelease(ReleasePackage $package, string $idempotencyKey): DistributorSubmission;

    /**
     * Ask for the release to be removed from stores.
     */
    public function requestTakedown(string $externalReleaseId, string $idempotencyKey): DistributorSubmission;
}
