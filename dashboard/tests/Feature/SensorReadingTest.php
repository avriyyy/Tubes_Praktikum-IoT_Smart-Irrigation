<?php

use App\Models\SensorReading;
use App\Services\Mqtt\MqttMessageHandler;
use Illuminate\Support\Facades\Event;

test('stores a sensor payload from the ESP', function () {
    Event::fake();

    app(MqttMessageHandler::class)(
        config('mqtt.topics.sensor'),
        '{"device":"esp32-01","soil":61.2,"suhu":29.1,"rh":82,"pompa":1,"ts":"2026-11-12T17:04:48+07:00"}'
    );

    $this->assertDatabaseHas('sensor_readings', [
        'device' => 'esp32-01',
        'soil' => 61.2,
        'suhu' => 29.1,
        'rh' => 82.0,
    ]);
    expect(SensorReading::first()->pompa)->toBeTrue();
});

test('stores a status payload from the ESP', function () {
    Event::fake();

    app(MqttMessageHandler::class)(config('mqtt.topics.status'), '{"pompa":1,"vpd":0.73}');

    $this->assertDatabaseHas('sensor_readings', ['vpd' => 0.73]);
    expect(SensorReading::first()->pompa)->toBeTrue();
});

test('latest endpoint returns the newest metrics', function () {
    SensorReading::create(['device' => 'esp32-01', 'soil' => 55.0, 'suhu' => 28.0, 'rh' => 80.0, 'measured_at' => now()->subMinutes(10)]);
    SensorReading::create(['device' => 'esp32-01', 'soil' => 65.0, 'suhu' => 30.0, 'rh' => 81.0, 'measured_at' => now()]);
    SensorReading::create(['pompa' => true, 'vpd' => 0.8, 'measured_at' => now()]);

    $data = $this->getJson('/api/readings/latest')->assertOk()->json();

    expect($data['has_data'])->toBeTrue()
        ->and($data['soil'])->toEqual(65.0)
        ->and($data['suhu'])->toEqual(30.0)
        ->and($data['pompa'])->toBeTrue()
        ->and($data['vpd'])->toEqual(0.8);
});

test('latest endpoint reports empty without data', function () {
    $data = $this->getJson('/api/readings/latest')->assertOk()->json();

    expect($data['has_data'])->toBeFalse();
});

test('history endpoint returns ascending rows inside the window', function () {
    SensorReading::create(['soil' => 60.0, 'measured_at' => now()->subHours(2)]);
    SensorReading::create(['soil' => 61.0, 'measured_at' => now()->subMinutes(30)]);
    SensorReading::create(['soil' => 62.0, 'measured_at' => now()]);

    $data = $this->getJson('/api/readings/history?hours=1')->assertOk()->json();

    expect($data['hours'])->toBe(1)
        ->and($data['count'])->toBe(2)
        ->and($data['rows'][0]['soil'])->toEqual(61.0)
        ->and($data['rows'][1]['soil'])->toEqual(62.0);
});

test('history endpoint accepts a minutes window', function () {
    SensorReading::create(['soil' => 60.0, 'measured_at' => now()->subMinutes(30)]);
    SensorReading::create(['soil' => 61.0, 'measured_at' => now()->subMinutes(5)]);

    $data = $this->getJson('/api/readings/history?minutes=15')->assertOk()->json();

    expect($data['minutes'])->toBe(15)
        ->and($data['count'])->toBe(1)
        ->and($data['rows'][0]['soil'])->toEqual(61.0);
});

test('history endpoint accepts a one minute window', function () {
    SensorReading::create(['soil' => 60.0, 'measured_at' => now()->subMinutes(5)]);
    SensorReading::create(['soil' => 61.0, 'measured_at' => now()->subSeconds(30)]);

    $data = $this->getJson('/api/readings/history?minutes=1')->assertOk()->json();

    expect($data['minutes'])->toBe(1)
        ->and($data['count'])->toBe(1)
        ->and($data['rows'][0]['soil'])->toEqual(61.0);
});

test('dashboard page loads', function () {
    $this->withoutVite()->get('/')->assertOk()->assertSee('Dashboard Monitoring')->assertSee('Durasi siram')->assertSee('<html lang="id" class="dark">', false)->assertSee('espState')->assertDontSee('themeToggle', false);
});

test('clamps future sensor timestamps to arrival time', function () {
    Event::fake();

    app(MqttMessageHandler::class)(config('mqtt.topics.sensor'), '{"soil":50,"ts":"2999-01-01T00:00:00+07:00"}');

    expect(abs(SensorReading::first()->measured_at->diffInSeconds(now())))->toBeLessThan(60);
});

test('stores offset timestamps as UTC instants', function () {
    Event::fake();

    app(MqttMessageHandler::class)(config('mqtt.topics.sensor'), '{"soil":50,"ts":"2026-11-14T14:17:00+07:00"}');

    $this->assertDatabaseHas('sensor_readings', [
        'soil' => 50.0,
        'measured_at' => '2026-11-14 07:17:00',
    ]);
});

test('history endpoint downsamples long windows', function () {
    $rows = [];
    for ($i = 0; $i < 2000; $i++) {
        $rows[] = ['soil' => 60.0, 'measured_at' => now()->subMinutes(2000 - $i), 'created_at' => now(), 'updated_at' => now()];
    }
    SensorReading::insert($rows);

    $data = $this->getJson('/api/readings/history?hours=168')->assertOk()->json();

    expect($data['count'])->toBeLessThanOrEqual(1500);
});
