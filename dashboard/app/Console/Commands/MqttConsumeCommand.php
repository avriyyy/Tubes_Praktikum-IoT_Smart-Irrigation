<?php

namespace App\Console\Commands;

use App\Services\Mqtt\MqttMessageHandler;
use App\Services\Mqtt\MqttService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use PhpMqtt\Client\Exceptions\MqttClientException;

#[Signature('mqtt:consume')]
#[Description('Subscribe to MQTT topics and process incoming IoT messages.')]
class MqttConsumeCommand extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(MqttService $mqtt, MqttMessageHandler $handler): int
    {
        $this->info('Connecting to MQTT broker at '.config('mqtt.host').':'.config('mqtt.port').' ...');

        if (extension_loaded('pcntl')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGINT, fn () => $mqtt->stop());
            pcntl_signal(SIGTERM, fn () => $mqtt->stop());
        }

        try {
            $mqtt->consume($handler);
        } catch (MqttClientException $exception) {
            $this->error('MQTT error: '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->info('MQTT consumer stopped.');

        return self::SUCCESS;
    }
}
