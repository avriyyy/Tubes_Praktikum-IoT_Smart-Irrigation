<?php

/*
|--------------------------------------------------------------------------
| MQTT Broker
|--------------------------------------------------------------------------
|
| Connection settings for the Mosquitto MQTT broker that carries telemetry
| from the ESP32 devices. This application subscribes to the sensor and
| status topics, and publishes device commands to the kontrol topic.
|
*/

return [
    'host' => env('MQTT_HOST', 'mqtt.avriyyy.my.id'),
    'port' => (int) env('MQTT_PORT', 1883),
    'username' => env('MQTT_USERNAME'),
    'password' => env('MQTT_PASSWORD'),
    // The client id must be unique per running consumer, otherwise the
    // broker disconnects the older connection.
    'client_id' => env('MQTT_CLIENT_ID', 'dashboard-iot'),
    // Persistent sessions allow automatic reconnects; the service disables
    // reconnects automatically when a clean session is requested.
    'clean_session' => env('MQTT_CLEAN_SESSION', false),
    'keep_alive' => (int) env('MQTT_KEEP_ALIVE', 60),
    'connect_timeout' => (int) env('MQTT_CONNECT_TIMEOUT', 10),
    'qos' => (int) env('MQTT_QOS', 0),
    'topics' => [
        'sensor' => env('MQTT_TOPIC_SENSOR', 'iot/sensor'),
        'status' => env('MQTT_TOPIC_STATUS', 'iot/status'),
        'kontrol' => env('MQTT_TOPIC_KONTROL', 'iot/kontrol'),
    ],
];
