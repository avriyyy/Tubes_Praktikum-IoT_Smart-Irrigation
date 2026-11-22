<?php

namespace App\Listeners;

use App\Events\SensorDataReceived;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SendPumpTelegramNotification
{
    /**
     * Handle the event.
     *
     * Notifikasi hanya dikirim saat status pompa berubah, karena data
     * sensor masuk tiap 5 detik. Tanpa pengecekan ini channel bakal
     * kebanjiran pesan yang sama. Listener berjalan sinkron di dalam
     * proses mqtt:consume (tidak ada queue worker di container), jadi
     * pengiriman dibatasi timeout singkat dan kegagalannya tidak boleh
     * mengganggu ingestion data sensor.
     */
    public function handle(SensorDataReceived $event): void
    {
        try {
            $this->notifyIfPumpChanged($event);
        } catch (\Throwable $exception) {
            Log::warning('Notifikasi Telegram pompa gagal.', [
                'error' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * Kirim notifikasi saat status pompa berubah.
     */
    private function notifyIfPumpChanged(SensorDataReceived $event): void
    {
        $token = config('services.telegram.token');
        $chatId = config('services.telegram.chat_id');

        if (! is_string($token) || $token === '' || ! is_string($chatId) || $chatId === '') {
            return;
        }

        $pumpOn = $this->pumpState($event);

        if ($pumpOn === null) {
            return;
        }

        $previous = Cache::get('telegram_pump_last', null);

        if ($previous === $pumpOn) {
            return;
        }

        Cache::put('telegram_pump_last', $pumpOn);

        if ($previous === null) {
            return;
        }

        $soil = $this->readableNumber($event->point['soil'] ?? null);
        $suhu = $this->readableNumber($event->point['suhu'] ?? null);

        $text = $pumpOn
            ? "Pompa nyala. Kelembapan tanah {$soil}%, suhu {$suhu}°C."
            : "Pompa mati. Kelembapan tanah {$soil}%, suhu {$suhu}°C.";

        Http::timeout(5)->post(
            "https://api.telegram.org/bot{$token}/sendMessage",
            ['chat_id' => $chatId, 'text' => $text],
        )->throw();
    }

    /**
     * Ambil status pompa dari point (bool hasil cast model) atau payload.
     */
    private function pumpState(SensorDataReceived $event): ?bool
    {
        $raw = $event->point['pompa'] ?? $event->payload['pompa'] ?? null;

        if (is_bool($raw)) {
            return $raw;
        }

        if (is_numeric($raw)) {
            return (bool) $raw;
        }

        return null;
    }

    /**
     * Format angka sensor untuk pesan, '-' kalau tidak ada nilainya.
     */
    private function readableNumber(mixed $value): string
    {
        if (! is_numeric($value)) {
            return '-';
        }

        return (string) $value;
    }
}
