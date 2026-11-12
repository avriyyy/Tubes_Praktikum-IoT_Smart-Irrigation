<?php

use App\Services\Mqtt\MqttClientFactory;
use Tests\Support\FakeMqttClient;
use Tests\Support\FakeMqttClientFactory;

test('mqtt:consume subscribes and exits successfully', function () {
    $client = new FakeMqttClient;
    $this->app->bind(MqttClientFactory::class, fn () => new FakeMqttClientFactory($client));

    $this->artisan('mqtt:consume')->assertSuccessful();

    expect($client->subscribedTopics)->toContain(config('mqtt.topics.sensor'));
    expect($client->loopRan)->toBeTrue();
});

test('mqtt:consume reports a connection failure', function () {
    $client = new FakeMqttClient;
    $client->failOnConnect = true;
    $this->app->bind(MqttClientFactory::class, fn () => new FakeMqttClientFactory($client));

    $this->artisan('mqtt:consume')->assertFailed();
});
