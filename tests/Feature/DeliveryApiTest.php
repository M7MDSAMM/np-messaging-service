<?php

namespace Tests\Feature;

use App\Jobs\DispatchDeliveryJob;
use App\Models\Delivery;
use App\Services\Contracts\EmailProviderInterface;
use App\Services\Contracts\PushProviderInterface;
use App\Services\Contracts\WhatsappProviderInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Support\AssertsApiEnvelope;
use Tests\Support\JwtHelper;
use Tests\TestCase;

class DeliveryApiTest extends TestCase
{
    use RefreshDatabase, JwtHelper, AssertsApiEnvelope;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpJwt();
    }

    // ── Health endpoint ──────────────────────────────────────────────────

    public function test_health_returns_standardized_envelope(): void
    {
        $response = $this->getJson('/api/v1/health');

        $this->assertApiSuccess($response);
        $response->assertHeader('X-Correlation-Id')
            ->assertJsonPath('data.service', 'messaging-service')
            ->assertJsonPath('data.status', 'healthy');
    }

    // ── Auth required ────────────────────────────────────────────────────

    public function test_unauthorized_access_returns_401_envelope(): void
    {
        $response = $this->postJson('/api/v1/deliveries', []);

        $this->assertApiError($response, 401, 'AUTH_INVALID');
    }

    public function test_malformed_token_returns_401(): void
    {
        $response = $this->withToken('not-a-valid-jwt')
            ->postJson('/api/v1/deliveries', []);

        $this->assertApiError($response, 401, 'AUTH_INVALID');
    }

    // ── Create deliveries ────────────────────────────────────────────────

    public function test_authenticated_admin_can_create_deliveries(): void
    {
        Queue::fake();

        $payload = $this->makeDeliveryPayload();

        $response = $this->withToken($this->makeToken())
            ->postJson('/api/v1/deliveries', $payload);

        $this->assertApiSuccess($response, 201);
        $response->assertJsonPath('data.notification_uuid', $payload['notification_uuid'])
            ->assertJsonStructure(['data' => ['notification_uuid', 'deliveries']]);

        $this->assertDatabaseHas('deliveries', [
            'notification_uuid' => $payload['notification_uuid'],
            'channel'           => 'email',
            'status'            => 'pending',
        ]);
    }

    public function test_creating_deliveries_dispatches_queue_jobs(): void
    {
        Queue::fake();

        $payload = $this->makeDeliveryPayload(channels: ['email', 'push']);

        $this->withToken($this->makeToken())
            ->postJson('/api/v1/deliveries', $payload);

        Queue::assertPushed(DispatchDeliveryJob::class, 2);
    }

    // ── Job execution ────────────────────────────────────────────────────

    public function test_successful_job_marks_delivery_as_sent(): void
    {
        $this->mockProviders();

        $delivery = Delivery::create([
            'notification_uuid' => (string) Str::uuid(),
            'user_uuid'         => (string) Str::uuid(),
            'channel'           => 'email',
            'recipient'         => 'user@example.com',
            'subject'           => 'Test',
            'content'           => 'Hello',
            'status'            => 'pending',
        ]);

        $job = new DispatchDeliveryJob($delivery->uuid);
        $job->handle();

        $delivery->refresh();

        $this->assertSame('sent', $delivery->status);
        $this->assertNotNull($delivery->sent_at);
        $this->assertSame(1, $delivery->attempts_count);

        $this->assertDatabaseHas('delivery_attempts', [
            'delivery_uuid'  => $delivery->uuid,
            'attempt_number' => 1,
            'status'         => 'sent',
        ]);
    }

    public function test_failed_job_retries_and_eventually_marks_failed(): void
    {
        $emailMock = $this->mock(EmailProviderInterface::class);
        $emailMock->shouldReceive('send')
            ->andThrow(new \RuntimeException('SMTP connection refused'));

        $delivery = Delivery::create([
            'notification_uuid' => (string) Str::uuid(),
            'user_uuid'         => (string) Str::uuid(),
            'channel'           => 'email',
            'recipient'         => 'user@example.com',
            'subject'           => 'Test',
            'content'           => 'Hello',
            'status'            => 'pending',
            'attempts_count'    => 2,
            'max_attempts'      => 3,
        ]);

        $job = new DispatchDeliveryJob($delivery->uuid);
        $job->handle();

        $delivery->refresh();

        $this->assertSame('failed', $delivery->status);
        $this->assertSame(3, $delivery->attempts_count);
        $this->assertSame('SMTP connection refused', $delivery->last_error);

        $this->assertDatabaseHas('delivery_attempts', [
            'delivery_uuid' => $delivery->uuid,
            'status'        => 'failed',
            'error_message' => 'SMTP connection refused',
        ]);
    }

    public function test_push_delivery_uses_push_provider(): void
    {
        $pushMock = $this->mock(PushProviderInterface::class);
        $pushMock->shouldReceive('send')
            ->once()
            ->andReturn(['provider_message_id' => 'push-msg-001']);

        $this->mock(EmailProviderInterface::class);
        $this->mock(WhatsappProviderInterface::class);

        $delivery = Delivery::create([
            'notification_uuid' => (string) Str::uuid(),
            'user_uuid'         => (string) Str::uuid(),
            'channel'           => 'push',
            'recipient'         => 'device-token-123',
            'content'           => 'Push notification',
            'status'            => 'pending',
        ]);

        $job = new DispatchDeliveryJob($delivery->uuid);
        $job->handle();

        $delivery->refresh();
        $this->assertSame('sent', $delivery->status);
    }

    public function test_whatsapp_delivery_uses_whatsapp_provider(): void
    {
        $whatsappMock = $this->mock(WhatsappProviderInterface::class);
        $whatsappMock->shouldReceive('send')
            ->once()
            ->andReturn(['provider_message_id' => 'wa-msg-001']);

        $this->mock(EmailProviderInterface::class);
        $this->mock(PushProviderInterface::class);

        $delivery = Delivery::create([
            'notification_uuid' => (string) Str::uuid(),
            'user_uuid'         => (string) Str::uuid(),
            'channel'           => 'whatsapp',
            'recipient'         => '+1234567890',
            'content'           => 'WhatsApp message',
            'status'            => 'pending',
        ]);

        $job = new DispatchDeliveryJob($delivery->uuid);
        $job->handle();

        $delivery->refresh();
        $this->assertSame('sent', $delivery->status);
    }

    // ── GET /deliveries/{uuid} ───────────────────────────────────────────

    public function test_get_delivery_returns_correct_data(): void
    {
        $delivery = Delivery::create([
            'notification_uuid' => (string) Str::uuid(),
            'user_uuid'         => (string) Str::uuid(),
            'channel'           => 'email',
            'recipient'         => 'user@example.com',
            'subject'           => 'Test Subject',
            'content'           => 'Test Content',
            'status'            => 'sent',
        ]);

        $response = $this->withToken($this->makeToken())
            ->getJson("/api/v1/deliveries/{$delivery->uuid}");

        $this->assertApiSuccess($response);
        $response->assertJsonPath('data.uuid', $delivery->uuid)
            ->assertJsonPath('data.channel', 'email')
            ->assertJsonPath('data.status', 'sent');
    }

    public function test_get_nonexistent_delivery_returns_404(): void
    {
        $response = $this->withToken($this->makeToken())
            ->getJson('/api/v1/deliveries/nonexistent-uuid');

        $this->assertApiError($response, 404, 'NOT_FOUND');
    }

    // ── Retry endpoint ───────────────────────────────────────────────────

    public function test_retry_endpoint_redispatches_failed_delivery(): void
    {
        Queue::fake();

        $delivery = Delivery::create([
            'notification_uuid' => (string) Str::uuid(),
            'user_uuid'         => (string) Str::uuid(),
            'channel'           => 'email',
            'recipient'         => 'user@example.com',
            'status'            => 'failed',
            'attempts_count'    => 3,
            'last_error'        => 'Previous error',
        ]);

        $response = $this->withToken($this->makeToken())
            ->postJson("/api/v1/deliveries/{$delivery->uuid}/retry");

        $this->assertApiSuccess($response);
        $response->assertJsonPath('data.status', 'retry_accepted')
            ->assertJsonPath('data.delivery_uuid', $delivery->uuid);

        $delivery->refresh();

        $this->assertSame('pending', $delivery->status);
        $this->assertSame(0, $delivery->attempts_count);
        $this->assertNull($delivery->last_error);

        Queue::assertPushed(DispatchDeliveryJob::class, 1);
    }

    // ── Validation errors ────────────────────────────────────────────────

    public function test_validation_errors_return_422_envelope(): void
    {
        $response = $this->withToken($this->makeToken())
            ->postJson('/api/v1/deliveries', [
                'notification_uuid' => 'not-a-uuid',
                'user_uuid'         => (string) Str::uuid(),
                'deliveries'        => [],
            ]);

        $this->assertApiError($response, 422, 'VALIDATION_ERROR');
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    private function makeDeliveryPayload(array $channels = ['email']): array
    {
        $deliveries = [];

        foreach ($channels as $channel) {
            $deliveries[] = [
                'channel'   => $channel,
                'recipient' => 'user@example.com',
                'subject'   => 'Welcome',
                'content'   => 'Hello user',
                'payload'   => ['key' => 'value'],
            ];
        }

        return [
            'notification_uuid' => (string) Str::uuid(),
            'user_uuid'         => (string) Str::uuid(),
            'deliveries'        => $deliveries,
        ];
    }

    private function mockProviders(): void
    {
        $emailMock = $this->mock(EmailProviderInterface::class);
        $emailMock->shouldReceive('send')->andReturn(['provider_message_id' => 'msg-email-001']);

        $whatsappMock = $this->mock(WhatsappProviderInterface::class);
        $whatsappMock->shouldReceive('send')->andReturn(['provider_message_id' => 'msg-wa-001']);

        $pushMock = $this->mock(PushProviderInterface::class);
        $pushMock->shouldReceive('send')->andReturn(['provider_message_id' => 'msg-push-001']);
    }
}
