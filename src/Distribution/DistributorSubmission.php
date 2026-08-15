<?php

declare(strict_types=1);

namespace SaniTube\Distribution;

/**
 * What a distributor returned when it was handed something.
 *
 * `externalReleaseId` is its handle on the package. It is stored and used for
 * every later status poll, and it never crosses the API boundary — it
 * identifies a record in someone else's account.
 */
final readonly class DistributorSubmission
{
    public function __construct(
        public bool $accepted,
        public ?string $externalReleaseId = null,
        public DeliveryStatus $status = DeliveryStatus::Draft,
        public ?string $failureReason = null,
    ) {}

    public static function failed(string $reason, DeliveryStatus $status = DeliveryStatus::Failed): self
    {
        return new self(accepted: false, status: $status, failureReason: $reason);
    }
}
