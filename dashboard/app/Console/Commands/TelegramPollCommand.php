<?php

namespace App\Console\Commands;

use App\Services\Telegram\TelegramUpdateHandler;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

#[Signature('telegram:poll')]
#[Description('Poll Telegram updates and answer /status with the latest sensor reading.')]
class TelegramPollCommand extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(TelegramUpdateHandler $handler): int
    {
        $token = config('services.telegram.token');

        if (! is_string($token) || $token === '') {
            $this->error('TELEGRAM_BOT_TOKEN belum diisi.');

            return self::FAILURE;
        }

        $offset = (int) Cache::get('telegram_update_offset', 0);

        while (true) {
            try {
                $updates = $this->fetchUpdates($token, $offset);

                foreach ($updates as $update) {
                    $offset = ((int) ($update['update_id'] ?? 0)) + 1;
                    $handler->handle($update);
                }

                Cache::put('telegram_update_offset', $offset);
            } catch (\Throwable $exception) {
                Log::warning('Poll Telegram gagal.', ['error' => $exception->getMessage()]);
                sleep(5);
            }
        }
    }

    /**
     * Ambil update baru dengan long polling.
     *
     * @return array<int, array<string, mixed>>
     */
    private function fetchUpdates(string $token, int $offset): array
    {
        $response = Http::timeout(35)->get(
            "https://api.telegram.org/bot{$token}/getUpdates",
            ['offset' => $offset, 'timeout' => 30, 'allowed_updates' => ['message']],
        )->throw();

        return $response->json('result', []);
    }
}
