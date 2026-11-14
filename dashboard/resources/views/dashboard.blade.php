<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light dark">
    <title>Dashboard Monitoring: Smart Irrigation Tanaman Cabai</title>
    <script>try{if(localStorage.getItem('pp-theme')==='dark'||(!localStorage.getItem('pp-theme')&&matchMedia('(prefers-color-scheme: dark)').matches))document.documentElement.classList.add('dark')}catch(e){}</script>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen text-pine antialiased dark:text-emerald-50">
    <div class="mx-auto w-full max-w-6xl px-4 py-4 sm:px-6">
        <header class="glass-strong sticky top-3 z-20 flex flex-wrap items-center gap-x-4 gap-y-2 rounded-3xl px-4 py-3 sm:px-5">
            <div>
                <h1 class="text-lg font-semibold tracking-tight">Dashboard Monitoring</h1>
                <p class="text-sm text-moss dark:text-emerald-100/70">Smart Irrigation Tanaman Cabai</p>
            </div>
            <p id="deviceBadge" class="rounded-full bg-leaf-100/80 px-2.5 py-1 text-xs font-medium text-leaf-700 dark:bg-emerald-400/15 dark:text-emerald-300">Perangkat: …</p>
            <div class="ml-auto flex flex-wrap items-center gap-1" role="group" aria-label="Rentang waktu grafik">
                <button type="button" data-minutes="15" aria-pressed="false" class="range-btn">15 menit</button>
                <button type="button" data-minutes="60" aria-pressed="false" class="range-btn">1 jam</button>
                <button type="button" data-minutes="1440" aria-pressed="true" class="range-btn">24 jam</button>
                <button id="refreshButton" type="button" aria-label="Muat ulang sekarang" title="Muat ulang sekarang" class="range-btn range-icon">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 12a8 8 0 1 1-2.3-5.6"/><path d="M20 3v4h-4"/></svg>
                </button>
                <button id="themeToggle" type="button" aria-label="Alihkan mode gelap" title="Alihkan mode gelap" aria-pressed="false" class="range-btn range-icon">
                    <svg id="iconMoon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 13.5A8 8 0 1 1 10.5 4 6.5 6.5 0 0 0 20 13.5z"/></svg>
                    <svg id="iconSun" class="hidden" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/></svg>
                </button>
            </div>
        </header>

        <div class="flex flex-wrap items-center gap-3 px-1 pt-4">
            <p id="statusLine" role="status" class="text-sm text-moss dark:text-emerald-100/70">Memuat data sensor…</p>
            <button id="retryButton" type="button" class="retry-btn hidden">Coba lagi</button>
        </div>

        <div id="emptyState" class="glass mt-4 hidden rounded-[2rem] p-6">
            <h2 class="text-lg font-semibold">Belum ada data.</h2>
            <p class="mt-1 text-sm text-moss dark:text-emerald-100/70">Nyalakan ESP, pastikan ia mengirim ke broker, lalu dari folder dashboard jalankan consumer <code class="rounded bg-white/70 px-1 py-0.5 text-[13px] text-pine dark:bg-white/10 dark:text-emerald-50">php artisan mqtt:consume</code> dan server realtime <code class="rounded bg-white/70 px-1 py-0.5 text-[13px] text-pine dark:bg-white/10 dark:text-emerald-50">php artisan reverb:start</code>.</p>
        </div>

        <div id="dataSections" class="hidden">
            <section aria-label="Nilai terkini" class="mt-4 grid grid-cols-2 gap-4 xl:grid-cols-5">
                <article class="glass col-span-2 flex flex-col items-center rounded-3xl p-4 xl:col-span-1">
                    <h2 class="text-sm font-medium text-moss dark:text-emerald-100/70">Status</h2>
                    <div class="relative mt-2 flex h-24 w-24 items-center justify-center" role="img" id="soilRingImg" aria-label="Lingkaran kelembapan tanah">
                        <svg class="h-full w-full" viewBox="0 0 120 120" aria-hidden="true">
                            <circle class="stroke-pine/10 dark:stroke-white/15" stroke-width="10" cx="60" cy="60" r="50" fill="transparent"></circle>
                            <circle id="soilRing" class="origin-center -rotate-90 text-leaf-600 dark:text-emerald-400" stroke="currentColor" stroke-width="10" stroke-linecap="round" cx="60" cy="60" r="50" fill="transparent" stroke-dasharray="314.159" stroke-dashoffset="314.159"></circle>
                        </svg>
                        <p class="absolute text-center tabular-nums"><span id="soilNow" class="text-xl font-bold">–</span> <span class="text-xs text-moss dark:text-emerald-100/70">%</span></p>
                    </div>
                    <p id="soilVerdict" class="mt-2 text-center text-xs font-medium text-moss dark:text-emerald-100/70">Menunggu data…</p>
                    <p id="soilRingTime" class="mt-1 text-[11px] text-moss dark:text-emerald-100/70">–</p>
                </article>
                <article id="pumpCard" class="glass rounded-3xl p-4">
                    <h2 class="text-sm font-medium text-moss dark:text-emerald-100/70">Pompa</h2>
                    <p class="mt-2 flex items-center gap-2">
                        <span id="pumpDot" class="inline-block h-2.5 w-2.5 rounded-full bg-pine/20 dark:bg-white/25"></span>
                        <span id="pumpNow" class="text-2xl font-semibold tracking-tight">–</span>
                    </p>
                    <p id="pumpTime" class="mt-1 text-xs text-moss dark:text-emerald-100/70">–</p>
                </article>
                <article class="glass rounded-3xl p-4">
                    <h2 class="text-sm font-medium text-moss dark:text-emerald-100/70">Suhu udara</h2>
                    <p class="mt-2 tabular-nums"><span id="suhuNow" class="text-2xl font-semibold tracking-tight">–</span> <span class="text-sm text-moss dark:text-emerald-100/70">°C</span></p>
                    <p id="suhuTime" class="mt-1 text-xs text-moss dark:text-emerald-100/70">–</p>
                </article>
                <article class="glass rounded-3xl p-4">
                    <h2 class="text-sm font-medium text-moss dark:text-emerald-100/70">RH udara</h2>
                    <p class="mt-2 tabular-nums"><span id="rhNow" class="text-2xl font-semibold tracking-tight">–</span> <span class="text-sm text-moss dark:text-emerald-100/70">%</span></p>
                    <p id="rhTime" class="mt-1 text-xs text-moss dark:text-emerald-100/70">–</p>
                </article>
                <article class="glass rounded-3xl p-4">
                    <h2 class="text-sm font-medium text-moss dark:text-emerald-100/70">VPD</h2>
                    <p class="mt-2 tabular-nums"><span id="vpdNow" class="text-2xl font-semibold tracking-tight">–</span> <span class="text-sm text-moss dark:text-emerald-100/70">kPa</span></p>
                    <p id="vpdTime" class="mt-1 text-xs text-moss dark:text-emerald-100/70">–</p>
                </article>
            </section>

            <section aria-label="Grafik timeseries" class="mt-4 grid gap-4">
                <article class="glass rounded-[2rem] p-5">
                    <h2 class="text-lg font-semibold tracking-tight">Kelembapan tanah</h2>
                    <div class="relative mt-4 h-64">
                        <canvas id="soilChart" role="img" aria-label="Grafik kelembapan tanah"></canvas>
                    </div>
                    <p class="mt-3 text-xs text-moss dark:text-emerald-100/70">Garis putus: mulai siram di bawah 60%, berhenti pada 70%.</p>
                </article>
                <div class="grid gap-4 lg:grid-cols-12">
                    <article class="glass rounded-[2rem] p-5 lg:col-span-7">
                        <h2 class="text-lg font-semibold tracking-tight">Suhu dan kelembapan udara</h2>
                        <div class="relative mt-4 h-56">
                            <canvas id="climateChart" role="img" aria-label="Grafik suhu dan kelembapan udara"></canvas>
                        </div>
                    </article>
                    <article class="glass rounded-[2rem] p-5 lg:col-span-5">
                        <h2 class="text-lg font-semibold tracking-tight">VPD udara</h2>
                        <div class="relative mt-4 h-56">
                            <canvas id="vpdChart" role="img" aria-label="Grafik VPD udara"></canvas>
                        </div>
                    </article>
                </div>
                <article class="glass rounded-[2rem] p-5">
                    <div class="flex flex-wrap items-baseline justify-between gap-2">
                        <h2 class="text-lg font-semibold tracking-tight">Durasi siram</h2>
                        <p class="tabular-nums"><span id="irrigTotal" class="text-2xl font-bold">–</span> <span class="text-sm text-moss dark:text-emerald-100/70">mnt</span> <span id="irrigLabel" class="text-xs text-moss dark:text-emerald-100/70">dalam 24 jam</span></p>
                    </div>
                    <div class="relative mt-4 h-48">
                        <canvas id="irrigChart" role="img" aria-label="Grafik durasi siram per jam"></canvas>
                    </div>
                </article>
            </section>
        </div>
    </div>
</body>
</html>
