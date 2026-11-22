<?php

use App\Models\SensorReading;
use App\Services\Telegram\TelegramUpdateHandler;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config()->set('services.telegram.token', 'test-token');
    config()->set('services.telegram.chat_id', '8090083492');

    Http::fake();
});

function telegramUpdate(int $chatId, string $text, int $updateId = 1): array
{
    return [
        'update_id' => $updateId,
        'message' => [
            'message_id' => 10,
            'chat' => ['id' => $chatId, 'type' => 'private'],
            'from' => ['id' => $chatId, 'username' => 'avriyyy'],
            'text' => $text,
        ],
    ];
}

test('answers /status with the latest reading', function () {
    SensorReading::create(['device' => 'esp32-01', 'soil' => 58.0, 'suhu' => 31.0, 'rh' => 70.0, 'pompa' => true, 'measured_at' => now()]);

    app(TelegramUpdateHandler::class)->handle(telegramUpdate(8090083492, '/status'));

    Http::assertSent(function ($request) {
        return $request['chat_id'] === '8090083492'
            && str_contains($request['text'], 'Status sekarang')
            && str_contains($request['text'], '58%')
            && str_contains($request['text'], '31')
            && str_contains($request['text'], 'pompa nyala');
    });
});

test('answers /status with pump off when the latest pump state is off', function () {
    SensorReading::create(['device' => 'esp32-01', 'soil' => 71.0, 'suhu' => 30.0, 'rh' => 75.0, 'pompa' => true, 'measured_at' => now()->subMinutes(2)]);
    SensorReading::create(['pompa' => false, 'measured_at' => now()]);

    app(TelegramUpdateHandler::class)->handle(telegramUpdate(8090083492, '/status'));

    Http::assertSent(fn ($request) => str_contains($request['text'], 'pompa mati'));
});

test('answers /status with no data when there are no readings', function () {
    app(TelegramUpdateHandler::class)->handle(telegramUpdate(8090083492, '/status'));

    Http::assertSent(fn ($request) => str_contains($request['text'], 'Belum ada data sensor.'));
});

test('answers /start with help', function () {
    app(TelegramUpdateHandler::class)->handle(telegramUpdate(8090083492, '/start'));

    Http::assertSent(fn ($request) => str_contains($request['text'], '/status'));
});

test('ignores messages from another chat', function () {
    app(TelegramUpdateHandler::class)->handle(telegramUpdate(123456789, '/status'));

    Http::assertNothingSent();
});

test('ignores unknown commands', function () {
    app(TelegramUpdateHandler::class)->handle(telegramUpdate(8090083492, '/pompa_on'));

    Http::assertNothingSent();
});
