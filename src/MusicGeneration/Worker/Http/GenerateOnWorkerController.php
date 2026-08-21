<?php

declare(strict_types=1);

namespace SaniTube\MusicGeneration\Worker\Http;

use Illuminate\Http\JsonResponse;
use SaniTube\Localization\ContentLanguage;
use SaniTube\MusicGeneration\GenerationRequest;
use SaniTube\MusicGeneration\Worker\RunGenerationOnWorker;
use SaniTube\MusicGeneration\Worker\WorkerGenerationFailed;

/**
 * The generation worker's one endpoint.
 *
 * Runs on the host that has the GPU and a local ACE-Step. Core calls it; it
 * calls the engine; it answers with a **storage key** and never with a
 * filesystem path.
 *
 * One route and one verb, because a worker with a surface is a worker with a
 * surface to attack. It takes what a generation means — a prompt, optional
 * lyrics, whether it is instrumental — and nothing that could reach a
 * checkpoint, a device, a path or a process.
 *
 * Synchronous, deliberately: ACE-Step is. The caller is a queued job on Core,
 * never a browser, and the timeouts on both sides are configuration.
 */
final class GenerateOnWorkerController
{
    public function __invoke(GenerateOnWorkerRequest $request, RunGenerationOnWorker $worker): JsonResponse
    {
        $language = $request->languageCode();

        try {
            $staged = $worker->handle(
                new GenerationRequest(
                    prompt: $request->prompt(),
                    stylePrompt: $request->stylePrompt(),
                    lyrics: $request->lyrics(),
                    instrumental: $request->instrumental(),
                    language: $language === null ? null : ContentLanguage::fromCode($language),
                ),
                $request->reference(),
            );
        } catch (WorkerGenerationFailed $exception) {
            // 422, not 500. The worker is working; this request is not going to
            // produce audio, and Core needs the reason rather than a stack.
            return new JsonResponse(['code' => $exception->reason], 422);
        }

        return new JsonResponse([
            // A storage key, which is a thing Core already knows how to ingest
            // from any host. Never `output_path`.
            'storage_key' => $staged['key'],
            'bytes' => $staged['bytes'],
            'checksum' => $staged['checksum'],
        ]);
    }
}
