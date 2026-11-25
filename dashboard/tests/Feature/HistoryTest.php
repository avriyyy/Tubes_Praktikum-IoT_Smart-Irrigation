<?php

use App\Models\SensorReading;

test('history page lists readings newest first', function () {
    SensorReading::create(['device' => 'esp32-01', 'soil' => 55.0, 'suhu' => 28.0, 'rh' => 80.0, 'pompa' => true, 'vpd' => 0.8, 'pid_output' => 60.0, 'pid_error' => 15.0, 'measured_at' => now()->subHour()]);
    SensorReading::create(['device' => 'esp32-01', 'soil' => 65.0, 'suhu' => 30.0, 'rh' => 81.0, 'pompa' => false, 'vpd' => 0.9, 'measured_at' => now()]);

    $this->withoutVite()->get('/riwayat')->assertOk()
        ->assertSee('Riwayat Data')
        ->assertSee('Unduh CSV')
        ->assertSee('65,0')
        ->assertSee('55,0')
        ->assertSee('60');
});

test('history page filters by date', function () {
    SensorReading::create(['soil' => 50.0, 'measured_at' => now('Asia/Jakarta')->subDays(5)->setTimezone('UTC')]);
    SensorReading::create(['soil' => 66.0, 'measured_at' => now()]);

    $from = now('Asia/Jakarta')->subDay()->format('Y-m-d');
    $to = now('Asia/Jakarta')->format('Y-m-d');

    $this->withoutVite()->get("/riwayat?from={$from}&to={$to}")->assertOk()
        ->assertSee('66,0')
        ->assertDontSee('50,0');
});

test('history export downloads a csv with the same columns', function () {
    SensorReading::create(['device' => 'esp32-01', 'soil' => 55.0, 'suhu' => 28.0, 'rh' => 80.0, 'pompa' => true, 'vpd' => 0.8, 'pid_output' => 60.0, 'pid_error' => 15.0, 'measured_at' => now()]);

    $response = $this->get('/riwayat/export');

    $response->assertOk();
    expect($response->headers->get('Content-Type'))->toContain('text/csv');
    expect($response->streamedContent())
        ->toContain('waktu_wib,device,soil_pct,suhu_c,rh_pct,pompa,vpd_kpa,pid_output_pct,pid_error_pct')
        ->toContain('esp32-01')
        ->toContain('55');
});

test('history export ignores rows outside the capped 31 day range', function () {
    SensorReading::create(['device' => 'esp32-tua', 'soil' => 44.0, 'measured_at' => now()->subDays(40)]);

    $from = now('Asia/Jakarta')->subDays(40)->format('Y-m-d');
    $to = now('Asia/Jakarta')->format('Y-m-d');

    $response = $this->get("/riwayat/export?from={$from}&to={$to}");

    $response->assertOk();
    expect($response->streamedContent())->not->toContain('esp32-tua');
});

test('history page merges a sensor row with its status twin', function () {
    $at = now()->subMinutes(5);
    SensorReading::create(['device' => 'esp32-01', 'soil' => 74.9, 'suhu' => 30.0, 'rh' => 79.0, 'pompa' => false, 'measured_at' => $at]);
    SensorReading::create(['pompa' => false, 'vpd' => 0.9, 'measured_at' => $at->copy()->addSecond()]);

    $this->withoutVite()->get('/riwayat')->assertOk()
        ->assertSee('74,9')
        ->assertSee('0,90');
});

test('history export merges sensor and status rows into one line', function () {
    $at = now()->subMinutes(5);
    SensorReading::create(['device' => 'esp32-01', 'soil' => 74.9, 'suhu' => 30.0, 'rh' => 79.0, 'pompa' => false, 'measured_at' => $at]);
    SensorReading::create(['pompa' => false, 'vpd' => 0.9, 'measured_at' => $at->copy()->addSecond()]);
    SensorReading::create(['pompa' => false, 'vpd' => 0.9, 'measured_at' => $at->copy()->addSeconds(2)]);

    $content = $this->get('/riwayat/export')->assertOk()->streamedContent();
    $lines = array_values(array_filter(explode("\n", trim($content))));

    expect($lines)->toHaveCount(2);
    expect($lines[1])->toContain('74.9')->toContain('0.9');
});

test('history export keeps every sensor row', function () {
    $at = now()->subMinutes(5);
    SensorReading::create(['device' => 'esp32-01', 'soil' => 74.9, 'suhu' => 30.0, 'rh' => 79.0, 'pompa' => false, 'measured_at' => $at]);
    SensorReading::create(['pompa' => false, 'vpd' => 0.9, 'measured_at' => $at->copy()->addSecond()]);
    SensorReading::create(['device' => 'esp32-01', 'soil' => 74.4, 'suhu' => 30.2, 'rh' => 79.0, 'pompa' => false, 'measured_at' => $at->copy()->addSeconds(4)]);
    SensorReading::create(['pompa' => false, 'vpd' => 0.92, 'measured_at' => $at->copy()->addSeconds(5)]);

    $content = $this->get('/riwayat/export')->assertOk()->streamedContent();
    $lines = array_values(array_filter(explode("\n", trim($content))));

    expect($lines)->toHaveCount(3);
    expect($lines[1])->toContain('74.9')->toContain('0.9');
    expect($lines[2])->toContain('74.4')->toContain('0.92');
});
