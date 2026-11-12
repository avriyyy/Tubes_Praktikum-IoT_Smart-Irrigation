<?php

namespace Tests\Support;

use App\Services\Mqtt\MqttClientFactory;
use PhpMqtt\Client\MqttClient;

class FakeMqttClientFactory implements MqttClientFactory
{
    public function __construct(private FakeMqttClient $client) {}

    public function create(): MqttClient
    {
        return $this->client;
    }
}
