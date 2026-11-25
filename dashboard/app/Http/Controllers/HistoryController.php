<?php

namespace App\Http\Controllers;

use App\Models\SensorReading;
use Carbon\Carbon;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class HistoryController extends Controller
{
    private const MAX_EXPORT_ROWS = 100000;

    /**
     * Tabel riwayat data sensor, terbaru dulu, dengan filter tanggal.
     */
    public function index(Request $request): View
    {
        [$from, $to] = $this->range($request);

        $readings = SensorReading::query()
            ->whereBetween('measured_at', [$from, $to])
            ->orderByDesc('measured_at')
            ->paginate(50)
            ->withQueryString();

        return view('history', [
            'readings' => $readings,
            'from' => $from->copy()->setTimezone('Asia/Jakarta')->format('Y-m-d'),
            'to' => $to->copy()->setTimezone('Asia/Jakarta')->format('Y-m-d'),
            'total' => SensorReading::query()->whereBetween('measured_at', [$from, $to])->count(),
        ]);
    }

    /**
     * Unduh riwayat yang sama sebagai CSV, untuk penelitian dan forecasting.
     * Kolom waktu dalam WIB.
     */
    public function export(Request $request): StreamedResponse
    {
        [$from, $to] = $this->range($request);

        $filename = 'riwayat-smart-irrigation-'.$from->copy()->setTimezone('Asia/Jakarta')->format('Ymd').'-'.$to->copy()->setTimezone('Asia/Jakarta')->format('Ymd').'.csv';

        return response()->streamDownload(function () use ($from, $to) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['waktu_wib', 'device', 'soil_pct', 'suhu_c', 'rh_pct', 'pompa', 'vpd_kpa', 'pid_output_pct', 'pid_error_pct']);

            SensorReading::query()
                ->whereBetween('measured_at', [$from, $to])
                ->orderBy('measured_at')
                ->limit(self::MAX_EXPORT_ROWS)
                ->chunk(1000, function ($rows) use ($out) {
                    foreach ($rows as $row) {
                        fputcsv($out, [
                            $row->measured_at->copy()->setTimezone('Asia/Jakarta')->format('Y-m-d H:i:s'),
                            $row->device,
                            $row->soil,
                            $row->suhu,
                            $row->rh,
                            $row->pompa === null ? '' : ($row->pompa ? 1 : 0),
                            $row->vpd,
                            $row->pid_output,
                            $row->pid_error,
                        ]);
                    }
                });

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * Rentang filter dari query ?from=YYYY-MM-DD&to=YYYY-MM-DD (WIB).
     * Default kemarin sampai hari ini, maksimal 31 hari.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    private function range(Request $request): array
    {
        $to = $this->parseDate($request->query('to'), now('Asia/Jakarta'))->endOfDay();
        $from = $this->parseDate($request->query('from'), now('Asia/Jakarta')->subDay())->startOfDay();

        if ($from->greaterThan($to)) {
            [$from, $to] = [$to->copy()->startOfDay(), $from->copy()->endOfDay()];
        }

        if ($from->diffInDays($to) > 31) {
            $from = $to->copy()->subDays(31)->startOfDay();
        }

        return [$from->copy()->setTimezone('UTC'), $to->copy()->setTimezone('UTC')];
    }

    private function parseDate(mixed $value, Carbon $fallback): Carbon
    {
        if (! is_string($value) || $value === '') {
            return $fallback;
        }

        try {
            $parsed = Carbon::createFromFormat('Y-m-d', $value, 'Asia/Jakarta');
        } catch (\Throwable) {
            return $fallback;
        }

        return $parsed === false ? $fallback : $parsed;
    }
}
