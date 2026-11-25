<!DOCTYPE html>
<html lang="id" class="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="dark">
    <title>Riwayat Data: Smart Irrigation Tanaman Cabai</title>
    @vite(['resources/css/app.css'])
</head>
<body class="min-h-screen text-pine antialiased dark:text-emerald-50">
    <div class="mx-auto w-full max-w-6xl px-4 py-4 sm:px-6">
        <header class="glass-strong sticky top-3 z-20 flex flex-wrap items-center gap-x-4 gap-y-2 rounded-3xl px-4 py-3 sm:px-5">
            <div>
                <h1 class="text-lg font-semibold tracking-tight">Riwayat Data</h1>
                <p class="text-sm text-moss dark:text-emerald-100/70">Smart Irrigation Tanaman Cabai</p>
            </div>
            <div class="ml-auto flex flex-wrap items-center gap-2">
                <a href="{{ url('/') }}" class="range-btn">Dashboard</a>
                <a href="{{ route('riwayat.export', ['from' => $from, 'to' => $to]) }}" class="range-btn">Unduh CSV</a>
            </div>
        </header>

        <section aria-label="Filter tanggal" class="glass mt-4 rounded-[2rem] p-5">
            <form method="get" action="{{ route('riwayat.index') }}" class="flex flex-wrap items-end gap-3">
                <div>
                    <label for="from" class="block text-xs font-medium text-moss dark:text-emerald-100/70">Dari tanggal</label>
                    <input id="from" name="from" type="date" value="{{ $from }}" max="{{ $to }}" class="mt-1 rounded-xl border border-pine/15 bg-white/70 px-3 py-2 text-sm dark:border-white/15 dark:bg-white/10">
                </div>
                <div>
                    <label for="to" class="block text-xs font-medium text-moss dark:text-emerald-100/70">Sampai tanggal</label>
                    <input id="to" name="to" type="date" value="{{ $to }}" max="{{ now('Asia/Jakarta')->format('Y-m-d') }}" class="mt-1 rounded-xl border border-pine/15 bg-white/70 px-3 py-2 text-sm dark:border-white/15 dark:bg-white/10">
                </div>
                <button type="submit" class="range-btn">Tampilkan</button>
            </form>
            <p class="mt-3 text-xs text-moss dark:text-emerald-100/70">{{ number_format($total, 0, ',', '.') }} baris tercatat pada {{ \Carbon\Carbon::parse($from, 'Asia/Jakarta')->isoFormat('D MMM YYYY') }} sampai {{ \Carbon\Carbon::parse($to, 'Asia/Jakarta')->isoFormat('D MMM YYYY') }} (WIB). ESP mengirim dua pesan per pengukuran (sensor dan status), jadi baris yang berdekatan digabung jadi satu pengukuran di tabel ini. CSV berisi kolom yang sama, maksimal 100.000 baris, untuk penelitian dan forecasting.</p>
        </section>

        <section aria-label="Tabel riwayat" class="glass mt-4 overflow-x-auto rounded-[2rem] p-5">
            @if ($rows->isEmpty())
                <p class="text-sm text-moss dark:text-emerald-100/70">Belum ada data pada rentang ini.</p>
            @else
                <table class="w-full min-w-[720px] text-left text-sm tabular-nums">
                    <thead>
                        <tr class="border-b border-pine/10 text-xs text-moss dark:border-white/10 dark:text-emerald-100/70">
                            <th scope="col" class="py-2 pr-3 font-medium">Waktu (WIB)</th>
                            <th scope="col" class="py-2 pr-3 font-medium">Perangkat</th>
                            <th scope="col" class="py-2 pr-3 font-medium">Tanah (%)</th>
                            <th scope="col" class="py-2 pr-3 font-medium">Suhu (°C)</th>
                            <th scope="col" class="py-2 pr-3 font-medium">RH (%)</th>
                            <th scope="col" class="py-2 pr-3 font-medium">Pompa</th>
                            <th scope="col" class="py-2 pr-3 font-medium">VPD (kPa)</th>
                            <th scope="col" class="py-2 pr-3 font-medium">Output PID (%)</th>
                            <th scope="col" class="py-2 font-medium">Error PID (%)</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $row)
                            <tr class="border-b border-pine/5 dark:border-white/5">
                                <td class="py-2 pr-3 whitespace-nowrap">{{ $row->measured_at->copy()->setTimezone('Asia/Jakarta')->format('d M Y H:i:s') }}</td>
                                <td class="py-2 pr-3">{{ $row->device ?? '–' }}</td>
                                <td class="py-2 pr-3">{{ $row->soil !== null ? number_format($row->soil, 1, ',', '.') : '–' }}</td>
                                <td class="py-2 pr-3">{{ $row->suhu !== null ? number_format($row->suhu, 1, ',', '.') : '–' }}</td>
                                <td class="py-2 pr-3">{{ $row->rh !== null ? number_format($row->rh, 0, ',', '.') : '–' }}</td>
                                <td class="py-2 pr-3">{{ $row->pompa === null ? '–' : ($row->pompa ? 'Nyala' : 'Mati') }}</td>
                                <td class="py-2 pr-3">{{ $row->vpd !== null ? number_format($row->vpd, 2, ',', '.') : '–' }}</td>
                                <td class="py-2 pr-3">{{ $row->pid_output !== null ? number_format($row->pid_output, 0, ',', '.') : '–' }}</td>
                                <td class="py-2">{{ $row->pid_error !== null ? number_format($row->pid_error, 1, ',', '.') : '–' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                <div class="mt-4">
                    {{ $readings->links() }}
                </div>
            @endif
            <p class="mt-3 text-xs text-moss dark:text-emerald-100/70">Output dan error PID baru tercatat setelah firmware ESP diperbarui (25 Nov 2026); data lama menampilkan "–" pada kedua kolom itu.</p>
        </section>
    </div>
</body>
</html>
