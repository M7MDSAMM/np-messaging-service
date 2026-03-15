<?php

namespace App\Jobs;

use App\Models\Delivery;
use App\Models\DeliveryAttempt;
use App\Services\Contracts\DeliveryProviderInterface;
use App\Services\Contracts\EmailProviderInterface;
use App\Services\Contracts\PushProviderInterface;
use App\Services\Contracts\WhatsappProviderInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class DispatchDeliveryJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(
        public readonly string $deliveryUuid,
    ) {}

    public function backoff(): array
    {
        return [0, 10, 30];
    }

    public function handle(): void
    {
        $delivery = Delivery::where('uuid', $this->deliveryUuid)->first();

        if (! $delivery) {
            Log::warning('delivery.not_found', ['delivery_uuid' => $this->deliveryUuid]);

            return;
        }

        $delivery->update(['status' => 'processing']);

        Log::info('delivery.processing', [
            'delivery_uuid'     => $delivery->uuid,
            'notification_uuid' => $delivery->notification_uuid,
            'channel'           => $delivery->channel,
            'attempt'           => $delivery->attempts_count + 1,
        ]);

        $provider = $this->resolveProvider($delivery->channel);
        $providerName = class_basename($provider);

        try {
            $result = $provider->send($delivery);

            $delivery->update([
                'status'         => 'sent',
                'provider'       => $providerName,
                'attempts_count' => $delivery->attempts_count + 1,
                'sent_at'        => now(),
            ]);

            DeliveryAttempt::create([
                'delivery_uuid'      => $delivery->uuid,
                'attempt_number'     => $delivery->attempts_count,
                'provider'           => $providerName,
                'status'             => 'sent',
                'provider_message_id' => $result['provider_message_id'] ?? null,
            ]);

            Log::info('delivery.sent', [
                'delivery_uuid'     => $delivery->uuid,
                'notification_uuid' => $delivery->notification_uuid,
                'channel'           => $delivery->channel,
                'provider'          => $providerName,
            ]);
        } catch (\Throwable $e) {
            $attemptNumber = $delivery->attempts_count + 1;

            $delivery->update([
                'attempts_count' => $attemptNumber,
                'provider'       => $providerName,
                'last_error'     => $e->getMessage(),
            ]);

            DeliveryAttempt::create([
                'delivery_uuid'  => $delivery->uuid,
                'attempt_number' => $attemptNumber,
                'provider'       => $providerName,
                'status'         => 'failed',
                'error_message'  => $e->getMessage(),
            ]);

            if ($attemptNumber < $delivery->max_attempts) {
                $delivery->update(['status' => 'pending']);

                $backoff = $this->backoff();
                $delay = $backoff[$attemptNumber] ?? 30;

                $this->release($delay);

                Log::warning('delivery.retry_scheduled', [
                    'delivery_uuid'     => $delivery->uuid,
                    'notification_uuid' => $delivery->notification_uuid,
                    'channel'           => $delivery->channel,
                    'attempt'           => $attemptNumber,
                    'next_delay'        => $delay,
                ]);
            } else {
                $delivery->update(['status' => 'failed']);

                Log::error('delivery.failed', [
                    'delivery_uuid'     => $delivery->uuid,
                    'notification_uuid' => $delivery->notification_uuid,
                    'channel'           => $delivery->channel,
                    'provider'          => $providerName,
                    'error'             => $e->getMessage(),
                ]);
            }
        }
    }

    private function resolveProvider(string $channel): DeliveryProviderInterface
    {
        return match ($channel) {
            'email'    => app(EmailProviderInterface::class),
            'whatsapp' => app(WhatsappProviderInterface::class),
            'push'     => app(PushProviderInterface::class),
            default    => throw new \RuntimeException("Unsupported channel: {$channel}"),
        };
    }
}
