<?php

declare(strict_types=1);

namespace SaniTube\Storage\Certification;

use RuntimeException;

/**
 * A certification check that answered, and answered wrongly.
 *
 * Distinct from whatever the SDK throws when a request cannot be made at all —
 * both end the same check, but this one carries a sentence written for the
 * operator reading the report rather than a stack of vendor detail.
 */
final class CertificationFailed extends RuntimeException {}
