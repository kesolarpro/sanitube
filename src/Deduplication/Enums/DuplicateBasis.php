<?php

declare(strict_types=1);

namespace SaniTube\Deduplication\Enums;

/**
 * What the finding was based on.
 *
 * Stored beside the level because "these two are the same recording" is not
 * reviewable on its own — the reviewer needs to know whether that came from
 * identical bytes or from an acoustic measurement over part of the audio, and
 * those two warrant different amounts of trust.
 *
 * It is also what makes the record idempotent in a useful way: one pair of
 * assets may be found by more than one route, and a checksum finding must not
 * overwrite an acoustic one or be overwritten by it.
 */
enum DuplicateBasis: string
{
    case Checksum = 'CHECKSUM';
    case Fingerprint = 'FINGERPRINT';
}
