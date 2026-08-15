<?php

declare(strict_types=1);

namespace SaniTube\Assets\Storage;

use php_user_filter;

/**
 * Stops a stream after a fixed number of bytes.
 *
 * The size limit has to be enforced *while* the upload is happening, not
 * after. Checking the object's size once it is written means a client can fill
 * storage first and be rejected second, and on a shared cPanel account with a
 * quota that is the whole attack.
 *
 * The cap is applied one byte above the real limit. That turns "was this too
 * big?" into an exact question about the stored object — an upload that
 * reaches `limit + 1` bytes was over the limit, and one that stops below it
 * was not — without keeping a running total anywhere the caller has to trust.
 *
 * Truncated content is never mistaken for accepted content: an object that
 * hits the cap is deleted from staging and never promoted.
 */
final class ByteCapFilter extends php_user_filter
{
    public const NAME = 'sanitube.byte-cap';

    private int $remaining = PHP_INT_MAX;

    /**
     * Caps a read stream so it yields at most `$limit + 1` bytes.
     *
     * @param  resource  $stream
     */
    public static function apply($stream, int $limit): void
    {
        stream_filter_append($stream, self::NAME, STREAM_FILTER_READ, $limit + 1);
    }

    public static function register(): void
    {
        if (! in_array(self::NAME, stream_get_filters(), true)) {
            stream_filter_register(self::NAME, self::class);
        }
    }

    public function onCreate(): bool
    {
        $this->remaining = is_int($this->params) && $this->params >= 0 ? $this->params : PHP_INT_MAX;

        return true;
    }

    /**
     * @param  resource  $in
     * @param  resource  $out
     * @param  int  $consumed
     * @param  bool  $closing
     *
     * @param-out int $consumed
     */
    public function filter($in, $out, &$consumed, $closing): int
    {
        $total = $consumed;

        while ($bucket = stream_bucket_make_writeable($in)) {
            $available = $bucket->datalen;

            // Saturating rather than wrapping: in PHP the sum of two integers
            // becomes a float on overflow, and a byte counter that silently
            // stops being an integer is worse than one that stops counting.
            $sum = $total + $available;
            $total = is_int($sum) ? $sum : PHP_INT_MAX;

            if ($this->remaining <= 0) {
                // Drained and dropped. Leaving it unconsumed would spin.
                continue;
            }

            if ($available > $this->remaining) {
                $bucket->data = substr($bucket->data, 0, $this->remaining);
                $bucket->datalen = strlen($bucket->data);
            }

            $this->remaining -= $bucket->datalen;

            stream_bucket_append($out, $bucket);
        }

        $consumed = $total;

        return PSFS_PASS_ON;
    }
}
