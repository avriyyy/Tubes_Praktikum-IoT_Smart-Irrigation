<?php

namespace App\Http\Controllers;

use App\Models\SensorReading;
use App\Support\MeasurementMerger;
use Carbon\Carbon;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class HistoryController extends Controller
{
    private const MAX_EXPORT_ROWS = 100000;

    /**
     * Tabel riwayat data sensor, terbaru dulu, dengan filter tanggal.
     * Baris sensor dan status yang berdekatan digabung jadi satu pengukuran
     * (lihat MeasurementMerger), jadi tabel tidak lagi menampilkan kolom
     * kosong.
     */
    public function index(Request $request): View
    {
        [$from, $to] = $this->range($request);

        $page = SensorReading::query()
            ->whereBetween('measured_at', [$from, $to])
            ->orderByDesc('measured_at')
            ->paginate(150)
            ->withQueryString();

        $rows = $page->getCollection();

        // Lengkapi grup di ujung halaman: baris kembar dari pengukuran yang
        // sama bisa jatuh di halaman berikutnya.
        $oldest = $rows->last();
        if ($oldest) {
            $extra = SensorReading::query()
                ->whereBetween('measured_at', [$from, $to])
                ->where('measured_at', '<', $oldest->measured_at)
                ->where('measured_at', '>=', $oldest->measured_at->copy()->subSeconds(MeasurementMerger::WINDOW_SECONDS))
                ->orderByDesc('measured_at')
                ->limit(15)
                ->get();
            $rows = $rows->concat($extra);
        }

        $merger = new MeasurementMerger;
        foreach ($rows->sortBy('measured_at')->values() as $row) {
            $merger->feed($row);
        }
        $merged = collect($merger->finish())->reverse()->values();

        return view('history', [
            'readings' => $page,
            'rows' => $merged,
            'from' => $from->copy()->setTimezone('Asia/Jakarta')->format('Y-m-d'),
            'to' => $to->copy()->setTimezone('Asia/Jakarta')->format('Y-m-d'),
            'total' => SensorReading::query()->whereBetween('measured_at', [$from, $to])->count(),
        ]);
    }

    /**
     * Unduh riwayat yang sama sebagai CSV, untuk penelitian dan forecasting.
     * Kolom waktu dalam WIB. Baris digabung seperti di tabel.
     */
    public function export(Request $request): StreamedResponse
    {
        [$from, $to] = $this->range($request);

        $filename = 'riwayat-smart-irrigation-'.$from->copy()->setTimezone('Asia/Jakarta')->format('Ymd').'-'.$to->copy()->setTimezone('Asia/Jakarta')->format('Ymd').'.csv';

        return response()->streamDownload(function () use ($from, $to) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['waktu_wib', 'device', 'soil_pct', 'suhu_c', 'rh_pct', 'pompa', 'vpd_kpa', 'pid_output_pct', 'pid_error_pct']);

            $merger = new MeasurementMerger;
            $lastAt = null;

            SensorReading::query()
                ->whereBetween('measured_at', [$from, $to])
                ->orderBy('measured_at')
                ->limit(self::MAX_EXPORT_ROWS)
                ->chunk(1000, function ($rows) use ($out, $merger, &$lastAt) {
                    foreach ($rows as $row) {
                        $merger->feed($row);
                        $lastAt = $row->measured_at;
                    }

                    foreach ($merger->drain($lastAt) as $merged) {
                        $this->writeCsvRow($out, $merged);
                    }
                });

            foreach ($merger->finish() as $merged) {
                $this->writeCsvRow($out, $merged);
            }

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function writeCsvRow(mixed $out, SensorReading $row): void
    {
        fputcsv($out, [
            $row->measured_at->copy()->setTimezone('Asia/Jakarta')->format('Y-m-d H:i:s'),
            $row->device,
            $row->soil,
            $row->suhu,
            $row->rh,
            $row->pompa === null ? '' : ($row->pompa ? 1 : 0),
            $row->vpd,
            $row->pid_output,
            $row->pidErrorValue(),
        ]);
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
