<?php

namespace App\Services\Mqtt;

use PhpMqtt\Client\MqttClient;

class PhpMqttClientFactory implements MqttClientFactory
{
    public function create(): MqttClient
    {
        return new MqttClient(
            config('mqtt.host'),
            config('mqtt.port'),
            config('mqtt.client_id'),
            MqttClient::MQTT_3_1_1
        );
    }
}
