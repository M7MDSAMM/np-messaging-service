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
        $deliveries = $this->deliveryService->createDeliveries($request->validated());

        return ApiResponse::created($deliveries, 'Deliveries accepted.');
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
