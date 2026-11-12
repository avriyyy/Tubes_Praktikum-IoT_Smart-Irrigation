<?php

namespace App\Services\Mqtt;

use App\Events\DeviceStatusReceived;
use App\Events\SensorDataReceived;
use Illuminate\Support\Facades\Log;

class MqttMessageHandler
{
    public function __invoke(string $topic, string $message): void
    {
        $payload = json_decode($message, true);

        if (! is_array($payload)) {
            Log::warning('Received invalid MQTT payload.', ['topic' => $topic]);

            return;
        }

        $sensorTopic = config('mqtt.topics.sensor');
        $statusTopic = config('mqtt.topics.status');

        match (true) {
            $topic === $sensorTopic => SensorDataReceived::dispatch($topic, $payload),
            $topic === $statusTopic => DeviceStatusReceived::dispatch($topic, $payload),
            default => Log::debug('Received MQTT message on unhandled topic.', ['topic' => $topic]),
        };

        Log::debug('Processed MQTT message.', ['topic' => $topic]);
    }
}
