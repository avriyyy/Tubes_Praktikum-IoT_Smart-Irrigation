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
            'measured_at' => 'datetime',
        ];
    }
}
