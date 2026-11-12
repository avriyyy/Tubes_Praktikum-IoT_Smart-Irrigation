<?php

namespace App\Services\Mqtt;

use PhpMqtt\Client\ConnectionSettings;
use PhpMqtt\Client\MqttClient;

class MqttService
{
    private ?MqttClient $activeClient = null;

    public function __construct(private MqttClientFactory $clientFactory) {}

    /**
     * Subscribe to the sensor and status topics and forward every incoming
     * message to the callback. Runs until stop() is called or the loop fails.
     *
     * @param  callable(string $topic, string $message): void  $messageCallback
     */
    public function consume(callable $messageCallback): void
    {
        $client = $this->connect();

        try {
            foreach ($this->subscribedTopics() as $topic) {
                $client->subscribe($topic, function (string $topic, string $message) use ($messageCallback): void {
                    $messageCallback($topic, $message);
                }, config('mqtt.qos'));
            }

            $client->loop(true);
        } finally {
            $this->activeClient = null;

            if ($client->isConnected()) {
                $client->disconnect();
            }
        }
    }

    public function publish(string $topic, string $message, int $qualityOfService = 0, bool $retain = false): void
    {
        $client = $this->connect();

        try {
            $client->publish($topic, $message, $qualityOfService, $retain);
        } finally {
            $this->activeClient = null;

            if ($client->isConnected()) {
                $client->disconnect();
            }
        }
    }

    public function publishControl(string $message): void
    {
        $this->publish(config('mqtt.topics.kontrol'), $message);
    }

    /**
     * @return list<string>
     */
    public function subscribedTopics(): array
    {
        return [
            config('mqtt.topics.sensor'),
            config('mqtt.topics.status'),
        ];
    }

    public function stop(): void
    {
        $this->activeClient?->interrupt();
    }

    private function connect(): MqttClient
    {
        $client = $this->clientFactory->create();

        $settings = (new ConnectionSettings)
            ->setKeepAliveInterval(config('mqtt.keep_alive'))
            ->setConnectTimeout(config('mqtt.connect_timeout'))
            // The client forbids automatic reconnects with a clean session.
            ->setReconnectAutomatically(! config('mqtt.clean_session'));

        $username = config('mqtt.username');

        if (filled($username)) {
            $settings = $settings->setUsername($username);

            $password = config('mqtt.password');

            if (filled($password)) {
                $settings = $settings->setPassword($password);
            }
        }

        $client->connect($settings, config('mqtt.clean_session'));
        $this->activeClient = $client;

        return $client;
    }
}
