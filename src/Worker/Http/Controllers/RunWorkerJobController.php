<?php

declare(strict_types=1);

namespace SaniTube\Worker\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use SaniTube\Worker\Enums\WorkerCapability;
use SaniTube\Worker\WorkerJobFailed;
use SaniTube\Worker\WorkerRegistry;

/**
 * One route for every kind of work.
 *
 * `POST /api/worker/jobs/{capability}`. The controller knows nothing about
 * generation, FFmpeg or transcription, and that is the point: a capability
 * added later needs a handler and a registration, not a new endpoint, a new
 * middleware and a new client method.
 *
 * **The handler declares its own rules**, and the controller applies them. That
 * keeps the security boundary next to the thing it protects: for generation,
 * the absent fields are what stop a request choosing a checkpoint path and a
 * GPU, and they belong beside the code that would otherwise use them.
 *
 * Synchronous, because the work behind it is. The caller is a queued job on
 * Core, never a browser.
 */
final class RunWorkerJobController
{
    public function __invoke(Request $request, string $capability, WorkerRegistry $registry): JsonResponse
    {
        $resolved = WorkerCapability::fromSlug($capability);

        if (! $resolved instanceof WorkerCapability) {
            // A capability this build has never heard of. 404, because the
            // route genuinely is not there -- and it says nothing about which
            // capabilities do exist.
            return new JsonResponse(['code' => 'UNKNOWN_CAPABILITY'], 404);
        }

        $handler = $registry->handlerFor($resolved);

        if ($handler === null) {
            return new JsonResponse(['code' => 'CAPABILITY_NOT_REGISTERED'], 404);
        }

        if (! $handler->isRunnable()) {
            // Registered and not currently able. 503 rather than 404: the
            // difference is what tells Core to try later rather than never.
            return new JsonResponse(['code' => 'CAPABILITY_NOT_RUNNABLE'], 503);
        }

        $validator = Validator::make($request->all(), $handler->rules());

        if ($validator->fails()) {
            return new JsonResponse([
                'code' => 'INVALID_PAYLOAD',
                'errors' => $validator->errors()->toArray(),
            ], 422);
        }

        try {
            /** @var array<string, mixed> $payload */
            $payload = $validator->validated();

            return new JsonResponse($handler->handle($payload));
        } catch (WorkerJobFailed $failure) {
            // 422, not 500. The worker is working; this job is not going to
            // produce a result, and Core needs the reason rather than a stack.
            return new JsonResponse(['code' => $failure->reason], 422);
        }
    }
}
