<?php

namespace App\Services\Contracts;

use App\Models\Delivery;

interface DeliveryProviderInterface
{
    /**
     * Send a delivery through the provider.
     *
     * @return array{provider_message_id: string|null}
     *
     * @throws \RuntimeException on failure
     */
    public function send(Delivery $delivery): array;
}
