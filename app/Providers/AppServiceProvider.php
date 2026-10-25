<?php

namespace App\Providers;

use App\Services\IpSecurity;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(IpSecurity::class);
    }

    public function boot(): void
    {
        $raw = (string) config('swimshady.trusted_proxies', '127.0.0.1');
        if ($raw === '*') {
            TrustProxies::at('*');

            return;
        }

        $proxies = array_values(array_filter(array_map('trim', explode(',', $raw))));
        TrustProxies::at($proxies === [] ? ['127.0.0.1'] : $proxies);
    }
}
