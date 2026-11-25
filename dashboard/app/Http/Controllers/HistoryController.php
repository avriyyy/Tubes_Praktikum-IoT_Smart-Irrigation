<?php

namespace App\Http\Controllers;

use App\Models\SensorReading;
use Carbon\Carbon;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;

class HistoryController extends Controller
{
    private const MAX_EXPORT_ROWS = 100000;

    // Dua pesan MQTT dari satu pengukuran yang sama (sensor + status) tiba
    // hampir bersamaan; baris dalam jendela ini digabung jadi satu.
    private const MERGE_WINDOW_SECONDS = 3;

    /**
     * Tabel riwayat data sensor, terbaru dulu, dengan filter tanggal.
     * Baris sensor dan status yang berdekatan digabung jadi satu pengukuran
     * (lihat mergeRows), jadi tabel tidak lagi menampilkan kolom kosong.
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
                ->where('measured_at', '>=', $oldest->measured_at->copy()->subSeconds(self::MERGE_WINDOW_SECONDS))
                ->orderByDesc('measured_at')
                ->limit(5)
                ->get();
            $rows = $rows->concat($extra);
        }

        $merged = $this->mergeRows($rows->sortBy('measured_at')->values())
            ->reverse()
            ->values();

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

            $pending = null;

            SensorReading::query()
                ->whereBetween('measured_at', [$from, $to])
                ->orderBy('measured_at')
                ->limit(self::MAX_EXPORT_ROWS)
                ->chunk(1000, function ($rows) use ($out, &$pending) {
                    foreach ($rows as $row) {
                        $pending = $this->absorb($pending, $row, function (SensorReading $merged) use ($out) {
                            $this->writeCsvRow($out, $merged);
                        });
                    }
                });

            if ($pending !== null) {
                $this->writeCsvRow($out, $pending);
            }

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * Gabungkan baris-baris mentah menjadi satu baris per pengukuran.
     *
     * ESP mengirim dua pesan MQTT per pengukuran: iot/sensor (device, tanah,
     * suhu, RH, pompa) dan iot/status (pompa, VPD). Keduanya tersimpan sebagai
     * baris terpisah, jadi tanpa penggabungan tabel menampilkan kolom kosong
     * berselang-seling. Baris status yang sama juga bisa terkirim ganda.
     *
     * Aturan: baris sensor selalu membuka grup baru (tidak ada data sensor
     * yang hilang); baris status bergabung ke grup yang sedang berjalan kalau
     * tiba dalam jendela dan mengisi VPD yang masih kosong, kalau tidak ia
     * dibuang sebagai duplikat. Grup ditutup saat baris berikutnya tiba di
     * luar jendela atau membuka grup baru.
     *
     * @param  Collection<int, SensorReading>  $rows  terurut menaik menurut measured_at
     * @return Collection<int, SensorReading>
     */
    private function mergeRows(Collection $rows): Collection
    {
        $merged = collect();
        $pending = null;

        foreach ($rows as $row) {
            $pending = $this->absorb($pending, $row, function (SensorReading $done) use ($merged) {
                $merged->push($done);
            });
        }

        if ($pending !== null) {
            $merged->push($pending);
        }

        return $merged;
    }

    /**
     * Serap satu baris mentah ke grup yang sedang berjalan.
     *
     * @return SensorReading grup yang sedang berjalan sesudah penyerapan
     */
    private function absorb(?SensorReading $pending, SensorReading $row, callable $flush): SensorReading
    {
        if ($pending === null) {
            return $row;
        }

        $gap = $row->measured_at->diffInSeconds($pending->measured_at, true);

        if ($row->soil !== null) {
            // Baris sensor: gabung hanya kalau grup belum punya data tanah
            // dan tiba dalam jendela; kalau tidak, grup lama selesai.
            if ($pending->soil === null && $gap <= self::MERGE_WINDOW_SECONDS) {
                return $this->mergeInto($pending, $row);
            }

            $flush($pending);

            return $row;
        }

        // Baris status: gabung kalau mengisi VPD yang masih kosong dalam
        // jendela; kalau tidak, ia duplikat dari status yang sudah ada.
        if ($pending->vpd === null && $gap <= self::MERGE_WINDOW_SECONDS) {
            return $this->mergeInto($pending, $row);
        }

        if ($gap <= self::MERGE_WINDOW_SECONDS) {
            return $pending;
        }

        $flush($pending);

        return $row;
    }

    /**
     * Isi kolom kosong di $into dengan nilai dari $row. Pompa selalu memakai
     * nilai terbaru, karena statusnya bisa berubah di dalam jendela.
     */
    private function mergeInto(SensorReading $into, SensorReading $row): SensorReading
    {
        foreach (['device', 'soil', 'suhu', 'rh', 'vpd', 'pid_output', 'pid_error'] as $field) {
            if ($into->{$field} === null && $row->{$field} !== null) {
                $into->{$field} = $row->{$field};
            }
        }

        if ($row->pompa !== null) {
            $into->pompa = $row->pompa;
        }

        if ($row->measured_at->greaterThan($into->measured_at)) {
            $into->measured_at = $row->measured_at;
        }

        return $into;
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
            $row->pid_error,
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
