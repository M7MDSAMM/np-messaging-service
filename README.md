# Messaging Service (Port 8003)

Stateless Laravel 12 JSON API responsible for **multi-channel message delivery**. It accepts delivery requests from the Notification Service, routes them to the appropriate channel provider (email, WhatsApp, push), tracks delivery attempts, and supports retry logic.

## Responsibilities

- Accept batched delivery requests with per-channel recipient/content data.
- Route deliveries to channel-specific providers (Email, WhatsApp, Push).
- Track delivery attempts with provider message IDs and error details.
- Retry failed deliveries with configurable max attempts (default: 3).
- Delivery status tracking (pending / processing / sent / failed).
- Queue-based async dispatch via `DispatchDeliveryJob`.

## Database

**Database:** `np_messaging_service`

| Table | Purpose |
|-------|---------|
| `deliveries` | Delivery records: channel, recipient, subject, content, status, attempts count, provider info |
| `delivery_attempts` | Individual attempt records per delivery with status, provider message ID, and errors |
| `cache` | Laravel cache (standard) |
| `jobs` | Laravel queue jobs for async delivery dispatch |

## API Endpoints

All routes are prefixed with `/api/v1` and require JWT authentication.

| Method | Endpoint | Description |
|--------|----------|-------------|
| `GET` | `/health` | Service health check |
| `POST` | `/deliveries` | Create delivery requests (batch) |
| `GET` | `/deliveries/{uuid}` | Get delivery details with attempts |
| `POST` | `/deliveries/{uuid}/retry` | Retry a failed delivery |

### Create Deliveries Payload

```json
{
  "notification_uuid": "uuid",
  "user_uuid": "uuid",
  "deliveries": [
    {
      "channel": "email",
      "recipient": "user@example.com",
      "subject": "Welcome",
      "content": "Hello Alex!",
      "payload": {}
    }
  ]
}
```

## Architecture

- **Tech**: Laravel 12, PHP 8.2, MySQL.
- **Auth**: RS256 JWT validation via `JwtAdminAuthMiddleware`. Tokens are issued by User Service.
- **Middleware**:
  - `CorrelationIdMiddleware` — propagates `X-Correlation-Id` on every request/response.
  - `RequestTimingMiddleware` — logs method, route, status, latency, actor in structured JSON.
  - `JwtAdminAuthMiddleware` — validates Bearer token.
- **Providers**: Channel-specific provider implementations:
  - `EmailProvider` — sends via Laravel Mail (`Mail::raw()`).
  - `WhatsappProvider` — stub implementation (simulates delivery).
  - `PushProvider` — stub implementation (simulates delivery).
- **Queue**: `DispatchDeliveryJob` handles async delivery execution with attempt tracking.
- **Responses**: Standardized API envelope (`success`, `message`, `data`, `meta`, `correlation_id`).

## Local Setup

```bash
cp .env.example .env
composer install
php artisan key:generate
php artisan migrate
php artisan serve --port=8003
```

Requires MySQL with database `np_messaging_service` created.

For async delivery processing, start a queue worker:

```bash
php artisan queue:work --queue=default --tries=3
```

## Testing

```bash
php artisan test
```

Tests run against MySQL database `np_messaging_service_test` (configured in `phpunit.xml`). Uses `RefreshDatabase` and `Queue::fake()` to verify job dispatch without executing providers.

**Test coverage:** 13 tests, 94 assertions — covers delivery creation, batch processing, status tracking, retry, provider routing, validation, and auth.

## Notes

- WhatsApp and Push providers are currently stub implementations that simulate delivery and return mock provider message IDs.
- Each delivery tracks its own `attempts_count` against `max_attempts` for retry logic.
- Deliveries are soft-deleted for audit purposes.
