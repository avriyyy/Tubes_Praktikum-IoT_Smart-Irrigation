<?php

namespace Tests\Support;

use PhpMqtt\Client\ConnectionSettings;
use PhpMqtt\Client\Exceptions\MqttClientException;
use PhpMqtt\Client\MqttClient;

class FakeMqttClient extends MqttClient
{
    /** @var array<int, string> */
    public array $subscribedTopics = [];

    /** @var array<string, callable|null> */
    public array $subscriptionCallbacks = [];

    /** @var array<int, array{topic: string, message: string, qos: int, retain: bool}> */
    public array $published = [];

    public bool $loopRan = false;

    public bool $interrupted = false;

    public bool $failOnConnect = false;

    public ?ConnectionSettings $lastSettings = null;

    private bool $connected = false;

    public function __construct()
    {
        parent::__construct('127.0.0.1', 1883, 'fake-client');
    }

    public function connect(?ConnectionSettings $settings = null, bool $useCleanSession = false): void
    {
        if ($this->failOnConnect) {
            throw new MqttClientException('Connection refused.');
        }

        $this->lastSettings = $settings;
        $this->connected = true;
    }

    public function subscribe(string $topicFilter, ?callable $callback = null, int $qualityOfService = self::QOS_AT_MOST_ONCE): void
    {
        $this->subscribedTopics[] = $topicFilter;
        $this->subscriptionCallbacks[$topicFilter] = $callback;
    }

    public function loop(bool $allowSleep = true, bool $exitWhenQueuesEmpty = false, ?int $queueWaitLimit = null): void
    {
        $this->loopRan = true;
    }

    public function publish(string $topic, string $message, int $qualityOfService = 0, bool $retain = false): void
    {
        $this->published[] = [
            'topic' => $topic,
            'message' => $message,
            'qos' => $qualityOfService,
            'retain' => $retain,
        ];
    }

    public function disconnect(): void
    {
        $this->connected = false;
    }

    public function interrupt(): void
    {
        $this->interrupted = true;
    }

    public function isConnected(): bool
    {
        return $this->connected;
    }

    public function simulateIncomingMessage(string $topic, string $message): void
    {
        call_user_func($this->subscriptionCallbacks[$topic], $topic, $message);
    }
}
