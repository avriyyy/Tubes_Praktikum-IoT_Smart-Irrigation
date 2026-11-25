<?php

namespace App\Services\Mqtt;

use App\Events\DeviceStatusReceived;
use App\Events\SensorDataReceived;
use App\Models\SensorReading;
use Carbon\Carbon;
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

        if ($topic === $sensorTopic) {
            $reading = $this->storeSensorReading($payload);
            $this->dispatch(fn () => SensorDataReceived::dispatch($topic, $payload, $this->point($reading)));
        } elseif ($topic === $statusTopic) {
            $reading = $this->storeDeviceStatus($payload);
            $this->dispatch(fn () => DeviceStatusReceived::dispatch($topic, $payload, $this->point($reading)));
        } else {
            Log::debug('Received MQTT message on unhandled topic.', ['topic' => $topic]);
        }

        Log::debug('Processed MQTT message.', ['topic' => $topic]);
    }

    /**
     * Broadcast must never break ingestion: if the websocket server is down,
     * the reading is already stored and the dashboard keeps polling.
     */
    private function dispatch(callable $dispatch): void
    {
        try {
            $dispatch();
        } catch (\Throwable $exception) {
            Log::warning('Realtime broadcast failed; reading is stored.', ['error' => $exception->getMessage()]);
        }
    }

    /**
     * Shape the stored row exactly like one history API row.
     *
     * @return array<string, mixed>
     */
    private function point(SensorReading $reading): array
    {
        return [
            't' => $reading->measured_at->toIso8601String(),
            'device' => $reading->device,
            'soil' => $reading->soil,
            'suhu' => $reading->suhu,
            'rh' => $reading->rh,
            'pompa' => $reading->pompa,
            'vpd' => $reading->vpd,
            'pid_output' => $reading->pid_output,
            'pid_error' => $reading->pid_error,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function storeSensorReading(array $payload): SensorReading
    {
        return SensorReading::create([
            'device' => $payload['device'] ?? null,
            'soil' => $this->floatOrNull($payload['soil'] ?? null),
            'suhu' => $this->floatOrNull($payload['suhu'] ?? null),
            'rh' => $this->floatOrNull($payload['rh'] ?? null),
            'pompa' => array_key_exists('pompa', $payload) ? (bool) $payload['pompa'] : null,
            'pid_output' => $this->floatOrNull($payload['pid'] ?? $payload['pid_output'] ?? null),
            'pid_error' => $this->floatOrNull($payload['err'] ?? $payload['pid_error'] ?? null),
            'measured_at' => $this->measuredAt($payload['ts'] ?? null),
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function storeDeviceStatus(array $payload): SensorReading
    {
        return SensorReading::create([
            'pompa' => array_key_exists('pompa', $payload) ? (bool) $payload['pompa'] : null,
            'vpd' => $this->floatOrNull($payload['vpd'] ?? null),
            'measured_at' => now(),
        ]);
    }

    private function floatOrNull(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    private function measuredAt(mixed $value): Carbon
    {
        if (! is_string($value) || $value === '') {
            return now();
        }

        try {
            $parsed = Carbon::parse($value);
        } catch (\Throwable) {
            return now();
        }

        // Columns are timezone-naive UTC: normalize first, otherwise an
        // offset-bearing timestamp is stored with its wall time shifted.
        $parsed = $parsed->copy()->setTimezone(config('app.timezone'));

        return $parsed->isAfter(now()->addMinutes(5)) ? now() : $parsed;
    }
}
