<?php

declare(strict_types=1);

namespace SaniTube\Api\Http\Controllers\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use RuntimeException;
use SaniTube\Api\Http\Resources\V1\DistributionDeliveryResource;
use SaniTube\Distribution\DistributorManager;
use SaniTube\Distribution\Exceptions\DistributionException;
use SaniTube\Distribution\Models\DistributionDelivery;
use SaniTube\Distribution\Services\SubmitDelivery;
use SaniTube\Distribution\Services\ValidateDelivery;
use SaniTube\Releases\Models\Release;
use Symfony\Component\HttpFoundation\Response;

/**
 * Distribution's write surface.
 *
 * `validate` and `submit` are separate for the same reason REL-001 separates
 * validate from ready, only more so: submission is the one irreversible act in
 * the platform. A label must be able to see a distributor's verdict without
 * anything being handed over.
 */
final class DistributionController
{
    public function index(): AnonymousResourceCollection
    {
        return DistributionDeliveryResource::collection(
            DistributionDelivery::query()
                ->with('release')
                ->withCount('attempts')
                ->orderBy('id')
                ->cursorPaginate()
                ->withQueryString(),
        );
    }

    public function show(DistributionDelivery $delivery): DistributionDeliveryResource
    {
        return new DistributionDeliveryResource($delivery->loadCount('attempts')->load('release'));
    }

    public function validateDelivery(
        Release $release,
        string $provider,
        DistributorManager $distributors,
        ValidateDelivery $validator,
    ): JsonResponse {
        try {
            $distributor = $distributors->distributor($provider);
        } catch (RuntimeException $exception) {
            return $this->refused($exception->getMessage(), 'UNKNOWN_DISTRIBUTOR');
        }

        // Read-only, and it must stay that way: a validation that quietly
        // created a draft upstream would leave abandoned records behind on
        // every attempt.
        return response()->json(['data' => $validator->handle($release, $distributor)->toArray()]);
    }

    public function submit(
        Release $release,
        string $provider,
        SubmitDelivery $submitter,
    ): JsonResponse|DistributionDeliveryResource {
        try {
            $delivery = $submitter->handle($release, $provider);
        } catch (DistributionException $exception) {
            return $this->refused($exception->getMessage(), $exception->reason);
        } catch (RuntimeException $exception) {
            return $this->refused($exception->getMessage(), 'UNKNOWN_DISTRIBUTOR');
        }

        return new DistributionDeliveryResource($delivery->load('release'));
    }

    private function refused(string $message, string $code): JsonResponse
    {
        return response()->json(
            ['message' => $message, 'code' => $code],
            Response::HTTP_UNPROCESSABLE_ENTITY,
        );
    }
}
