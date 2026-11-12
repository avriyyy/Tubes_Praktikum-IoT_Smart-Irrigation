<?php

use App\Services\Mqtt\MqttService;
use Tests\Support\FakeMqttClient;
use Tests\Support\FakeMqttClientFactory;

beforeEach(function () {
    $this->client = new FakeMqttClient;
    $this->service = new MqttService(new FakeMqttClientFactory($this->client));
});

test('consume subscribes to the sensor and status topics', function () {
    $this->service->consume(fn () => null);

    expect($this->client->subscribedTopics)
        ->toContain(config('mqtt.topics.sensor'), config('mqtt.topics.status'));
    expect($this->client->loopRan)->toBeTrue();
    expect($this->client->isConnected())->toBeFalse();
});

test('consume routes incoming messages to the callback', function () {
    $received = [];

    $this->service->consume(function (string $topic, string $message) use (&$received): void {
        $received[] = [$topic, $message];
    });
    $this->client->simulateIncomingMessage(config('mqtt.topics.sensor'), '{"temperature":27.5}');

    expect($received)->toBe([[config('mqtt.topics.sensor'), '{"temperature":27.5}']]);
});

test('publish sends the message and disconnects', function () {
    $this->service->publish('iot/kontrol', 'standby');

    expect($this->client->published)->toBe([[
        'topic' => 'iot/kontrol',
        'message' => 'standby',
        'qos' => 0,
        'retain' => false,
    ]]);
    expect($this->client->isConnected())->toBeFalse();
});

test('publishControl uses the kontrol topic', function () {
    $this->service->publishControl('standby');

    expect($this->client->published[0]['topic'])->toBe(config('mqtt.topics.kontrol'));
});

test('connects anonymously when credentials are blank', function () {
    config(['mqtt.username' => '', 'mqtt.password' => '']);

    $this->service->publish('iot/kontrol', 'standby');

    expect($this->client->lastSettings->getUsername())->toBeNull()
        ->and($this->client->lastSettings->getPassword())->toBeNull();
});

test('connects with credentials when configured', function () {
    config(['mqtt.username' => 'esp-user', 'mqtt.password' => 's3cret']);

    $this->service->publish('iot/kontrol', 'standby');

    expect($this->client->lastSettings->getUsername())->toBe('esp-user')
        ->and($this->client->lastSettings->getPassword())->toBe('s3cret');
});
