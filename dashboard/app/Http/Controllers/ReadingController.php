<?php

namespace App\Http\Controllers;

use App\Models\SensorReading;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReadingController extends Controller
{
    public function latest(): JsonResponse
    {
        $recent = SensorReading::latest('measured_at')->limit(1000)->get();
        $sensor = $recent->first(fn (SensorReading $row) => $row->soil !== null);
        $status = $recent->first(fn (SensorReading $row) => $row->vpd !== null);
        $pompa = $recent->first(fn (SensorReading $row) => $row->pompa !== null);

        if (! $sensor && ! $status && ! $pompa) {
            return response()->json(['has_data' => false]);
        }

        return response()->json([
            'has_data' => true,
            'device' => $sensor?->device,
            'soil' => $sensor?->soil,
            'suhu' => $sensor?->suhu,
            'rh' => $sensor?->rh,
            'soil_at' => $sensor?->measured_at?->toIso8601String(),
            'pompa' => $pompa?->pompa,
            'pompa_at' => $pompa?->measured_at?->toIso8601String(),
            'vpd' => $status?->vpd,
            'vpd_at' => $status?->measured_at?->toIso8601String(),
        ]);
    }

    public function history(Request $request): JsonResponse
    {
        $minutes = $request->has('minutes')
            ? min(max($request->integer('minutes'), 5), 10080)
            : min(max($request->integer('hours', 24), 1), 168) * 60;

        $rows = SensorReading::where('measured_at', '>=', now()->subMinutes($minutes))
            ->orderBy('measured_at')
            ->limit(150000)
            ->get(['device', 'soil', 'suhu', 'rh', 'pompa', 'vpd', 'measured_at']);

        $stride = (int) ceil($rows->count() / 1500);
        $sampled = $stride > 1
            ? $rows->filter(fn (SensorReading $row, int $key) => $key % $stride === 0)->values()
            : $rows;

        return response()->json([
            'minutes' => $minutes,
            'hours' => $minutes / 60,
            'count' => $sampled->count(),
            'rows' => $sampled->map(fn (SensorReading $row) => [
                't' => $row->measured_at->toIso8601String(),
                'device' => $row->device,
                'soil' => $row->soil,
                'suhu' => $row->suhu,
                'rh' => $row->rh,
                'pompa' => $row->pompa,
                'vpd' => $row->vpd,
            ]),
        ]);
    }
}
