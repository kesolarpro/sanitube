<?php

declare(strict_types=1);

namespace SaniTube\Transcription\Services;

use SaniTube\Assets\Enums\AssetKind;
use SaniTube\Assets\Enums\AssetStatus;
use SaniTube\Assets\Models\Asset;
use SaniTube\Deduplication\Enums\DuplicateDecision;
use SaniTube\Deduplication\Models\DuplicateRelation;
use SaniTube\Transcription\Enums\TranscriptionRefusal;
use SaniTube\Transcription\Models\Transcript;
use SaniTube\Transcription\TranscriptionManager;

/**
 * Whether this particular asset should be sent to a supplier.
 *
 * **Asked twice, on purpose.** Once when the work is requested, so a refusal
 * reaches the person who asked and no job is queued for something that was
 * never going to happen; and again inside the job, because a queue is a delay
 * and an asset can be trashed, transcribed or confirmed as a duplicate in
 * between. The second check is the one that actually protects the bill.
 *
 * **It answers about the asset and nothing else.** Whether the installation is
 * paused, and whether the queue has room, are separate questions with separate
 * gates; folding them in here would leave an operator unable to tell which of
 * the three stopped them.
 *
 * A refusal is never an error. Most assets in most libraries are legitimately
 * not worth transcribing, and a screen that treated "artwork has no speech in
 * it" as a fault would report a working library as broken.
 */
final readonly class TranscriptionEligibility
{
    /**
     * Kinds that can plausibly contain speech.
     *
     * Video is absent deliberately rather than by oversight: the transcription
     * path spools an object down and hands the supplier a file, and a video
     * master is an order of magnitude larger than an audio one. Sending video
     * is a decision with its own cost, and it belongs to whichever ticket
     * actually wants it.
     */
    private const AUDIBLE = [
        AssetKind::AudioMaster,
        AssetKind::AudioDerivative,
        AssetKind::Preview,
        AssetKind::Stem,
    ];

    public function __construct(private TranscriptionManager $providers) {}

    /**
     * @return TranscriptionRefusal|null null when the asset may be transcribed
     */
    public function refusalFor(Asset $asset): ?TranscriptionRefusal
    {
        $provider = $this->providers->default();

        if (! $provider->isAvailable()) {
            return TranscriptionRefusal::ProviderUnavailable;
        }

        if ($asset->isTrashed()) {
            return TranscriptionRefusal::AssetTrashed;
        }

        if (! in_array($asset->status, [AssetStatus::Stored, AssetStatus::Verified], true)) {
            return TranscriptionRefusal::AssetNotSettled;
        }

        if (! in_array($asset->kind, self::AUDIBLE, true)) {
            return TranscriptionRefusal::NotAudio;
        }

        if ($this->isConfirmedDuplicate($asset)) {
            return TranscriptionRefusal::ConfirmedDuplicate;
        }

        // Keyed by provider *and* version, the same key the transcription
        // service stores under. A version bump makes a genuinely different
        // transcript a different row, so this stops being satisfied and the
        // asset becomes eligible again -- which is the point of versioning it.
        $held = Transcript::query()
            ->where('asset_id', $asset->id)
            ->where('provider', $provider->name())
            ->where('provider_version', $provider->version())
            ->exists();

        return $held ? TranscriptionRefusal::AlreadyHeld : null;
    }

    public function allows(Asset $asset): bool
    {
        return $this->refusalFor($asset) === null;
    }

    /**
     * Whether a person has confirmed this asset is a copy of something already
     * held.
     *
     * **Only `CONFIRMED` counts.** A proposed finding is a question nobody has
     * answered, and refusing on one would let an unreviewed similarity score
     * quietly decide that a recording is never transcribed.
     *
     * **Only one side of the pair is refused**, and that is the whole reason
     * this asks about `asset_id` rather than either column. A finding is
     * written from the side the evaluation ran from; refusing both members
     * would leave a confirmed duplicate pair with no transcript at all, which
     * is worse than paying once. Which of the two survives is not important —
     * that exactly one does, is.
     */
    private function isConfirmedDuplicate(Asset $asset): bool
    {
        return DuplicateRelation::query()
            ->where('asset_id', $asset->id)
            ->where('decision', DuplicateDecision::Confirmed->value)
            ->exists();
    }
}
