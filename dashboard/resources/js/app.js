import Chart from 'chart.js/auto';

const LATEST_MS = 5000;
const HISTORY_MS = 60000;
const STALE_MS = 5 * 60 * 1000;
const RING_C = 314.159;

const PALETTES = {
    light: { leaf: '#2e7d46', clay: '#a06a35', amber: '#b45309', idle: '#e3e1dc', tick: '#4c5f53', grid: 'rgba(30, 43, 35, 0.06)', band: 'rgba(46, 125, 70, 0.12)', area: 'rgba(46, 125, 70, 0.10)' },
    dark: { leaf: '#7fd6a4', clay: '#d29a5b', amber: '#f0a832', idle: '#3a4640', tick: '#9db8a6', grid: 'rgba(255, 255, 255, 0.08)', band: 'rgba(127, 214, 164, 0.20)', area: 'rgba(127, 214, 164, 0.14)' },
};
const pal = () => (document.documentElement.classList.contains('dark') ? PALETTES.dark : PALETTES.light);

let LEAF = PALETTES.light.leaf;
let CLAY = PALETTES.light.clay;
let AMBER = PALETTES.light.amber;
let BAR_IDLE = PALETTES.light.idle;
let GRID = PALETTES.light.grid;
let BAND = PALETTES.light.band;
let AREA = PALETTES.light.area;
let lastData = null;

function applyPalette() {
    const p = pal();
    LEAF = p.leaf;
    CLAY = p.clay;
    AMBER = p.amber;
    BAR_IDLE = p.idle;
    GRID = p.grid;
    BAND = p.band;
    AREA = p.area;
    Chart.defaults.color = p.tick;
}

Chart.defaults.font.family = "'Instrument Sans', ui-sans-serif, system-ui, sans-serif";
Chart.defaults.animation = false;
applyPalette();

const state = { minutes: 1440, label: '24 jam', charts: {}, rows: null, latest: null };

const $ = (id) => document.getElementById(id);

const fmtClock = new Intl.DateTimeFormat('id-ID', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
const fmtShort = new Intl.DateTimeFormat('id-ID', { hour: '2-digit', minute: '2-digit' });
const fmtLong = new Intl.DateTimeFormat('id-ID', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' });
const fmtHour = new Intl.DateTimeFormat('id-ID', { hour: '2-digit', minute: '2-digit' });

const fmtLabel = (iso) => (state.minutes <= 1440 ? fmtShort : fmtLong).format(new Date(iso));
const fmtTime = (iso) => fmtClock.format(new Date(iso));

function setStatus(text) {
    $('statusLine').textContent = text;
}

function show(name) {
    $('dataSections').classList.toggle('hidden', name !== 'data');
    $('emptyState').classList.toggle('hidden', name !== 'empty');
    $('retryButton').classList.toggle('hidden', name !== 'error');
}

async function getJSON(url) {
    const res = await fetch(url, { headers: { Accept: 'application/json' } });
    if (!res.ok) {
        throw new Error('HTTP ' + res.status);
    }

    return res.json();
}

function verdict(soil) {
    if (soil === null || soil === undefined) {
        return 'Menunggu data…';
    }
    if (soil < 60) {
        return 'Kering';
    }
    if (soil >= 70) {
        return 'Basah';
    }

    return 'Normal';
}

function renderLatest(d) {
    state.latest = d.has_data ? d : null;
    if (!d.has_data) {
        for (const id of ['soilNow', 'suhuNow', 'rhNow', 'vpdNow', 'pumpNow', 'irrigTotal']) {
            $(id).textContent = '–';
        }
        setStatus('Belum ada data.');
        show('empty');

        return;
    }

    $('deviceBadge').textContent = 'Perangkat: ' + (d.device ?? 'tak dikenal');
    $('soilNow').textContent = d.soil ?? '–';
    $('soilVerdict').textContent = verdict(d.soil);
    $('soilVerdict').classList.toggle('zone-dry', d.soil !== null && d.soil !== undefined && d.soil < 60);
    $('soilRing').setAttribute('stroke-dashoffset', d.soil === null || d.soil === undefined ? RING_C : (RING_C * (1 - Math.min(Math.max(d.soil, 0), 100) / 100)).toFixed(1));
    $('soilRingImg').setAttribute('aria-label', 'Lingkaran kelembaban tanah, ' + (d.soil ?? 'tidak ada') + ' persen.');
    $('soilRingTime').textContent = d.soil_at ? fmtTime(d.soil_at) : '–';
    $('suhuNow').textContent = d.suhu ?? '–';
    $('rhNow').textContent = d.rh ?? '–';
    $('vpdNow').textContent = d.vpd ?? '–';
    $('suhuTime').textContent = d.soil_at ? fmtTime(d.soil_at) : '–';
    $('rhTime').textContent = d.soil_at ? fmtTime(d.soil_at) : '–';
    $('vpdTime').textContent = d.vpd_at ? fmtTime(d.vpd_at) : '–';
    $('pumpTime').textContent = d.pompa_at ? fmtTime(d.pompa_at) : '–';

    const pumpKnown = d.pompa !== null && d.pompa !== undefined;
    $('pumpCard').classList.toggle('pump-on', pumpKnown && d.pompa);
    $('pumpNow').textContent = !pumpKnown ? '–' : (d.pompa ? 'Menyala' : 'Mati');

    const stamps = [d.soil_at, d.pompa_at, d.vpd_at].filter(Boolean).map((t) => new Date(t).getTime());
    const newest = stamps.length ? Math.max(...stamps) : null;
    const age = newest ? Date.now() - newest : null;
    if (age === null || age > STALE_MS) {
        const mins = age === null ? null : Math.floor(age / 60000);
        setStatus(mins === null ? 'Belum ada data.' : 'Data terakhir ' + mins + ' menit lalu, periksa ESP.');
    } else {
        setStatus(state.label + ' terakhir, diperbarui ' + fmtTime(newest));
    }
}

function baseScales(extra) {
    const scales = Object.assign({
        x: {
            ticks: { maxTicksLimit: 8, maxRotation: 0, font: { size: 11 } },
            grid: { color: GRID },
        },
    }, extra);
    for (const key of Object.keys(scales)) {
        scales[key].border = { display: false };
    }

    return scales;
}

function makeChart(id, config) {
    if (state.charts[id]) {
        state.charts[id].destroy();
    }
    state.charts[id] = new Chart($(id), config);
}

function bucketHours(rows) {
    const buckets = new Map();
    let total = 0;
    for (let i = 0; i < rows.length; i++) {
        if (rows[i].pompa !== true) {
            continue;
        }
        const t = new Date(rows[i].t).getTime();
        const next = i + 1 < rows.length ? new Date(rows[i + 1].t).getTime() : t + 5000;
        const mins = Math.min(Math.max(next - t, 0), 30000) / 60000;
        total += mins;
        const d = new Date(t);
        d.setMinutes(0, 0, 0);
        const key = d.getTime();
        buckets.set(key, (buckets.get(key) || 0) + mins);
    }
    const keys = [...buckets.keys()].sort((a, b) => a - b);
    const fmt = state.minutes <= 1440 ? fmtHour : fmtLong;

    return { labels: keys.map((k) => fmt.format(new Date(k))), data: keys.map((k) => Math.round(buckets.get(k) * 100) / 100), total };
}

function renderHistory(d) {
    if (!d.rows.length) {
        show('empty');
        setStatus('Belum ada data.');

        return;
    }
    show('data');
    lastData = d;
    state.rows = d.rows;
    paintCharts(d.rows);
}

function paintCharts(rows) {
    const labels = rows.map((r) => fmtLabel(r.t));
    const soil = rows.map((r) => r.soil);
    const suhu = rows.map((r) => r.suhu);
    const rh = rows.map((r) => r.rh);
    const vpd = rows.map((r) => r.vpd);
    const flat = (v) => labels.map(() => v);

    makeChart('soilChart', {
        type: 'line',
        data: {
            labels,
            datasets: [
                { data: flat(70), borderColor: LEAF, borderWidth: 1.5, borderDash: [6, 4], pointRadius: 0, showInLegend: false },
                { data: flat(60), borderColor: AMBER, borderWidth: 1.5, borderDash: [6, 4], pointRadius: 0, fill: '-1', backgroundColor: BAND, showInLegend: false },
                { label: 'Kelembaban tanah (%)', data: soil, borderColor: CLAY, borderWidth: 2, pointRadius: 0, tension: 0.25 },
            ],
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                tooltip: { mode: 'index', intersect: false, filter: (item) => item.dataset.showInLegend !== false },
            },
            scales: baseScales({ y: { min: 0, max: 100, title: { display: true, text: 'persen (%)' } } }),
        },
    });

    makeChart('climateChart', {
        type: 'line',
        data: {
            labels,
            datasets: [
                { label: 'Suhu (°C)', data: suhu, borderColor: CLAY, borderWidth: 2, pointRadius: 0, tension: 0.25, yAxisID: 'y' },
                { label: 'RH (%)', data: rh, borderColor: LEAF, borderWidth: 2, pointRadius: 0, tension: 0.25, yAxisID: 'y1' },
            ],
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { tooltip: { mode: 'index', intersect: false } },
            scales: baseScales({
                y: { suggestedMin: 15, suggestedMax: 40, title: { display: true, text: 'derajat (°C)' } },
                y1: { position: 'right', min: 0, max: 100, grid: { drawOnChartArea: false }, title: { display: true, text: 'persen (%)' } },
            }),
        },
    });

    makeChart('vpdChart', {
        type: 'line',
        data: {
            labels,
            datasets: [
                { label: 'VPD (kPa)', data: vpd, borderColor: LEAF, borderWidth: 2, pointRadius: 0, stepped: true, fill: 'origin', backgroundColor: AREA },
            ],
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { display: false }, tooltip: { mode: 'index', intersect: false } },
            scales: baseScales({ y: { suggestedMin: 0, suggestedMax: 3, title: { display: true, text: 'kPa' } } }),
        },
    });

    const irrig = bucketHours(rows);
    $('irrigTotal').textContent = irrig.total.toLocaleString('id-ID', { maximumFractionDigits: 1 });
    $('irrigLabel').textContent = 'dalam ' + state.label;
    makeChart('irrigChart', {
        type: 'bar',
        data: {
            labels: irrig.labels,
            datasets: [
                {
                    label: 'Menit siram',
                    data: irrig.data,
                    backgroundColor: irrig.data.map((v) => (v > 0 ? LEAF : BAR_IDLE)),
                    borderRadius: 4,
                    barPercentage: 0.65,
                },
            ],
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                tooltip: { callbacks: { label: (item) => ' ' + item.parsed.y + ' mnt' } },
            },
            scales: baseScales({ y: { title: { display: true, text: 'mnt' } } }),
        },
    });

    const last = (arr) => [...arr].reverse().find((v) => v !== null && v !== undefined);
    $('soilChart').setAttribute('aria-label', 'Grafik kelembaban tanah ' + state.label + ' terakhir, nilai terakhir ' + (last(soil) ?? 'tidak ada') + ' persen.');
    $('climateChart').setAttribute('aria-label', 'Grafik suhu dan kelembapan udara ' + state.label + ' terakhir.');
    $('vpdChart').setAttribute('aria-label', 'Grafik VPD ' + state.label + ' terakhir, nilai terakhir ' + (last(vpd) ?? 'tidak ada') + ' kPa.');
    $('irrigChart').setAttribute('aria-label', 'Grafik durasi siram per jam ' + state.label + ' terakhir, total ' + irrig.total.toLocaleString('id-ID', { maximumFractionDigits: 1 }) + ' menit.');
}

async function refreshLatest() {
    try {
        renderLatest(await getJSON('/api/readings/latest'));
    } catch (err) {
        setStatus('Gagal memperbarui angka (' + (err instanceof Error ? err.message : 'tidak diketahui') + ').');
    }
}

async function refreshHistory() {
    try {
        renderHistory(await getJSON('/api/readings/history?minutes=' + state.minutes));
    } catch (err) {
        show('error');
        setStatus('Gagal memuat grafik (' + (err instanceof Error ? err.message : 'tidak diketahui') + ').');
    }
}

document.querySelectorAll('[data-minutes]').forEach((btn) => {
    btn.addEventListener('click', () => {
        state.minutes = Number(btn.dataset.minutes);
        state.label = btn.textContent.trim();
        document.querySelectorAll('[data-minutes]').forEach((b) => b.setAttribute('aria-pressed', String(b === btn)));
        setStatus('Memuat data sensor…');
        refreshHistory();
    });
});

$('refreshButton').addEventListener('click', () => {
    setStatus('Memuat data sensor…');
    refreshHistory();
    refreshLatest();
});

$('retryButton').addEventListener('click', () => {
    setStatus('Memuat data sensor…');
    refreshHistory();
    refreshLatest();
});

function preferredDark() {
    try {
        const saved = localStorage.getItem('pp-theme');
        if (saved) {
            return saved === 'dark';
        }
    } catch {
        /* fall through to OS preference */
    }

    return window.matchMedia('(prefers-color-scheme: dark)').matches;
}

function applyTheme(dark) {
    document.documentElement.classList.toggle('dark', dark);
    $('themeToggle').setAttribute('aria-pressed', String(dark));
    $('iconMoon').classList.toggle('hidden', dark);
    $('iconSun').classList.toggle('hidden', !dark);
    try {
        localStorage.setItem('pp-theme', dark ? 'dark' : 'light');
    } catch {
        /* keep theme in memory only */
    }
    applyPalette();
    if (lastData && lastData.rows && lastData.rows.length) {
        renderHistory(lastData);
    }
}

$('themeToggle').addEventListener('click', () => {
    applyTheme(!document.documentElement.classList.contains('dark'));
});

applyTheme(preferredDark());

function num(value) {
    return value === null || value === undefined || value === '' || Number.isNaN(Number(value)) ? null : Number(value);
}

function onLivePoint(point) {
    const row = {
        t: point.t ?? new Date().toISOString(),
        device: point.device ?? null,
        soil: num(point.soil),
        suhu: num(point.suhu),
        rh: num(point.rh),
        pompa: point.pompa ?? null,
        vpd: num(point.vpd),
    };

    if (state.latest) {
        const live = state.latest;
        if (row.soil !== null || row.suhu !== null || row.rh !== null) {
            if (row.device !== null) {
                live.device = row.device;
            }
            if (row.soil !== null) {
                live.soil = row.soil;
            }
            if (row.suhu !== null) {
                live.suhu = row.suhu;
            }
            if (row.rh !== null) {
                live.rh = row.rh;
            }
            live.soil_at = row.t;
        }
        if (row.pompa !== null && (!live.pompa_at || row.t >= live.pompa_at)) {
            live.pompa = Boolean(row.pompa);
            live.pompa_at = row.t;
        }
        if (row.vpd !== null) {
            live.vpd = row.vpd;
            live.vpd_at = row.t;
        }
        renderLatest(live);
    } else {
        refreshLatest();
        refreshHistory();
    }

    if (state.rows) {
        state.rows.push(row);
        const cutoff = Date.now() - state.minutes * 60 * 1000;
        state.rows = state.rows.filter((r) => new Date(r.t).getTime() >= cutoff);
        if (state.rows.length > 2000) {
            state.rows = state.rows.slice(-2000);
        }
        paintCharts(state.rows);
    }
}

import('./echo')
    .then(({ default: echo }) => {
        echo.channel('readings')
            .listen('.sensor.received', onLivePoint)
            .listen('.status.received', onLivePoint);
    })
    .catch(() => {});

refreshHistory();
refreshLatest();
setInterval(refreshLatest, LATEST_MS);
setInterval(refreshHistory, HISTORY_MS);
