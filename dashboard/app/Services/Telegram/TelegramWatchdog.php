<?php

namespace App\Services\Telegram;

use App\Models\SensorReading;
use Illuminate\Support\Facades\Cache;

class TelegramWatchdog
{
    /**
     * Data dianggap berhenti kalau tidak ada bacaan baru selama ini.
     */
    private const OFFLINE_AFTER_MINUTES = 5;

    public function __construct(private TelegramNotifier $notifier) {}

    /**
     * Periksa data terakhir sekali jalan.
     *
     * Kalau ESP berhenti mengirim data, kirim alert sekali saja sampai
     * datanya masuk lagi. Kalau datanya sudah masuk lagi setelah sempat
     * mati, kirim kabar pulih sekali saja.
     */
    public function check(): void
    {
        $latest = SensorReading::latest('measured_at')->first();

        if ($latest === null) {
            return;
        }

        $offline = now()->diffInMinutes($latest->measured_at, true) >= self::OFFLINE_AFTER_MINUTES;
        $alerted = (bool) Cache::get('telegram_esp_offline', false);

        if ($offline && ! $alerted) {
            $this->notifier->send($this->offlineText());
            Cache::put('telegram_esp_offline', true);
        } elseif (! $offline && $alerted) {
            $this->notifier->send('ESP hidup lagi.');
            Cache::put('telegram_esp_offline', false);
        }
    }

    /**
     * Susun pesan mati dengan kondisi terakhir yang diketahui.
     */
    private function offlineText(): string
    {
        $soilReading = SensorReading::whereNotNull('soil')->latest('measured_at')->first();
        $pumpRow = SensorReading::whereNotNull('pompa')->latest('measured_at')->first();

        $text = 'ESP mati, tidak ada data masuk.';

        $known = [];

        if ($soilReading !== null) {
            $known[] = 'tanah '.$this->readableNumber($soilReading->soil).'%';
        }

        $pumpOn = $pumpRow !== null ? (bool) $pumpRow->pompa : ($soilReading !== null ? (bool) $soilReading->pompa : null);

        if ($pumpOn !== null) {
            $known[] = 'pompa '.($pumpOn ? 'nyala' : 'mati');
        }

        if ($known !== []) {
            $text .= ' Terakhir: '.implode(', ', $known).'.';
        }

        return $text;
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
