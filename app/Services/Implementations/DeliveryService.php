<?php

namespace App\Services\Implementations;

use App\Jobs\DispatchDeliveryJob;
use App\Models\Delivery;
use App\Services\Contracts\DeliveryServiceInterface;
use Illuminate\Support\Facades\Log;

class DeliveryService implements DeliveryServiceInterface
{
    public function createDeliveries(array $payload): array
    {
        $notificationUuid = $payload['notification_uuid'];
        $userUuid = $payload['user_uuid'];
        $correlationId = request()->header('X-Correlation-Id', '');
        $adminUuid = request()->attributes->get('auth_admin_uuid');

        $deliveries = [];

        foreach ($payload['deliveries'] as $item) {
            $delivery = Delivery::create([
                'notification_uuid' => $notificationUuid,
                'user_uuid'         => $userUuid,
                'channel'           => $item['channel'],
                'recipient'         => $item['recipient'] ?? null,
                'subject'           => $item['subject'] ?? null,
                'content'           => $item['content'] ?? null,
                'payload'           => $item['payload'] ?? null,
                'status'            => 'pending',
            ]);

            Log::info('delivery.created', [
                'delivery_uuid'      => $delivery->uuid,
                'notification_uuid'  => $notificationUuid,
                'channel'            => $delivery->channel,
                'correlation_id'     => $correlationId,
                'acting_admin_uuid'  => $adminUuid,
            ]);

            DispatchDeliveryJob::dispatch($delivery->uuid);

            $deliveries[] = $delivery;
        }

        return $deliveries;
    }

    public function getDeliveryByUuid(string $uuid): Delivery
    {
        $delivery = Delivery::where('uuid', $uuid)->firstOrFail();

        Log::info('delivery.viewed', [
            'delivery_uuid'     => $delivery->uuid,
            'correlation_id'    => request()->header('X-Correlation-Id', ''),
            'acting_admin_uuid' => request()->attributes->get('auth_admin_uuid'),
        ]);

        return $delivery->load('attempts');
    }

    public function retryDelivery(string $uuid): array
    {
        $delivery = Delivery::where('uuid', $uuid)->firstOrFail();

        $delivery->update([
            'status'         => 'pending',
            'attempts_count' => 0,
            'last_error'     => null,
        ]);

        DispatchDeliveryJob::dispatch($delivery->uuid);

        Log::info('delivery.retry_requested', [
            'delivery_uuid'     => $delivery->uuid,
            'notification_uuid' => $delivery->notification_uuid,
            'channel'           => $delivery->channel,
            'correlation_id'    => request()->header('X-Correlation-Id', ''),
            'acting_admin_uuid' => request()->attributes->get('auth_admin_uuid'),
        ]);

        return [
            'delivery_uuid' => $delivery->uuid,
            'status'        => 'retry_accepted',
        ];
    }
}
