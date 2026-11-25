<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SensorReading extends Model
{
    protected $fillable = [
        'device',
        'soil',
        'suhu',
        'rh',
        'pompa',
        'vpd',
        'pid_output',
        'pid_error',
        'measured_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'soil' => 'float',
            'suhu' => 'float',
            'rh' => 'float',
            'pompa' => 'boolean',
            'vpd' => 'float',
            'pid_output' => 'float',
            'pid_error' => 'float',
            'measured_at' => 'datetime',
        ];
    }

    /**
     * Error PID (setpoint - tanah), sama seperti yang dikirim firmware.
     * Bila ESP belum mengirim nilainya (firmware lama), hitung dari tanah
     * supaya kolom Error PID di riwayat selalu terisi.
     */
    public function pidErrorValue(): ?float
    {
        if ($this->pid_error !== null) {
            return $this->pid_error;
        }

        if ($this->soil === null) {
            return null;
        }

        return round((float) config('pid.setpoint') - $this->soil, 1);
    }
}
