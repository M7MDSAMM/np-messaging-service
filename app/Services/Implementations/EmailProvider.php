<?php

namespace App\Services\Implementations;

use App\Models\Delivery;
use App\Services\Contracts\EmailProviderInterface;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class EmailProvider implements EmailProviderInterface
{
    public function send(Delivery $delivery): array
    {
        try {
            Mail::raw($delivery->content ?? '', function ($message) use ($delivery) {
                $message->to($delivery->recipient)
                    ->subject($delivery->subject ?? 'Notification');
            });

            $messageId = (string) Str::uuid();

            Log::info('email.sent', [
                'delivery_uuid'      => $delivery->uuid,
                'recipient'          => $delivery->recipient,
                'provider_message_id' => $messageId,
            ]);

            return ['provider_message_id' => $messageId];
        } catch (\Throwable $e) {
            Log::error('email.send_failed', [
                'delivery_uuid' => $delivery->uuid,
                'error'         => $e->getMessage(),
            ]);

            throw new \RuntimeException('Email delivery failed: '.$e->getMessage(), 0, $e);
        }
    }
}
