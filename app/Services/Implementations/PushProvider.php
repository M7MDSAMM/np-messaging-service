<?php

namespace App\Services\Implementations;

use App\Models\Delivery;
use App\Services\Contracts\PushProviderInterface;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class PushProvider implements PushProviderInterface
{
    public function send(Delivery $delivery): array
    {
        try {
            // Stub: simulate push notification delivery
            $messageId = 'push-'.(string) Str::uuid();

            Log::info('push.sent', [
                'delivery_uuid'       => $delivery->uuid,
                'recipient'           => $delivery->recipient,
                'provider_message_id' => $messageId,
            ]);

            return ['provider_message_id' => $messageId];
        } catch (\Throwable $e) {
            Log::error('push.send_failed', [
                'delivery_uuid' => $delivery->uuid,
                'error'         => $e->getMessage(),
            ]);

            throw new \RuntimeException('Push delivery failed: '.$e->getMessage(), 0, $e);
        }
    }
}
