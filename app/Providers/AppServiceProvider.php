<?php

namespace App\Providers;

use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // TLS is terminated at Cloudflare/the host nginx; the request that
        // reaches this container is plain HTTP, so url()/asset() would
        // otherwise detect "http" from the request and generate mixed-content
        // links (e.g. report photo_url) that Android's cleartext-traffic
        // policy silently blocks, even though browsers tolerate it.
        if (str_starts_with(config('app.url'), 'https://')) {
            URL::forceScheme('https');
        }
    }
}
