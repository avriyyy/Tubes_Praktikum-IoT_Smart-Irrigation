<?php

use App\Models\SensorReading;
use App\Services\Telegram\TelegramWatchdog;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config()->set('services.telegram.token', 'test-token');
    config()->set('services.telegram.chat_id', '8090083492');

    Http::fake();
});

test('sends nothing when data is fresh', function () {
    SensorReading::create(['device' => 'esp32-01', 'soil' => 58.0, 'suhu' => 31.0, 'pompa' => true, 'measured_at' => now()]);

    app(TelegramWatchdog::class)->check();

    Http::assertNothingSent();
});

test('sends one offline alert when data stops', function () {
    SensorReading::create(['device' => 'esp32-01', 'soil' => 58.0, 'suhu' => 31.0, 'pompa' => true, 'measured_at' => now()->subMinutes(10)]);

    app(TelegramWatchdog::class)->check();

    Http::assertSent(function ($request) {
        return $request['chat_id'] === '8090083492'
            && str_contains($request['text'], 'ESP mati')
            && str_contains($request['text'], '58%')
            && str_contains($request['text'], 'pompa nyala');
    });
});

test('does not repeat the offline alert while the ESP stays silent', function () {
    SensorReading::create(['device' => 'esp32-01', 'soil' => 58.0, 'suhu' => 31.0, 'pompa' => true, 'measured_at' => now()->subMinutes(10)]);

    $watchdog = app(TelegramWatchdog::class);
    $watchdog->check();
    $watchdog->check();

    expect(Http::recorded())->toHaveCount(1);
});

test('sends a recovery message when data resumes', function () {
    Cache::put('telegram_esp_offline', true);
    SensorReading::create(['device' => 'esp32-01', 'soil' => 57.0, 'suhu' => 30.0, 'pompa' => false, 'measured_at' => now()]);

    app(TelegramWatchdog::class)->check();

    Http::assertSent(fn ($request) => str_contains($request['text'], 'ESP hidup lagi.'));
    expect(Cache::get('telegram_esp_offline'))->toBeFalse();
});

test('sends nothing when there are no readings at all', function () {
    app(TelegramWatchdog::class)->check();

    Http::assertNothingSent();
});
