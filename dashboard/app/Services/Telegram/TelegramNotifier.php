<?php

namespace App\Services\Telegram;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TelegramNotifier
{
    /**
     * Kirim pesan ke chat Telegram.
     *
     * Tanpa chat id, pesan dikirim ke chat yang dikonfigurasi. Diam kalau
     * token atau chat id belum diisi, dan kegagalan pengiriman tidak
     * melempar exception supaya tidak mengganggu proses pemanggil.
     */
    public function send(string $text, ?string $chatId = null): bool
    {
        $token = config('services.telegram.token');
        $chatId ??= config('services.telegram.chat_id');

        if (! is_string($token) || $token === '' || ! is_string($chatId) || $chatId === '') {
            return false;
        }

        try {
            Http::timeout(5)->post(
                "https://api.telegram.org/bot{$token}/sendMessage",
                ['chat_id' => $chatId, 'text' => $text],
            )->throw();

            return true;
        } catch (\Throwable $exception) {
            Log::warning('Kirim Telegram gagal.', ['error' => $exception->getMessage()]);

            return false;
        }
    }
}
