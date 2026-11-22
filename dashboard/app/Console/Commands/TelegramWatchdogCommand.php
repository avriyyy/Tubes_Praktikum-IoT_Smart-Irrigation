<?php

namespace App\Console\Commands;

use App\Services\Telegram\TelegramWatchdog;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

#[Signature('telegram:watchdog')]
#[Description('Watch the ESP feed and alert on Telegram when it goes silent or recovers.')]
class TelegramWatchdogCommand extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(TelegramWatchdog $watchdog): int
    {
        while (true) {
            try {
                $watchdog->check();
            } catch (\Throwable $exception) {
                Log::warning('Watchdog Telegram gagal.', ['error' => $exception->getMessage()]);
            }

            sleep(60);
        }
    }
}
