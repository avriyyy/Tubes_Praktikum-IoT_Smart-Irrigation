<?php

namespace App\Console\Commands;

use App\Services\Mqtt\MqttService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use PhpMqtt\Client\Exceptions\MqttClientException;

#[Signature('mqtt:publish {topic} {message} {--qos=0} {--retain}')]
#[Description('Publish a message to an MQTT topic.')]
class MqttPublishCommand extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(MqttService $mqtt): int
    {
        try {
            $mqtt->publish(
                $this->argument('topic'),
                $this->argument('message'),
                (int) $this->option('qos'),
                (bool) $this->option('retain')
            );
        } catch (MqttClientException $exception) {
            $this->error('MQTT error: '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->info('Published to ['.$this->argument('topic').'].');

        return self::SUCCESS;
    }
}
