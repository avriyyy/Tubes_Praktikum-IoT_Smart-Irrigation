<?php

namespace App\Http\Controllers;

use App\Models\SensorReading;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

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

        $pid = $this->pidState($recent);

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
            'pid' => $pid,
        ]);
    }

    /**
     * Status kendali PID dari data tersimpan, mengikuti logika firmware:
     * PID aktif selama tanah < setpoint; nonaktif saat tanah >= setpoint.
     * Output PID hanya terisi kalau firmware sudah mengirimnya.
     *
     * @param  Collection<int, SensorReading>  $recent
     * @return array<string, mixed>
     */
    private function pidState($recent): array
    {
        $setpoint = (float) config('pid.setpoint');

        $sensor = $recent->first(fn (SensorReading $row) => $row->soil !== null);
        $outputRow = $recent->first(fn (SensorReading $row) => $row->pid_output !== null);

        return [
            'setpoint' => $setpoint,
            'kp' => (float) config('pid.kp'),
            'ki' => (float) config('pid.ki'),
            'kd' => (float) config('pid.kd'),
            'active' => $sensor?->soil !== null && $sensor->soil < $setpoint,
            'error' => $sensor?->soil !== null ? round($setpoint - $sensor->soil, 1) : null,
            'output' => $outputRow?->pid_output,
            'output_at' => $outputRow?->measured_at?->toIso8601String(),
        ];
    }

    public function history(Request $request): JsonResponse
    {
        $minutes = $request->has('minutes')
            ? min(max($request->integer('minutes'), 1), 10080)
            : min(max($request->integer('hours', 24), 1), 168) * 60;

        $rows = SensorReading::where('measured_at', '>=', now()->subMinutes($minutes))
            ->orderBy('measured_at')
            ->limit(150000)
            ->get(['device', 'soil', 'suhu', 'rh', 'pompa', 'vpd', 'pid_output', 'pid_error', 'measured_at']);

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
                'pid_output' => $row->pid_output,
                'pid_error' => $row->pid_error,
            ]),
        ]);
    }
}
