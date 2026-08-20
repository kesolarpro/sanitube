<?php

declare(strict_types=1);

namespace SaniTube\Ingestion\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use SaniTube\Ingestion\Enums\IngestionBatchStatus;
use SaniTube\Ingestion\Enums\IngestionFailureCode;
use SaniTube\Ingestion\Enums\IngestionItemStatus;
use SaniTube\Ingestion\Enums\IngestionSource;
use SaniTube\Ingestion\Exceptions\IngestionException;
use SaniTube\Ingestion\Jobs\ProcessIngestionItemJob;
use SaniTube\Ingestion\Manifest\Manifest;
use SaniTube\Ingestion\Models\IngestionBatch;
use SaniTube\Ingestion\Models\IngestionItem;
use SaniTube\Ingestion\Sources\SourceReaderFactory;
use SaniTube\Operations\Services\WorkAdmission;
use SaniTube\Storage\StorageManager;

/**
 * Turns a request to import things into a batch and a queue of work.
 *
 * Nothing is fetched here. The request carries references — object keys — and
 * every byte is moved later by a job, which is the only shape in which 900
 * files can be imported without an HTTP request having to survive it.
 *
 * Two things are decided up front rather than per item, on purpose:
 *
 *   - **Acceptability.** A request naming five hundred objects the platform
 *     refuses to import fails once, in the response, rather than five hundred
 *     times in a queue where nobody is watching.
 *   - **Identity.** Each item's ingestion key is computed before anything is
 *     enqueued, so a resubmission is recognised as the same intent and is
 *     recorded as skipped rather than processed twice.
 */
final readonly class StartIngestionBatch
{
    public function __construct(
        private StorageManager $storage,
        private SourceReaderFactory $readers,
        private WorkAdmission $admission,
    ) {}

    /**
     * @param  list<string>  $references  explicit object keys; never combined with a prefix
     * @param  Manifest|null  $manifest  a reference list with evidence attached; never combined with the other two
     */
    public function handle(
        IngestionSource $source,
        ?string $prefix = null,
        array $references = [],
        ?string $provider = null,
        ?int $createdBy = null,
        ?Manifest $manifest = null,
    ): IngestionBatch {
        if (! $source->isImplemented()) {
            throw IngestionException::unsupportedSource($source->value);
        }

        // A manifest *is* the reference list. Accepting one alongside a prefix
        // or an explicit list would mean two things naming what to import with
        // no rule for which wins — which is how an import quietly does
        // something nobody asked for.
        if ($manifest instanceof Manifest && ($prefix !== null || $references !== [])) {
            throw IngestionException::manifestWithOtherSelection();
        }

        // Nothing named at all. Distinguished from "nothing found under the
        // prefix you gave", which is what this used to report: the messages
        // read almost the same and the fixes are opposite — one is a typo in a
        // path, the other is a form submitted empty. ING-002 put this refusal
        // in front of a person, where "Nothing to import under [(no
        // references)]" was not a sentence anybody could act on.
        if (! $manifest instanceof Manifest && $prefix === null && $references === []) {
            throw IngestionException::emptyPrefix();
        }

        // A folder and a list together. This used to keep the folder and drop
        // the list without saying so — the same ambiguity a manifest beside a
        // prefix is refused for, answered differently only because nobody had
        // put the two fields on a screen next to each other yet.
        if ($prefix !== null && $references !== []) {
            throw IngestionException::ambiguousSelection();
        }

        $reader = $this->readers->for($source, $provider);
        $resolved = match (true) {
            $manifest instanceof Manifest => $manifest->references(),
            $prefix !== null => $this->expand($prefix, $provider),
            default => $references,
        };

        if ($resolved === []) {
            throw IngestionException::nothingToImport($prefix ?? '(no references)');
        }

        $limit = max(1, (int) config('ingestion.max_batch', 500));

        if (count($resolved) > $limit) {
            throw IngestionException::batchTooLarge(count($resolved), $limit);
        }

        // Every reference is checked before a single row is written. A batch
        // that is half-refused is worse than one that is refused.
        foreach ($resolved as $reference) {
            $reader->assertAcceptable($reference);
        }

        // And so is a batch the queue has no room for. OPS-002: this loop
        // enqueues one job per item, so the same reasoning that puts
        // acceptability up here puts capacity up here too -- refusing now,
        // while somebody is looking at the response, beats stranding half a
        // batch in a queue nobody is watching.
        $this->admission->admit(count($resolved));

        $batch = DB::transaction(function () use ($source, $createdBy, $resolved, $reader, $manifest): IngestionBatch {
            $batch = IngestionBatch::query()->create([
                'source' => $source,
                'status' => IngestionBatchStatus::Pending,
                'created_by' => $createdBy,
            ]);

            foreach ($resolved as $reference) {
                $key = IngestionKey::for($source, $reference);
                $row = $manifest?->rowFor($reference);

                $batch->items()->create([
                    'ingestion_key' => $key,
                    'source_reference' => $reference,
                    'original_filename' => $reader->originalFilename($reference),
                    // Recorded on the item, not only carried in memory: a
                    // retry after a crash has to know what the manifest said,
                    // and re-reading the operator's CSV is not available to a
                    // queue worker.
                    'manifest_metadata' => $row?->toEvidence(),
                    // Something already working on this exact intent means
                    // this item has nothing to do — recorded, not silently
                    // dropped, so the batch still accounts for every
                    // reference the caller named.
                    ...$this->isInFlightElsewhere($key)
                        ? [
                            'status' => IngestionItemStatus::Skipped,
                            'failure_code' => IngestionFailureCode::AlreadyInFlight,
                            'failure_message' => 'Another item with the same ingestion key is already in flight.',
                        ]
                        : ['status' => IngestionItemStatus::Pending],
                ]);
            }

            return $batch;
        });

        $this->dispatchPending($batch);

        return $batch->refresh();
    }

    /**
     * Object keys under a prefix, with the platform's own output excluded.
     *
     * @return list<string>
     */
    private function expand(string $prefix, ?string $provider): array
    {
        $reader = $this->readers->for(IngestionSource::CloudImport, $provider);
        $reader->assertAcceptable($prefix);

        $store = $provider === null ? $this->storage->default() : $this->storage->provider($provider);

        return array_values(array_filter(
            $store->files($prefix),
            function (string $key) use ($reader): bool {
                try {
                    $reader->assertAcceptable($key);

                    return true;
                } catch (IngestionException) {
                    return false;
                }
            },
        ));
    }

    /**
     * Whether some other item is already working on this intent.
     *
     * The unique index on `(ingestion_key, active_marker)` would refuse the
     * insert anyway; asking first is what turns a constraint violation into a
     * recorded, explainable outcome.
     */
    private function isInFlightElsewhere(string $key): bool
    {
        return IngestionItem::query()
            ->where('ingestion_key', $key)
            ->whereNotNull('active_marker')
            ->exists();
    }

    private function dispatchPending(IngestionBatch $batch): void
    {
        $pending = $batch->items()->where('status', IngestionItemStatus::Pending->value)->get();

        if ($pending->isEmpty()) {
            // Everything was skipped. There is no work to do and no job to
            // wait for, so the batch is finished the moment it is created.
            app(FinalizeIngestionBatch::class)->handle($batch);

            return;
        }

        $batch->forceFill([
            'status' => IngestionBatchStatus::Processing,
            'started_at' => Carbon::now(),
        ])->save();

        foreach ($pending as $item) {
            ProcessIngestionItemJob::dispatch($item->uuid);
        }
    }
}
