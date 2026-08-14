<?php

declare(strict_types=1);

namespace SaniTube\Foundation\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Gives an aggregate a public identity that is distinct from its storage key.
 *
 * The primary key stays an auto-incrementing BIGINT: it keeps indexes narrow
 * and joins cheap, which matters for a catalogue that will hold hundreds of
 * thousands of rows on shared hosting. The public identity is a separate UUID
 * v7 column — time-ordered, so it clusters well when it is used as a lookup
 * key, and opaque, so nothing outside the application can infer catalogue size
 * or ordering from it.
 *
 * Route binding resolves on the UUID, which is what makes it impossible for an
 * internal id to become part of a URL by accident.
 */
trait HasPublicUuid
{
    public static function bootHasPublicUuid(): void
    {
        static::creating(function (Model $model): void {
            // Attribute accessors rather than magic properties: the closure is
            // typed against Model, which has no `uuid` of its own.
            if (blank($model->getAttribute('uuid'))) {
                $model->setAttribute('uuid', (string) Str::uuid7());
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }
}
