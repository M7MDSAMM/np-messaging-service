<?php

namespace App\Services\Contracts;

use App\Models\Delivery;

interface DeliveryServiceInterface
{
    public function createDeliveries(array $payload): array;

    public function getDeliveryByUuid(string $uuid): Delivery;

    public function retryDelivery(string $uuid): array;
}
