<?php

use App\Events\SensorDataReceived;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config()->set('services.telegram.token', 'test-token');
    config()->set('services.telegram.chat_id', '@test-channel');

    Http::fake();
});

function sensorEvent(bool $pumpOn, float $soil = 58.0, float $suhu = 31.0): SensorDataReceived
{
    $point = ['soil' => $soil, 'suhu' => $suhu, 'pompa' => $pumpOn];
    $payload = ['soil' => $soil, 'suhu' => $suhu, 'pompa' => $pumpOn ? 1 : 0];

    return new SensorDataReceived('iot/sensor', $payload, $point);
}

test('sends a message when the pump turns on', function () {
    Cache::put('telegram_pump_last', false);

    event(sensorEvent(true));

    Http::assertSent(function ($request) {
        return $request->url() === 'https://api.telegram.org/bottest-token/sendMessage'
            && $request['chat_id'] === '@test-channel'
            && str_contains($request['text'], 'Pompa nyala')
            && str_contains($request['text'], '58%');
    });
});

test('sends a message when the pump turns off', function () {
    Cache::put('telegram_pump_last', true);

    event(sensorEvent(false, 71.0, 30.0));

    Http::assertSent(function ($request) {
        return $request['chat_id'] === '@test-channel'
            && str_contains($request['text'], 'Pompa mati')
            && str_contains($request['text'], '71%');
    });
});

test('does not send when the pump state is unchanged', function () {
    Cache::put('telegram_pump_last', true);

    event(sensorEvent(true));

    Http::assertNothingSent();
});

test('does not send on the first reading after restart', function () {
    event(sensorEvent(true));

    Http::assertNothingSent();
    expect(Cache::get('telegram_pump_last'))->toBeTrue();
});

test('does not send when telegram is not configured', function () {
    config()->set('services.telegram.token', null);

    event(sensorEvent(true));

    Http::assertNothingSent();
});
