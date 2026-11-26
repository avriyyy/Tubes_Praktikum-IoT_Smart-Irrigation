<?php

namespace App\Support;

use App\Models\SensorReading;
use Carbon\Carbon;

/**
 * Menggabungkan baris sensor dan status menjadi satu baris per pengukuran.
 *
 * ESP mengirim dua pesan MQTT per pengukuran: iot/sensor (device, tanah,
 * suhu, RH, pompa) dan iot/status (pompa, VPD, output dan error PID). Keduanya tersimpan sebagai
 * baris terpisah, dan urutannya tidak selalu berselang-seling rapi: pesan
 * kadang tiba berkelompok (beberapa baris sensor dulu, baru baris status
 * beberapa detik kemudian), atau baris status bisa tiba duluan (jam ESP
 * dan jam server tidak selalu sama). Baris status juga bisa terkirim
 * ganda.
 *
 * Aturan: tiap baris dipasangkan dengan baris tertua yang belum punya
 * pasangan dan masih dalam jendela (FIFO, bukan yang terdekat). Karena
 * ESP mengirim kedua pesan berurutan, pasangan FIFO mengikuti urutan
 * siklus aslinya, jadi pelebaran jendela tidak mengacaukan pasangan.
 * Baris status yang tidak menemukan pasangan dalam jendela tetap
 * ditampilkan apa adanya (nilai VPD-nya data asli, jangan dibuang).
 * Baris sensor tidak pernah dibuang, dan tidak pernah digabung dengan
 * baris sensor lain, jadi tidak ada data sensor yang hilang.
 */
class MeasurementMerger
{
    // Jendela pasangan: di data live, kelompok sensor dan status bisa
    // terpaut sampai ~9 detik (diukur dari data 10 menit terakhir).
    public const WINDOW_SECONDS = 10;

    /** @var array<int, SensorReading> baris sensor tanpa pasangan, tertua dulu */
    private array $sensors = [];

    /** @var array<int, SensorReading> baris status tanpa pasangan, tertua dulu */
    private array $statuses = [];

    /** @var array<int, SensorReading> baris selesai yang menunggu ditulis */
    private array $ready = [];

    public function feed(SensorReading $row): void
    {
        if ($row->soil !== null) {
            $this->feedSensor($row);
        } else {
            $this->feedStatus($row);
        }

        $this->expire($row->measured_at);
    }

    private function feedSensor(SensorReading $row): void
    {
        $status = $this->takeOldest($this->statuses, $row->measured_at);

        if ($status !== null) {
            $this->mergeInto($row, $status);
            $this->ready[] = $row;

            return;
        }

        $this->sensors[] = $row;
    }

    private function feedStatus(SensorReading $row): void
    {
        $sensor = $this->takeOldest($this->sensors, $row->measured_at);

        if ($sensor !== null) {
            $this->mergeInto($sensor, $row);
            $this->ready[] = $sensor;

            return;
        }

        $this->statuses[] = $row;
    }

    /**
     * Ambil baris tertua dalam jendela dari antrean, lalu hapus dari sana.
     *
     * @param  array<int, SensorReading>  $queue
     */
    private function takeOldest(array &$queue, Carbon $at): ?SensorReading
    {
        foreach ($queue as $key => $candidate) {
            if ($at->diffInSeconds($candidate->measured_at, true) > self::WINDOW_SECONDS) {
                continue;
            }

            unset($queue[$key]);
            $queue = array_values($queue);

            return $candidate;
        }

        return null;
    }

    /**
     * Baris yang sudah terlalu tua untuk menemukan pasangan lagi dikeluarkan
     * apa adanya.
     */
    private function expire(Carbon $now): void
    {
        $cutoff = $now->copy()->subSeconds(self::WINDOW_SECONDS);

        foreach (['sensors', 'statuses'] as $key) {
            while (! empty($this->{$key}) && $this->{$key}[0]->measured_at->lessThan($cutoff)) {
                $this->ready[] = array_shift($this->{$key});
            }
        }
    }

    /**
     * Baris selesai yang cukup tua sehingga tidak akan disalip lagi oleh
     * baris berikutnya, terurut menurut waktu.
     *
     * @return array<int, SensorReading>
     */
    public function drain(Carbon $now): array
    {
        $cutoff = $now->copy()->subSeconds(self::WINDOW_SECONDS);
        $out = [];
        $rest = [];

        foreach ($this->ready as $row) {
            if ($row->measured_at->lessThan($cutoff)) {
                $out[] = $row;
            } else {
                $rest[] = $row;
            }
        }

        $this->ready = $rest;
        usort($out, fn (SensorReading $a, SensorReading $b) => $a->measured_at <=> $b->measured_at);

        return $out;
    }

    /**
     * Keluarkan semua sisa baris, terurut menurut waktu.
     *
     * @return array<int, SensorReading>
     */
    public function finish(): array
    {
        foreach (['sensors', 'statuses'] as $key) {
            foreach ($this->{$key} as $row) {
                $this->ready[] = $row;
            }
            $this->{$key} = [];
        }

        $out = $this->ready;
        $this->ready = [];
        usort($out, fn (SensorReading $a, SensorReading $b) => $a->measured_at <=> $b->measured_at);

        return $out;
    }

    /**
     * Isi kolom kosong di $into (baris sensor) dengan nilai dari $from
     * (baris status). Pompa memakai nilai yang lebih baru, karena
     * statusnya bisa berubah di dalam jendela.
     */
    private function mergeInto(SensorReading $into, SensorReading $from): void
    {
        foreach (['device', 'soil', 'suhu', 'rh', 'vpd', 'pid_output', 'pid_error'] as $field) {
            if ($into->{$field} === null && $from->{$field} !== null) {
                $into->{$field} = $from->{$field};
            }
        }

        if ($from->pompa !== null && $from->measured_at->greaterThanOrEqualTo($into->measured_at)) {
            $into->pompa = $from->pompa;
        }
    }
}
