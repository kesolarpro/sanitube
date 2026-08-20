<?php

declare(strict_types=1);

namespace SaniTube\Artwork\Services;

use SaniTube\Artwork\Contracts\ImageProvider;
use SaniTube\Artwork\Exceptions\ArtworkGenerationException;
use SaniTube\Artwork\ImageRequest;
use SaniTube\Assets\Enums\AssetKind;
use SaniTube\Assets\Enums\AssetStatus;
use SaniTube\Assets\Models\Asset;
use SaniTube\Assets\Services\AssetStorageService;
use SaniTube\Assets\Services\AssetVerificationService;
use Throwable;

/**
 * Generates one cover and stores it as an ordinary artwork asset.
 *
 * **The order is the design.** Feasibility is checked, then the provider is
 * called, then the bytes are stored — and the first step exists so the second
 * never happens pointlessly. Generating first and validating afterwards would
 * mean paying for every cover the platform's own validator then rejects, which
 * given the current gap between image-model sizes and this platform's default
 * 3000px requirement would be *every* cover.
 *
 * **What comes back becomes an asset, not a special case.** It is registered as
 * `ARTWORK`, stored through the same service every upload uses, checksummed and
 * verified like any other object — which means ART-001's listener measures it
 * and the release validator judges it on that measurement. A generated cover
 * gets no more trust than an uploaded one, and the provider's claim about what
 * it produced is never what the platform reports.
 *
 * The image is written to a temporary file and stored from there rather than
 * held twice in memory, and the temporary file is removed in a `finally` —
 * including on failure, which is the case that fills a shared host's disk.
 */
final readonly class GenerateArtwork
{
    public function __construct(
        private ImageProvider $provider,
        private ArtworkFeasibility $feasibility,
        private AssetStorageService $storage,
        private AssetVerificationService $verification,
    ) {}

    /**
     * @throws ArtworkGenerationException
     */
    public function handle(string $prompt, ?string $quality = null): Asset
    {
        return $this->produce($prompt, $quality)['asset'];
    }

    /**
     * The asset **and** what the provider said about it.
     *
     * ART-004 needs the model name for provenance, and the asset alone cannot
     * carry it: an asset is bytes this platform verified, and which model made
     * them is a fact about the request rather than about the file. `handle()`
     * stays for callers that only want the cover.
     *
     * @return array{asset: Asset, model: string|null}
     *
     * @throws ArtworkGenerationException
     */
    public function produce(string $prompt, ?string $quality = null): array
    {
        if (trim($prompt) === '') {
            throw ArtworkGenerationException::emptyPrompt();
        }

        if (! $this->provider->isAvailable()) {
            throw ArtworkGenerationException::noProvider();
        }

        $size = $this->feasibility->bestUsableSize();

        if ($size === null) {
            // Before the request, not after it.
            throw ArtworkGenerationException::requirementsUnreachable($this->feasibility->explain());
        }

        $image = $this->provider->generate(new ImageRequest(
            prompt: $prompt,
            size: $size,
            quality: $quality,
        ));

        if ($image === null) {
            throw ArtworkGenerationException::providerFailed($this->provider->name());
        }

        $path = tempnam(sys_get_temp_dir(), 'sanitube-gen-');

        if ($path === false || file_put_contents($path, $image->bytes) === false) {
            if (is_string($path)) {
                @unlink($path);
            }

            throw ArtworkGenerationException::notStored();
        }

        try {
            $asset = $this->storage->register(
                kind: AssetKind::Artwork,
                originalFilename: $this->filename($image->mimeType),
            );

            $stored = $this->storage->storeFile($asset, $path);

            // Verified here, exactly as ProcessIngestionItem and
            // SelectMusicGenerationResult do after storing bytes. Without it
            // the cover sits at STORED for ever: ART-001's listener fires on
            // AssetVerified, so an unverified cover is never measured and the
            // release validator reports it as unmeasured for the rest of its
            // life.
            $verified = $this->verification->verify($stored->refresh());

            if ($verified->status !== AssetStatus::Verified) {
                // The bytes did not come back as they went in. A cover nobody
                // can confirm is worse than no cover, so this fails rather
                // than handing back an asset the validator will reject.
                throw ArtworkGenerationException::notStored();
            }

            return ['asset' => $verified, 'model' => $image->model];
        } catch (ArtworkGenerationException $exception) {
            throw $exception;
        } catch (Throwable) {
            // The provider's exception text is not carried for the reason
            // ProviderFailure gives about generation: a storage or client
            // message quotes the request, and the prompt is catalogue data.
            throw ArtworkGenerationException::notStored();
        } finally {
            @unlink($path);
        }
    }

    private function filename(string $mimeType): string
    {
        $extension = match (strtolower($mimeType)) {
            'image/jpeg' => 'jpg',
            'image/webp' => 'webp',
            default => 'png',
        };

        // No timestamp and no random suffix: the object key is derived from the
        // asset's uuid, so the readable part only has to be honest about what
        // the file is.
        return 'generated-cover.'.$extension;
    }
}
