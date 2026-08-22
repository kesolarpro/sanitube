<?php

declare(strict_types=1);

namespace SaniTube\Ingestion\Services;

use Illuminate\Support\Str;
use SaniTube\Assets\Enums\AssetKind;
use SaniTube\Assets\Services\UploadAdmission;
use SaniTube\Assets\Storage\OriginalFilename;

/**
 * Where a file a person picked in a browser waits until a batch refers to it.
 *
 * `ManualUploadReader` has always said the bytes "arrive through the ordinary
 * storage pipeline into a reserved inbox prefix, and the batch refers to them
 * by key". Everything downstream of that sentence was built — the reader, the
 * batch, the per-item job, the retries, the candidate, the screens — and
 * **nothing could put a byte in the inbox from a browser**. The one route that
 * starts a batch from references had no caller either. BULK-001 is that gap.
 *
 * **The server chooses the key. Always.** A client that named its own would be
 * naming a path, and a path a client controls is a path that eventually
 * contains `..`. What the client supplies is a *filename*, which matters only
 * because `ManualUploadReader::originalFilename()` is `basename()` of the key
 * and `SuggestTitle` reads it — so it is sanitised and kept, never trusted.
 *
 * **Each deposit gets its own directory.** Two people importing their own
 * `01 - Intro.wav` in the same minute must not collide, and a random segment
 * costs nothing next to the file it names. It also means a deposit is never
 * silently overwritten by a second one, which is the failure that would be
 * discovered only when a batch imported the wrong audio under the right name.
 *
 * Admission is asked of {@see UploadAdmission}, the same service the
 * single-file upload screen uses. A catalogue import accepts what an upload
 * accepts; two lists would drift, and the one that drifts is the one nobody
 * tests.
 */
final readonly class InboxDeposit
{
    public function __construct(private UploadAdmission $admission) {}

    /**
     * The key one declared file will be deposited at, or null if the name is
     * not usable as one.
     *
     * Null rather than an exception: an unusable filename is an ordinary thing
     * for a browser to hand over, and the caller turns it into a refusal code
     * beside the file it is about. Borrowing an `IngestionFailureCode` — every
     * one of which means something that happened to a file already inside the
     * pipeline — would put a word in the failure column that is not true.
     */
    public function keyFor(string $filename): ?string
    {
        $name = $this->safeName($filename);

        if ($name === null) {
            return null;
        }

        return $this->prefix().'/'.Str::uuid()->toString().'/'.$name;
    }

    /**
     * Whether this installation accepts a file of this type at all.
     *
     * Answered before any capability is minted, because a presigned PUT cannot
     * be taken back: a URL handed out for a file the platform will refuse is a
     * URL that costs somebody an upload before it costs them a refusal.
     */
    public function acceptsType(?string $mimeType): bool
    {
        return $this->admission->acceptsType(AssetKind::AudioMaster, $mimeType);
    }

    /**
     * @return list<string>
     */
    public function acceptedTypes(): array
    {
        return $this->admission->acceptedTypes(AssetKind::AudioMaster);
    }

    public function maximumBytes(): int
    {
        return $this->admission->maximumBytes(AssetKind::AudioMaster);
    }

    /**
     * The ceiling that will actually refuse a file, given how it travels.
     *
     * UPL-004. This screen promised the configured maximum — two gigabytes by
     * default — while a relayed deposit dies at whatever `post_max_size` is,
     * which on shared hosting is single-digit megabytes. A real 4.7 MB MP3
     * was refused in production with a message about neither number.
     */
    public function effectiveMaximumBytes(bool $direct): int
    {
        return $this->admission->effectiveMaximumBytes(AssetKind::AudioMaster, $direct);
    }

    public function limitedByPhp(bool $direct): bool
    {
        return $this->admission->limitedByPhp(AssetKind::AudioMaster, $direct);
    }

    public function phpPostLimitBytes(): int
    {
        return $this->admission->phpPostLimitBytes();
    }

    /**
     * The most files one deposit request may declare.
     *
     * Not the size of an import — that is `ingestion.max_batch`, and it bounds
     * something else: how many files one batch may carry. This bounds one
     * *request*, so a browser holding nine hundred files asks in several
     * rounds rather than in a payload nothing on a shared host will accept.
     */
    public function perRequestLimit(): int
    {
        return max(1, (int) config('ingestion.deposit_per_request', 50));
    }

    /**
     * How many files the browser uploads at once.
     *
     * Configurable because the right answer is a property of the host, not of
     * the application: an object store happily takes six, and a shared cPanel
     * account relaying through PHP will start refusing connections long before
     * that. Low by default — a slow import that finishes beats a fast one that
     * takes the site down.
     */
    public function concurrency(): int
    {
        return max(1, min(8, (int) config('ingestion.deposit_concurrency', 3)));
    }

    public function prefix(): string
    {
        return trim((string) config('ingestion.inbox_prefix', 'inbox'), '/');
    }

    /**
     * A filename reduced to something that cannot be a path.
     *
     * `OriginalFilename::sanitise()` is the platform's existing rule and does
     * the load-bearing work: it normalises Windows separators and then takes
     * `basename()`, so no separator survives it and `..` comes back as its
     * `unnamed` fallback rather than as an empty string.
     *
     * **Which makes the two guards below unreachable today, and they stay.**
     * BULK-001's mutation pass confirmed it — removing the separator strip
     * changes no test — and that is recorded rather than hidden. They are here
     * because this is the last statement before a storage *key* is built from
     * the result, while `sanitise()`'s job is to produce a safe *label*. The
     * two happen to coincide; a change to the label rule would not announce
     * that it had stopped satisfying the key rule. An unreachable guard is
     * what defence in depth looks like while the outer layer is working.
     */
    private function safeName(string $filename): ?string
    {
        $name = OriginalFilename::sanitise($filename);
        $name = str_replace(['/', '\\', "\0"], '', $name);
        $name = ltrim($name, '.');
        $name = trim($name);

        return $name === '' || $name === '.' || $name === '..' ? null : $name;
    }
}
