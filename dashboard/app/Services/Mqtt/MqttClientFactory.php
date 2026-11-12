<?php

namespace App\Services\Mqtt;

use PhpMqtt\Client\MqttClient;

interface MqttClientFactory
{
    public function create(): MqttClient;
}
