<?php

namespace App\Providers;

use App\Domain\Auth\JwtTokenServiceInterface;
use App\Infrastructure\Auth\Rs256JwtTokenService;
use App\Services\Contracts\DeliveryServiceInterface;
use App\Services\Contracts\EmailProviderInterface;
use App\Services\Contracts\PushProviderInterface;
use App\Services\Contracts\WhatsappProviderInterface;
use App\Services\Implementations\DeliveryService;
use App\Services\Implementations\EmailProvider;
use App\Services\Implementations\PushProvider;
use App\Services\Implementations\WhatsappProvider;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Auth
        $this->app->bind(JwtTokenServiceInterface::class, Rs256JwtTokenService::class);

        // Delivery service
        $this->app->bind(DeliveryServiceInterface::class, DeliveryService::class);

        // Channel providers
        $this->app->bind(EmailProviderInterface::class, EmailProvider::class);
        $this->app->bind(WhatsappProviderInterface::class, WhatsappProvider::class);
        $this->app->bind(PushProviderInterface::class, PushProvider::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
