<?php

declare(strict_types=1);

namespace SaniTube\Transcription\Enums;

use SaniTube\Operations\Services\BackgroundWork;
use SaniTube\Operations\Services\BacklogGuard;

/**
 * Why this asset is not going to be transcribed.
 *
 * A closed list, because "we did not transcribe it" is a thing an operator will
 * eventually need counted, and a reason somebody typed is a reason nobody can
 * count. The English lives in the interface; these codes are what the platform
 * reasons with.
 *
 * **Every one of these is an ordinary state, not a failure.** A library where
 * most assets are refused for one of these reasons is a library working
 * correctly — most installations configure no supplier at all, and most of the
 * rest will not want to pay to transcribe every artwork file and every stem.
 *
 * Deliberately *about the asset*. Whether this installation should be doing
 * background work at all is {@see BackgroundWork}'s question, and whether there
 * is room for it is {@see BacklogGuard}'s; both are answered before this one is
 * asked, and keeping the three apart is what lets an operator see which gate
 * stopped them.
 */
enum TranscriptionRefusal: string
{
    /**
     * No supplier is configured, or the configured one has no usable
     * credentials. The default state of a fresh install.
     */
    case ProviderUnavailable = 'TRANSCRIPTION_PROVIDER_UNAVAILABLE';

    /**
     * The bytes are not settled yet. Transcribing a pending asset races the
     * write that is still happening, and a supplier reading a half-written
     * object would return a confident transcript of a truncated file.
     */
    case AssetNotSettled = 'TRANSCRIPTION_ASSET_NOT_SETTLED';

    /**
     * Somebody set this aside. Paying to transcribe a file a person has
     * already rejected is the clearest possible waste.
     */
    case AssetTrashed = 'TRANSCRIPTION_ASSET_TRASHED';

    /**
     * Not something with speech in it. An artwork file, a document, a
     * distribution export.
     */
    case NotAudio = 'TRANSCRIPTION_NOT_AUDIO';

    /**
     * Already transcribed by this supplier at this version. The stored answer
     * stands; asking again is a second bill for the same sentence.
     */
    case AlreadyHeld = 'TRANSCRIPTION_ALREADY_HELD';

    /**
     * A person has confirmed this asset holds the same recording as one the
     * platform already has.
     *
     * The confirmation is the whole basis. A *proposed* duplicate is a
     * question nobody has answered, and skipping on one would let an
     * unreviewed similarity score quietly decide that a recording is never
     * transcribed.
     */
    case ConfirmedDuplicate = 'TRANSCRIPTION_CONFIRMED_DUPLICATE';
}
