<?php

use App\Events\DeviceStatusReceived;
use App\Events\SensorDataReceived;
use App\Services\Mqtt\MqttMessageHandler;
use Carbon\Carbon;
use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Event;

test('sensor event broadcasts its stored point on the readings channel', function () {
    $event = new SensorDataReceived('iot/sensor', ['soil' => 61.2], [
        't' => '2026-11-12T17:04:48+07:00',
        'device' => 'esp32-01',
        'soil' => 61.2,
        'suhu' => 29.1,
        'rh' => 82.0,
        'pompa' => true,
        'vpd' => null,
    ]);

    expect($event)->toBeInstanceOf(ShouldBroadcastNow::class);

    $channels = array_map(fn (Channel $channel) => $channel->name, $event->broadcastOn());
    expect($channels)->toBe(['readings']);

    expect($event->broadcastAs())->toBe('sensor.received');
    expect($event->broadcastWith())->toMatchArray([
        't' => '2026-11-12T17:04:48+07:00',
        'device' => 'esp32-01',
        'soil' => 61.2,
    ]);
});

test('status event broadcasts its stored point on the readings channel', function () {
    $event = new DeviceStatusReceived('iot/status', ['pompa' => 1, 'vpd' => 0.73], [
        't' => '2026-11-12T17:04:48+07:00',
        'device' => null,
        'soil' => null,
        'suhu' => null,
        'rh' => null,
        'pompa' => true,
        'vpd' => 0.73,
    ]);

    expect($event)->toBeInstanceOf(ShouldBroadcastNow::class);

    $channels = array_map(fn (Channel $channel) => $channel->name, $event->broadcastOn());
    expect($channels)->toBe(['readings']);

    expect($event->broadcastAs())->toBe('status.received');
    expect($event->broadcastWith())->toMatchArray(['pompa' => true, 'vpd' => 0.73]);
});

test('handler attaches the stored point to the dispatched event', function () {
    Event::fake();

    app(MqttMessageHandler::class)(
        config('mqtt.topics.sensor'),
        '{"device":"esp32-01","soil":61.2,"suhu":29.1,"rh":82,"pompa":1,"ts":"2026-11-12T17:04:48+07:00"}'
    );

    Event::assertDispatched(SensorDataReceived::class, fn ($event) => $event->point['soil'] === 61.2
        && $event->point['device'] === 'esp32-01'
        && Carbon::parse($event->point['t'])->equalTo('2026-11-12T17:04:48+07:00'));
});

test('ingestion continues when the broadcast driver fails', function () {
    Broadcast::extend('failing', fn () => new class extends Illuminate\Broadcasting\Broadcasters\Broadcaster
    {
        public function auth($request) {}

        public function validAuthenticationResponse($request, $result) {}

        public function broadcast(array $channels, $event, array $payload = [])
        {
            throw new RuntimeException('websocket down');
        }
    });
    config()->set('broadcasting.default', 'failing');

    app(MqttMessageHandler::class)(config('mqtt.topics.sensor'), '{"soil":61.2}');

    $this->assertDatabaseHas('sensor_readings', ['soil' => 61.2]);
});
