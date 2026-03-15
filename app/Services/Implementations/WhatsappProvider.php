<?php

namespace App\Services\Implementations;

use App\Models\Delivery;
use App\Services\Contracts\WhatsappProviderInterface;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class WhatsappProvider implements WhatsappProviderInterface
{
    public function send(Delivery $delivery): array
    {
        try {
            // Stub: simulate WhatsApp delivery
            $messageId = 'wa-'.(string) Str::uuid();

            Log::info('whatsapp.sent', [
                'delivery_uuid'       => $delivery->uuid,
                'recipient'           => $delivery->recipient,
                'provider_message_id' => $messageId,
            ]);

            return ['provider_message_id' => $messageId];
        } catch (\Throwable $e) {
            Log::error('whatsapp.send_failed', [
                'delivery_uuid' => $delivery->uuid,
                'error'         => $e->getMessage(),
            ]);

            throw new \RuntimeException('WhatsApp delivery failed: '.$e->getMessage(), 0, $e);
        }
    }
}
