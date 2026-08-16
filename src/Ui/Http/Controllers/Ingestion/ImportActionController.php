<?php

declare(strict_types=1);

namespace SaniTube\Ui\Http\Controllers\Ingestion;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use SaniTube\Audit\Enums\AuditAction;
use SaniTube\Audit\Services\RecordAuditEvent;
use SaniTube\Ingestion\Exceptions\IngestionException;
use SaniTube\Ingestion\Services\StartIngestionBatch;
use SaniTube\Ui\Http\Requests\Ingestion\StartImportRequest;

/**
 * Starting an import without a terminal.
 *
 * `sanitube:import` is still the right tool for the initial catalogue — nine
 * hundred files are a manifest and a long afternoon, and a browser tab is the
 * wrong place to keep either. What was missing is the ordinary case: a label
 * that dropped twenty new masters in a folder and wants them in, and had to be
 * given SSH to do it.
 *
 * **Nothing is imported inside this request.** `StartIngestionBatch` creates
 * the batch and queues the work; the queue does the rest, and it survives the
 * tab being closed. A controller that fetched, hashed and verified inline
 * would be one that half-runs whenever somebody navigates away — which is the
 * exact shape ING-001 was designed not to have.
 *
 * The person is recorded on the batch. An import is where several hundred
 * things enter the platform at once, and "who started this" is the first
 * question asked when one of them turns out to be wrong.
 */
final class ImportActionController
{
    public function start(
        StartImportRequest $request,
        StartIngestionBatch $starter,
        RecordAuditEvent $audit,
    ): RedirectResponse {
        /** @var User $operator */
        $operator = $request->user();

        try {
            $batch = $starter->handle(
                source: $request->source(),
                prefix: $request->prefix(),
                references: $request->references(),
                provider: $request->provider(),
                createdBy: $operator->getKey(),
            );
        } catch (IngestionException $exception) {
            // A code, not the sentence. The domain's English is written for a
            // developer reading a log, and this platform ships six languages.
            $audit->refused(AuditAction::IngestionBatchStarted, $exception->reason);

            return back()->withErrors(['import' => $exception->reason]);
        }

        // The source and provider, never the prefix or the references: those
        // are object keys in somebody's bucket, and a bucket layout is a thing
        // worth not writing into a table that outlives the import.
        $audit->record(
            AuditAction::IngestionBatchStarted,
            subjectUuid: $batch->uuid,
            context: ['source' => $request->source(), 'provider' => $request->provider()],
        );

        return to_route('ingestion.batches.show', $batch)->with('status', 'ingestion.import_started');
    }
}
