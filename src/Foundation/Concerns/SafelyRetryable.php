<?php

declare(strict_types=1);

namespace SaniTube\Foundation\Concerns;

/**
 * A job that can be run again without doing its work twice.
 *
 * SYS-001b needed a "retry" button on the failed-jobs screen, and the obvious
 * version of it — retry whatever is in `failed_jobs` — is the wrong one. A
 * failed job is a job that got *somewhere* before it stopped, and the platform
 * has exactly one operation it must never perform twice: handing a release to
 * a distributor. A generic retry offers that button for everything and trusts
 * an operator to know which is which.
 *
 * So retryability is **declared by the job**, not decided by the screen. A job
 * that implements this is saying it has its own guard — an idempotency key, a
 * unique index, a status claim — and that running it again converges rather
 * than duplicates. Anything that does not implement it gets no retry button
 * and a reason instead.
 *
 * The direction matters: opt in, never opt out. A new job added later is not
 * retryable until somebody has thought about whether it is, which is the safe
 * default. Opting out would make the same omission produce a button.
 *
 * This is a marker with no methods on purpose. There is nothing to implement;
 * the claim *is* the type, and a job that wants to make it has to say so in a
 * place a reviewer reads.
 */
interface SafelyRetryable {}
