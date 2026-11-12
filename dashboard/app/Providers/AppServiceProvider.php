<?php

namespace App\Providers;

use App\Services\Mqtt\MqttClientFactory;
use App\Services\Mqtt\PhpMqttClientFactory;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(MqttClientFactory::class, PhpMqttClientFactory::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
