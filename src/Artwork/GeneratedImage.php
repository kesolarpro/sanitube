<?php

declare(strict_types=1);

namespace SaniTube\Artwork;

/**
 * The bytes a provider produced, and what it says they are.
 *
 * **Bytes, never a URL.** Some models return a link valid for an hour, which is
 * a bearer credential with an expiry and is exactly the sort of thing that ends
 * up in a database column and then on a screen. The adapter resolves it to bytes
 * inside itself or fails; nothing downstream ever holds a provider address.
 *
 * `mimeType` is what the provider *claims*. It is not trusted: the stored asset
 * is measured by ART-001 like any other, and what that measurement says is what
 * the release validator judges.
 */
final readonly class GeneratedImage
{
    public function __construct(
        public string $bytes,
        public string $mimeType,
        public ?string $model = null,

        /**
         * Some models rewrite the prompt before generating and return what they
         * actually used. Worth keeping: "why does this cover not match the
         * brief" is otherwise unanswerable.
         */
        public ?string $revisedPrompt = null,
    ) {}
}
