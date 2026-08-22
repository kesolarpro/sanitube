<?php

declare(strict_types=1);

namespace SaniTube\Ui\Http\Requests\Concerns;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;
use SaniTube\Assets\Services\DiscardedUpload;

/**
 * Say "the host refused this size" instead of "the file field is required".
 *
 * UPL-004. A body larger than `post_max_size` leaves PHP with empty
 * superglobals, so an upload request that carried a real file arrives looking
 * identical to one that carried nothing — and stock validation answers *the
 * file field is required*, which is true, unhelpful, and what a person
 * uploading a 4.7 MB MP3 in production was actually told.
 *
 * Applied where the misleading message is produced rather than in the
 * controller, because by the time a controller runs, validation has already
 * failed. Both relayed upload paths use it: one rule, no drift.
 */
trait RefusesDiscardedUploads
{
    protected function failedValidation(Validator $validator): void
    {
        $discarded = $this->container->make(DiscardedUpload::class);

        if ($discarded->happened($this)) {
            throw new HttpResponseException(response()->json([
                'code' => 'HOST_UPLOAD_LIMIT',
                // The host's own setting, so the message can name a number
                // somebody can act on. Not a path, not a credential.
                'limit_bytes' => $discarded->hostLimitBytes(),
                // 413 rather than 422: the request was refused for its size,
                // and a proxy or a log reading only the status should see that
                // rather than "unprocessable".
            ], 413));
        }

        parent::failedValidation($validator);
    }
}
