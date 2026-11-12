<?php

use App\Events\DeviceStatusReceived;
use App\Events\SensorDataReceived;
use App\Services\Mqtt\MqttMessageHandler;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    Event::fake();
});

test('dispatches sensor event for a valid sensor payload', function () {
    $topic = config('mqtt.topics.sensor');

    app(MqttMessageHandler::class)($topic, '{"temperature":27.5,"humidity":60}');

    Event::assertDispatched(SensorDataReceived::class, fn ($event) => $event->topic === $topic
        && $event->payload === ['temperature' => 27.5, 'humidity' => 60]);
});

test('dispatches status event for a valid status payload', function () {
    $topic = config('mqtt.topics.status');

    app(MqttMessageHandler::class)($topic, '{"pump":"on","vpd":1.2}');

    Event::assertDispatched(DeviceStatusReceived::class, fn ($event) => $event->topic === $topic
        && $event->payload === ['pump' => 'on', 'vpd' => 1.2]);
});

test('ignores an invalid JSON payload', function () {
    app(MqttMessageHandler::class)(config('mqtt.topics.sensor'), 'not-json{');

    Event::assertNotDispatched(SensorDataReceived::class);
    Event::assertNotDispatched(DeviceStatusReceived::class);
});

test('ignores messages on unknown topics', function () {
    app(MqttMessageHandler::class)('iot/unknown', '{"temperature":27.5}');

    Event::assertNotDispatched(SensorDataReceived::class);
    Event::assertNotDispatched(DeviceStatusReceived::class);
});
