<?php

declare(strict_types=1);

namespace SaniTube\Ui\Queries;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * What the queue is doing, and what it failed at.
 *
 * **No payload ever leaves this class, and no exception text either.**
 *
 * A queued job's `payload` is the serialised job object. SaniTube's jobs carry
 * uuids rather than models by convention, but a payload is not a field this
 * platform controls the contents of — a future job, a package's job, a
 * Laravel-internal job — and "we believe nothing sensitive is in there" is not
 * a boundary. What a person watching a queue needs is what kind of work is
 * waiting and how long it has waited, and that is derivable from the class name
 * and the timestamps.
 *
 * A failed job's `exception` is a stack trace: absolute filesystem paths, the
 * application's directory layout, often the arguments that were being passed.
 * The **first line** — the exception class and its message — is what tells an
 * operator whether this is a provider outage or a bug, and it is the only part
 * that travels. Even that is truncated, because a message can be arbitrarily
 * long and some of them quote what they were given.
 *
 * The tables are read directly rather than through a model. They are Laravel's,
 * not the domain's, and giving them Eloquent models would invite somebody to
 * treat a queue entry as an aggregate.
 */
final readonly class QueueQuery
{
    public const PER_PAGE = 50;

    /** How much of an exception's first line is worth showing. */
    private const MESSAGE_LIMIT = 200;

    /**
     * Counts a person watching a queue actually acts on.
     *
     * Every figure is nullable and null means **unknown**, not zero: the
     * database queue tables are optional in Laravel, and an installation using
     * Redis or SQS has no `jobs` table at all. Rendering 0 there would say
     * "nothing is waiting" when the truth is "this screen cannot see the
     * queue".
     *
     * @return array<string, mixed>
     */
    public function summary(): array
    {
        return [
            'driver' => (string) config('queue.default'),
            'readable' => $this->tableExists('jobs') && $this->tableExists('failed_jobs'),
            'pending' => $this->count('jobs'),
            'reserved' => $this->reserved(),
            'failed' => $this->count('failed_jobs'),
            'oldest_pending_at' => $this->oldestPending(),
        ];
    }

    /**
     * Waiting work, oldest first — the order somebody diagnosing a backlog
     * reads it in.
     *
     * @return array<string, mixed>
     */
    public function pending(int $perPage = self::PER_PAGE): array
    {
        if (! $this->tableExists('jobs')) {
            return ['readable' => false, 'rows' => []];
        }

        $rows = [];

        $records = DB::table('jobs')
            ->select(['id', 'queue', 'payload', 'attempts', 'reserved_at', 'available_at', 'created_at'])
            ->orderBy('id')
            ->limit($perPage)
            ->get();

        foreach ($records as $record) {
            /** @var object{id: int, queue: string, payload: string, attempts: int, reserved_at: int|null, available_at: int, created_at: int} $record */
            $rows[] = [
                'id' => (int) $record->id,
                'queue' => (string) $record->queue,
                // The class name only. Never the payload it came from.
                'name' => $this->jobName((string) $record->payload),
                'attempts' => (int) $record->attempts,
                'reserved' => $record->reserved_at !== null,
                'available_at' => $this->moment((int) $record->available_at),
                'created_at' => $this->moment((int) $record->created_at),
            ];
        }

        return ['readable' => true, 'rows' => $rows];
    }

    /**
     * What broke, newest first.
     *
     * @return array<string, mixed>
     */
    public function failed(int $perPage = self::PER_PAGE): array
    {
        if (! $this->tableExists('failed_jobs')) {
            return ['readable' => false, 'rows' => []];
        }

        $rows = [];

        $records = DB::table('failed_jobs')
            ->select(['uuid', 'connection', 'queue', 'payload', 'exception', 'failed_at'])
            ->orderByDesc('id')
            ->limit($perPage)
            ->get();

        foreach ($records as $record) {
            /** @var object{uuid: string, connection: string, queue: string, payload: string, exception: string, failed_at: string} $record */
            $rows[] = [
                'uuid' => (string) $record->uuid,
                'queue' => (string) $record->queue,
                'connection' => (string) $record->connection,
                'name' => $this->jobName((string) $record->payload),
                // First line only, truncated. See the class comment.
                'error' => $this->firstLine((string) $record->exception),
                'failed_at' => $this->timestamp((string) $record->failed_at),
            ];
        }

        return ['readable' => true, 'rows' => $rows];
    }

    /**
     * The job's class name, pulled out of the serialised payload.
     *
     * Reading one field out of a structure is not the same as publishing the
     * structure. A payload that does not parse yields null rather than a guess,
     * and null renders as "unknown kind of work" — which is true, and is a
     * different fact from a job with no name.
     */
    private function jobName(string $payload): ?string
    {
        $decoded = json_decode($payload, true);

        if (! is_array($decoded)) {
            return null;
        }

        $name = $decoded['displayName'] ?? null;

        return is_string($name) && $name !== '' ? $name : null;
    }

    /**
     * The exception class and message, without the trace beneath it.
     */
    private function firstLine(string $exception): ?string
    {
        $line = trim(strtok($exception, "\n") ?: '');

        if ($line === '') {
            return null;
        }

        // Truncated: a message can be arbitrarily long, and some of them quote
        // the input that caused them.
        return mb_strlen($line) > self::MESSAGE_LIMIT
            ? mb_substr($line, 0, self::MESSAGE_LIMIT).'…'
            : $line;
    }

    private function count(string $table): ?int
    {
        if (! $this->tableExists($table)) {
            return null;
        }

        try {
            return (int) DB::table($table)->count();
        } catch (Throwable) {
            return null;
        }
    }

    private function reserved(): ?int
    {
        if (! $this->tableExists('jobs')) {
            return null;
        }

        try {
            return (int) DB::table('jobs')->whereNotNull('reserved_at')->count();
        } catch (Throwable) {
            return null;
        }
    }

    private function oldestPending(): ?string
    {
        if (! $this->tableExists('jobs')) {
            return null;
        }

        try {
            /** @var int|null $created */
            $created = DB::table('jobs')->min('created_at');

            return $created === null ? null : $this->moment((int) $created);
        } catch (Throwable) {
            return null;
        }
    }

    private function tableExists(string $table): bool
    {
        try {
            return Schema::hasTable($table);
        } catch (Throwable) {
            // An unreachable database is a reason to report unknown, never a
            // reason to fail the page that asked.
            return false;
        }
    }

    private function moment(int $unixSeconds): string
    {
        return Carbon::createFromTimestampUTC($unixSeconds)->toAtomString();
    }

    private function timestamp(string $value): ?string
    {
        try {
            return Carbon::parse($value)->toAtomString();
        } catch (Throwable) {
            return null;
        }
    }
}
