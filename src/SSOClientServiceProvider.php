<?php

declare(strict_types=1);

namespace MyUnnes\SSOClient;

use Illuminate\Support\ServiceProvider;
use MyUnnes\SSOClient\Services\SSOAuthService;
use MyUnnes\SSOClient\Services\PKCEService;
use MyUnnes\SSOClient\Services\StateService;
use MyUnnes\SSOClient\Services\TokenService;
use MyUnnes\SSOClient\Services\UserService;
use MyUnnes\SSOClient\Services\DiscoveryService;
use MyUnnes\SSOClient\Middleware\EnsureSSOAuthenticated;
use MyUnnes\SSOClient\Middleware\RedirectIfSSOAuthenticated;

class SSOClientServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        // Merge configuration
        $this->mergeConfigFrom(
            __DIR__.'/../config/sso-client.php',
            'sso-client'
        );

        // Register services as singletons for better performance
        $this->app->singleton(PKCEService::class);
        $this->app->singleton(StateService::class);
        $this->app->singleton(TokenService::class);
        $this->app->singleton(UserService::class);
        $this->app->singleton(DiscoveryService::class);
        $this->app->singleton(SSOAuthService::class);

        // Register the main SSO client
        $this->app->singleton('sso-client', function ($app) {
            return new SSOClient(
                $app->make(SSOAuthService::class),
                $app->make(UserService::class),
                $app->make('log')
            );
        });

        // Register facade alias
        $this->app->alias('sso-client', SSOClient::class);
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        // Publish configuration
        $this->publishes([
            __DIR__.'/../config/sso-client.php' => config_path('sso-client.php'),
        ], 'sso-client-config');

        // Publish migrations
        $this->publishes([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], 'sso-client-migrations');

        // Load routes if enabled
        if (config('sso-client.routes.enabled', false)) {
            $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
        }

        // Register middleware
        $this->app['router']->aliasMiddleware('sso.auth', EnsureSSOAuthenticated::class);
        $this->app['router']->aliasMiddleware('sso.guest', RedirectIfSSOAuthenticated::class);
    }

    /**
     * Get the services provided by the provider.
     */
    public function provides(): array
    {
        return [
            'sso-client',
            SSOClient::class,
            SSOAuthService::class,
            PKCEService::class,
            StateService::class,
            TokenService::class,
            UserService::class,
            DiscoveryService::class,
        ];
    }
}
