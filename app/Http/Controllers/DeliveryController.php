<?php

namespace App\Http\Controllers;

use App\Http\Requests\DeliveryCreateRequest;
use App\Http\Responses\ApiResponse;
use App\Services\Contracts\DeliveryServiceInterface;
use Illuminate\Http\JsonResponse;

class DeliveryController extends Controller
{
    public function __construct(
        private readonly DeliveryServiceInterface $deliveryService,
    ) {}

    public function store(DeliveryCreateRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $deliveries = $this->deliveryService->createDeliveries($validated);

        $responseData = [
            'notification_uuid' => $validated['notification_uuid'],
            'deliveries'        => collect($deliveries)->map(fn ($d) => [
                'uuid'    => $d->uuid,
                'channel' => $d->channel,
                'status'  => $d->status,
            ])->values()->all(),
        ];

        return ApiResponse::created($responseData, 'Deliveries accepted.');
    }

    public function show(string $uuid): JsonResponse
    {
        $delivery = $this->deliveryService->getDeliveryByUuid($uuid);

        return ApiResponse::success($delivery, 'Delivery retrieved.');
    }

    public function retry(string $uuid): JsonResponse
    {
        $result = $this->deliveryService->retryDelivery($uuid);

        return ApiResponse::success($result, 'Delivery retry accepted.');
    }
}
