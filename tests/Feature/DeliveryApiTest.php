<?php

namespace Tests\Feature;

use App\Jobs\DispatchDeliveryJob;
use App\Models\Delivery;
use App\Models\DeliveryAttempt;
use App\Services\Contracts\EmailProviderInterface;
use App\Services\Contracts\PushProviderInterface;
use App\Services\Contracts\WhatsappProviderInterface;
use Firebase\JWT\JWT;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class DeliveryApiTest extends TestCase
{
    use RefreshDatabase;

    private string $privateKey;

    protected function setUp(): void
    {
        parent::setUp();

        [$private, $public] = $this->generateKeyPair();
        $this->privateKey = $private;

        config([
            'jwt.keys.public_content' => $public,
            'jwt.keys.public'         => null,
            'jwt.issuer'              => 'user-service',
            'jwt.audience'            => 'notification-platform',
        ]);
    }

    // ── 1. Health endpoint ──────────────────────────────────────────────

    public function test_health_returns_standardized_envelope(): void
    {
        $response = $this->getJson('/api/v1/health');

        $response->assertOk()
            ->assertHeader('X-Correlation-Id')
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['correlation_id', 'data' => ['service', 'status', 'time']]);
    }

    // ── 2. Auth required ────────────────────────────────────────────────

    public function test_unauthorized_access_returns_401_envelope(): void
    {
        $response = $this->postJson('/api/v1/deliveries', []);

        $response->assertUnauthorized()
            ->assertJsonPath('success', false)
            ->assertJsonPath('error_code', 'AUTH_INVALID')
            ->assertJsonStructure(['correlation_id']);
    }

    // ── 3. Create deliveries ────────────────────────────────────────────

    public function test_authenticated_admin_can_create_deliveries(): void
    {
        Queue::fake();

        $token = $this->makeToken();
        $payload = $this->makeDeliveryPayload();

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/deliveries', $payload);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Deliveries accepted.');

        $this->assertDatabaseHas('deliveries', [
            'notification_uuid' => $payload['notification_uuid'],
            'channel'           => 'email',
            'status'            => 'pending',
        ]);
    }

    // ── 4. Queue jobs dispatched ────────────────────────────────────────

    public function test_creating_deliveries_dispatches_queue_jobs(): void
    {
        Queue::fake();

        $token = $this->makeToken();
        $payload = $this->makeDeliveryPayload(channels: ['email', 'push']);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/deliveries', $payload);

        Queue::assertPushed(DispatchDeliveryJob::class, 2);
    }

    // ── 5. Successful job marks delivery as sent ────────────────────────

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

    // ── 6. Failed job retries then marks failed ─────────────────────────

    public function test_failed_job_retries_and_eventually_marks_failed(): void
    {
        $emailMock = $this->mock(EmailProviderInterface::class);
        $emailMock->shouldReceive('send')
            ->andThrow(new \RuntimeException('SMTP connection refused'));

        // Simulate 3 attempts exhausting max_attempts
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

        // We can't easily test release() in sync mode, so we test the final failure path
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

    // ── 7. GET /deliveries/{uuid} ───────────────────────────────────────

    public function test_get_delivery_returns_correct_data(): void
    {
        $token = $this->makeToken();

        $delivery = Delivery::create([
            'notification_uuid' => (string) Str::uuid(),
            'user_uuid'         => (string) Str::uuid(),
            'channel'           => 'email',
            'recipient'         => 'user@example.com',
            'subject'           => 'Test Subject',
            'content'           => 'Test Content',
            'status'            => 'sent',
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/v1/deliveries/{$delivery->uuid}");

        $response->assertOk()
            ->assertJsonPath('data.uuid', $delivery->uuid)
            ->assertJsonPath('data.channel', 'email')
            ->assertJsonPath('data.status', 'sent')
            ->assertJsonStructure(['correlation_id']);
    }

    // ── 8. Retry endpoint ───────────────────────────────────────────────

    public function test_retry_endpoint_redispatches_failed_delivery(): void
    {
        Queue::fake();

        $token = $this->makeToken();

        $delivery = Delivery::create([
            'notification_uuid' => (string) Str::uuid(),
            'user_uuid'         => (string) Str::uuid(),
            'channel'           => 'email',
            'recipient'         => 'user@example.com',
            'status'            => 'failed',
            'attempts_count'    => 3,
            'last_error'        => 'Previous error',
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/v1/deliveries/{$delivery->uuid}/retry");

        $response->assertOk()
            ->assertJsonPath('data.status', 'retry_accepted')
            ->assertJsonPath('data.delivery_uuid', $delivery->uuid);

        $delivery->refresh();

        $this->assertSame('pending', $delivery->status);
        $this->assertSame(0, $delivery->attempts_count);
        $this->assertNull($delivery->last_error);

        Queue::assertPushed(DispatchDeliveryJob::class, 1);
    }

    // ── 9. Validation errors ────────────────────────────────────────────

    public function test_validation_errors_return_422_envelope(): void
    {
        $token = $this->makeToken();

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/deliveries', [
                'notification_uuid' => 'not-a-uuid',
                'user_uuid'         => (string) Str::uuid(),
                'deliveries'        => [],
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error_code', 'VALIDATION_ERROR')
            ->assertJsonStructure(['errors', 'correlation_id']);
    }

    // ── Helpers ─────────────────────────────────────────────────────────

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

    private function makeToken(string $role = 'admin'): string
    {
        $now = time();
        $payload = [
            'iss'  => 'user-service',
            'aud'  => 'notification-platform',
            'sub'  => 'admin-uuid',
            'typ'  => 'admin',
            'role' => $role,
            'iat'  => $now,
            'exp'  => $now + 3600,
        ];

        return JWT::encode($payload, $this->privateKey, 'RS256');
    }

    private function generateKeyPair(): array
    {
        $res = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);

        openssl_pkey_export($res, $privateKey);
        $pub       = openssl_pkey_get_details($res);
        $publicKey = $pub['key'];

        return [$privateKey, $publicKey];
    }
}
