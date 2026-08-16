<?php

declare(strict_types=1);

namespace SaniTube\Observability\Services;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\ConnectionInterface;
use SaniTube\Foundation\Concerns\SafelyRetryable;
use SaniTube\Observability\Exceptions\FailedJobException;
use Throwable;

/**
 * What an operator may do about a job that failed.
 *
 * Two actions, and the interesting one is the first.
 *
 * **Retry is refused unless the job says it is safe.** The obvious version of
 * this screen — a retry button on every row — is the wrong one. A failed job is
 * a job that got *somewhere* before it stopped, and this platform has one
 * operation it must never perform twice: handing a release to a distributor. A
 * generic button offers that too, and trusts the person pressing it to know
 * which rows are which at the moment they are least able to reason carefully.
 *
 * So the job declares it, by implementing {@see SafelyRetryable}, and this
 * asks the type. Opt in, never opt out: a job added later is unretryable until
 * somebody has thought about it, and the same omission under an opt-out scheme
 * would produce a button.
 *
 * **Delete removes the record, not the work.** A failed job that will never be
 * retried is a row an operator has read and dealt with, and leaving it forever
 * makes the screen useless for the next failure. What it must not do is look
 * like undoing: the deletion is of the *failure record*, and the interface says
 * so.
 *
 * Neither action ever puts the serialised payload in front of a person. It
 * carries whatever the job was constructed with — model attributes, references,
 * occasionally a signed URL — and a screen that showed it would publish all of
 * that to anybody who can read an admin page.
 *
 * Retry goes through Laravel's own `queue:retry`, which knows how to rebuild a
 * payload for the connection it came from. Re-implementing that here would be
 * a second, worse copy of a solved problem.
 */
final readonly class ResolveFailedJob
{
    public function __construct(
        private ConnectionInterface $connection,
        private Kernel $console,
    ) {}

    /**
     * @throws FailedJobException
     */
    public function retry(string $uuid): void
    {
        $record = $this->find($uuid);

        $class = $this->jobClass((string) $record->payload);

        if ($class === null) {
            throw FailedJobException::unknownKind();
        }

        if (! is_a($class, SafelyRetryable::class, allow_string: true)) {
            // Named, so the screen can say *which* job it will not retry
            // without publishing what the job was carrying.
            throw FailedJobException::notRetryable(class_basename($class));
        }

        $this->console->call('queue:retry', ['id' => [$uuid]]);
    }

    /**
     * @throws FailedJobException
     */
    public function forget(string $uuid): void
    {
        $this->find($uuid);

        $this->connection->table('failed_jobs')->where('uuid', $uuid)->delete();
    }

    /**
     * @throws FailedJobException
     */
    private function find(string $uuid): object
    {
        $record = $this->connection->table('failed_jobs')->where('uuid', $uuid)->first();

        if ($record === null) {
            // Already retried, already deleted, or a stale page. Distinct from
            // "not retryable": nothing is there to act on.
            throw FailedJobException::gone();
        }

        return $record;
    }

    /**
     * The job's class name, read out of the serialised payload.
     *
     * Reading one field out of a structure is not publishing the structure.
     * A payload that does not parse yields null, and null is "unknown kind of
     * work" — which is true, and is a different fact from a job with no name.
     *
     * @return class-string|null
     */
    private function jobClass(string $payload): ?string
    {
        try {
            $decoded = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }

        if (! is_array($decoded)) {
            return null;
        }

        /** @var array{data?: array{commandName?: mixed}} $decoded */
        $name = $decoded['data']['commandName'] ?? null;

        return is_string($name) && class_exists($name) ? $name : null;
    }
}
