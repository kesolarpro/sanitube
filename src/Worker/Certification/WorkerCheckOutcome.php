<?php

declare(strict_types=1);

namespace SaniTube\Worker\Certification;

enum WorkerCheckOutcome: string
{
    case Passed = 'PASSED';
    case Failed = 'FAILED';

    /** Never asked, and the report says why rather than leaving a gap. */
    case Skipped = 'SKIPPED';
}
