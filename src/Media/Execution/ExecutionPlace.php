<?php

declare(strict_types=1);

namespace SaniTube\Media\Execution;

/**
 * Where a piece of work would actually run, having asked.
 *
 * Distinct from {@see ExecutionStrategy}, which is what an operator *asked
 * for*. This is what the platform can *do*, and the gap between them is the
 * thing a doctor screen has to show: "remote worker selected" and "remote
 * worker reachable and advertising this capability" are different claims, and
 * only the second means anything.
 */
enum ExecutionPlace: string
{
    case Local = 'LOCAL';

    case RemoteWorker = 'REMOTE_WORKER';

    /**
     * Neither. The capability is genuinely unavailable on this installation.
     *
     * Not a failure state: a cPanel account with no FFmpeg and no worker still
     * ingests, catalogues and releases. It simply cannot measure, and saying so
     * is more useful than a green tick that becomes a support ticket.
     */
    case Nowhere = 'UNAVAILABLE';
}
