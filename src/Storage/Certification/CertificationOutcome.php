<?php

declare(strict_types=1);

namespace SaniTube\Storage\Certification;

/**
 * How one certification check ended.
 *
 * **Three states, because two would lie.** The local disk cannot mint a signed
 * URL, and reporting that as a failure would tell an operator to go and fix a
 * correct configuration. Reporting it as a pass would be worse: it would
 * certify a capability the installation does not have. It is *skipped*, and the
 * report says why.
 */
enum CertificationOutcome: string
{
    case Passed = 'passed';

    case Failed = 'failed';

    /**
     * Not applicable to this provider.
     *
     * A fact about the deployment, not a defect in it — and never counted as a
     * pass, so an installation cannot be certified for something it never did.
     */
    case Skipped = 'skipped';
}
