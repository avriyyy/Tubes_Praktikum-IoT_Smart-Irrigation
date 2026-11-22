<?php

namespace App\Services\Telegram;

use App\Models\SensorReading;

class TelegramUpdateHandler
{
    public function __construct(private TelegramNotifier $notifier) {}

    /**
     * Tangani satu update dari Telegram.
     *
     * Hanya /status dan /start yang dijawab, dan hanya dari chat yang
     * dikonfigurasi. Bot ini tidak menerima perintah kendali apa pun.
     */
    public function handle(array $update): void
    {
        $message = $update['message'] ?? null;

        if (! is_array($message)) {
            return;
        }

        $chatId = (string) ($message['chat']['id'] ?? '');
        $configured = (string) config('services.telegram.chat_id', '');

        if ($chatId === '' || $configured === '' || $chatId !== $configured) {
            return;
        }

        $command = explode(' ', trim((string) ($message['text'] ?? '')))[0] ?? '';
        $command = explode('@', $command)[0];

        if ($command === '/status') {
            $this->reply($chatId, $this->statusText());
        } elseif ($command === '/start') {
            $this->reply($chatId, 'Bot notifikasi Smart Irrigation. Kirim /status buat lihat kondisi terakhir: kelembapan tanah, suhu, RH, dan pompa.');
        }
    }

    /**
     * Susun teks status dari data sensor terakhir.
     */
    private function statusText(): string
    {
        $reading = SensorReading::whereNotNull('soil')->latest('measured_at')->first();

        if ($reading === null) {
            return 'Belum ada data sensor.';
        }

        $pumpRow = SensorReading::whereNotNull('pompa')->latest('measured_at')->first();
        $pumpOn = $pumpRow !== null ? (bool) $pumpRow->pompa : (bool) $reading->pompa;

        return 'Status sekarang - kelembapan tanah '.$this->readableNumber($reading->soil).'%, suhu '.$this->readableNumber($reading->suhu).'°C, RH '.$this->readableNumber($reading->rh).'%, pompa '.($pumpOn ? 'nyala' : 'mati').'.';
    }

    /**
     * Kirim balasan ke chat pengirim.
     */
    private function reply(string $chatId, string $text): void
    {
        $this->notifier->send($text, $chatId);
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
