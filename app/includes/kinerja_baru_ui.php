<?php
/**
 * PENILAIAN KINERJA BARU — komponen tampilan bersama (gaya, filter, status data).
 */

if (!function_exists('kbUrl')) {
    /** URL halaman saat ini dengan parameter filter dasar + tambahan. */
    function kbUrl(array $ctx, array $extra = [], ?string $page = null): string
    {
        $params = ['unit_id' => $ctx['unit_id']];
        if (!empty($ctx['ta_id'])) {
            $params['ta'] = $ctx['ta_id'];
        }
        foreach ($extra as $k => $v) {
            if ($v === null) {
                unset($params[$k]);
            } else {
                $params[$k] = $v;
            }
        }
        $page = $page ?? basename($_SERVER['PHP_SELF'] ?? 'index.php');
        return $page . '?' . http_build_query($params);
    }
}

if (!function_exists('kbIssueLabel')) {
    function kbIssueLabel(string $jenis): string
    {
        return [
            'ganda'           => 'Nilai tercatat ganda',
            'tidak_wajar'     => 'Nilai di luar 1–100',
            'nol'             => 'Nilai 0 (dianggap belum dinilai)',
            'mapel_tidak_ada' => 'Rombel tanpa nilai mapel',
            'keluar'          => 'Santri keluar',
        ][$jenis] ?? $jenis;
    }
}

if (!function_exists('kbAlasanLabel')) {
    function kbAlasanLabel(string $alasan): string
    {
        return [
            'kosong'      => 'belum diisi',
            'nol'         => 'nilai 0',
            'tidak_wajar' => 'nilai tidak wajar',
            'tidak_ada'   => 'tidak ada data',
        ][$alasan] ?? $alasan;
    }
}

if (!function_exists('kbRankBadge')) {
    function kbRankBadge(?int $rank): string
    {
        if ($rank === null) {
            return '<span class="kb-rank kb-rank-none">Belum ada peringkat</span>';
        }
        if ($rank === 1) {
            return '<span class="kb-rank kb-rank-1"><i class="fa-solid fa-crown"></i> Peringkat 1</span>';
        }
        if ($rank === 2) {
            return '<span class="kb-rank kb-rank-2"><i class="fa-solid fa-medal"></i> Peringkat 2</span>';
        }
        if ($rank === 3) {
            return '<span class="kb-rank kb-rank-3"><i class="fa-solid fa-award"></i> Peringkat 3</span>';
        }
        return '<span class="kb-rank">Peringkat ' . (int)$rank . '</span>';
    }
}

if (!function_exists('kbBarClass')) {
    function kbBarClass(?float $v): string
    {
        if ($v === null) {
            return 'kb-bar-none';
        }
        return $v >= 90 ? 'kb-bar-a' : ($v >= 80 ? 'kb-bar-b' : ($v >= KB_BATAS_NILAI ? 'kb-bar-c' : 'kb-bar-d'));
    }
}

if (!function_exists('kbUiStyles')) {
    function kbUiStyles(): void
    {
        ?>
<style>
/* Penilaian Kinerja Baru: tenang, hangat, nyaman dibaca lama */
.kb{--kb-ink:#1f2937;--kb-ink2:#4b5563;--kb-mute:#6b7280;--kb-line:#e6e9f0;--kb-soft:#f7f8fc;--kb-acc:#4f46e5;--kb-acc-soft:#eef0fe;
    --kb-ok:#15803d;--kb-ok-soft:#e8f6ee;--kb-warn:#b45309;--kb-warn-soft:#fdf4e3;--kb-bad:#be123c;--kb-bad-soft:#fdeef0;
    --kb-teal:#0f766e;--kb-teal-soft:#e6f5f3;
    --kb-shadow:0 1px 2px rgba(16,24,40,.04),0 2px 8px rgba(16,24,40,.03);
    color:var(--kb-ink);font-size:14px;line-height:1.55;width:100%;box-sizing:border-box}
.kb *{box-sizing:border-box}
.kb h1,.kb h2,.kb h3{letter-spacing:0}

/* warna aksen lembut (dipakai ikon) */
.kb-t-indigo{--t-bg:#eef0fe;--t-fg:#4f46e5}
.kb-t-teal{--t-bg:#e6f5f3;--t-fg:#0f766e}
.kb-t-green{--t-bg:#e8f6ee;--t-fg:#15803d}
.kb-t-amber{--t-bg:#fdf4e3;--t-fg:#b45309}
.kb-t-rose{--t-bg:#fdeef0;--t-fg:#be123c}
.kb-ic{display:inline-flex;align-items:center;justify-content:center;flex-shrink:0;width:38px;height:38px;border-radius:11px;background:var(--t-bg,#eef0fe);color:var(--t-fg,#4f46e5);font-size:15px}

/* kartu */
.kb-card{background:#fff;border:1px solid var(--kb-line)!important;border-radius:14px;padding:18px 20px;margin-bottom:16px;box-shadow:var(--kb-shadow)}
.kb-card.kb-top{box-shadow:inset 0 3px 0 #e3a13b,var(--kb-shadow)}
.kb-card h3{font-weight:700!important}

/* kepala halaman dengan motif titik halus */
.kb-hero{position:relative;overflow:hidden}
.kb-hero::after{content:"";position:absolute;right:0;top:0;width:260px;height:100%;pointer-events:none;
    background-image:radial-gradient(rgba(79,70,229,.16) 1.1px,transparent 1.3px);background-size:14px 14px;
    -webkit-mask-image:linear-gradient(to left,#000 0%,transparent 85%);mask-image:linear-gradient(to left,#000 0%,transparent 85%)}
.kb-hero>*{position:relative;z-index:1}
.kb-head{display:flex;justify-content:space-between;align-items:flex-start;gap:16px;flex-wrap:wrap}
.kb-head>div{min-width:0}
.kb-tag{display:none!important}
.kb-titlebox{flex:1 1 360px;display:flex;gap:14px;align-items:flex-start}
.kb-titlebox .kb-ic{width:44px;height:44px;border-radius:13px;font-size:18px}
.kb-head h1{margin:0 0 2px;font-size:20px;font-weight:700}
.kb-head p{margin:0;color:var(--kb-mute);font-size:13.5px;max-width:640px}
.kb-filter{display:flex;gap:8px;flex-wrap:wrap;align-items:center}
.kb-filter-main{flex:0 1 auto}
.kb .kb-select{width:auto;max-width:100%;border:1px solid var(--kb-line);border-radius:10px;padding:7px 10px;font-size:13.5px;font-weight:500;color:var(--kb-ink);background:#fff;min-height:38px}
.kb-btn{display:inline-flex;align-items:center;gap:7px;border-radius:10px;padding:8px 14px;font-size:13.5px;font-weight:600;text-decoration:none;border:1px solid transparent;cursor:pointer;min-height:38px}
.kb-btn-primary{background:var(--kb-acc);color:#fff!important}
.kb-btn-primary:hover{background:#4338ca}
.kb-btn-ghost{background:#fff;color:var(--kb-ink)!important;border-color:var(--kb-line)}
.kb-btn-ghost:hover{background:var(--kb-soft)}
.kb-status{display:flex;flex-wrap:wrap;gap:6px 10px;margin-top:14px;font-size:12.5px;color:var(--kb-ink2)}
.kb-status>span{display:inline-flex;align-items:center;background:var(--kb-soft);border:1px solid var(--kb-line);border-radius:999px;padding:3px 10px}
.kb-dot{display:inline-block;width:7px;height:7px;border-radius:50%;margin-right:7px}
.kb-dot-live{background:#16a34a;box-shadow:0 0 0 3px rgba(22,163,74,.15)}.kb-dot-cache{background:#6366f1;box-shadow:0 0 0 3px rgba(99,102,241,.15)}.kb-dot-off{background:#dc2626;box-shadow:0 0 0 3px rgba(220,38,38,.15)}

/* navigasi sub-bab */
.kb-nav{display:flex;gap:2px;overflow-x:auto;-webkit-overflow-scrolling:touch;scrollbar-width:none;border-bottom:1px solid var(--kb-line)}
.kb-nav::-webkit-scrollbar{display:none}
.kb nav.kb-nav{padding:0;margin:-6px -4px 18px;background:none;box-shadow:none;height:auto;min-height:0;position:static}
.kb nav.kb-nav a{flex-shrink:0;display:inline-flex;align-items:center;gap:7px;padding:9px 12px 10px;font-size:13.5px;font-weight:500;color:var(--kb-mute);text-decoration:none;white-space:nowrap;border-bottom:2px solid transparent;margin-bottom:-1px;border-radius:0}
.kb nav.kb-nav a i{font-size:12.5px;opacity:.75}
.kb nav.kb-nav a:hover{color:var(--kb-ink)}
.kb nav.kb-nav a.active{color:var(--kb-acc);font-weight:600;border-bottom-color:var(--kb-acc)}
.kb nav.kb-nav a.active i{opacity:1}

/* pesan */
.kb-alert{display:flex;gap:10px;align-items:flex-start;border-radius:12px;padding:11px 14px;margin-bottom:16px;font-size:13.5px;background:var(--kb-soft);border:1px solid var(--kb-line);color:var(--kb-ink2)}
.kb-alert>i{margin-top:3px;flex-shrink:0}
.kb-alert-warn{background:var(--kb-warn-soft);border-color:#f6dfb3;color:#7c4a03}
.kb-alert-bad{background:var(--kb-bad-soft);border-color:#f7c9d1;color:#881337}
.kb-alert-info{background:#f3f4fe;border-color:#dfe2fb;color:#3730a3}
.kb-alert a{color:var(--kb-acc);font-weight:600}

/* kartu ringkasan */
.kb-kpis{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin-bottom:16px}
@media (max-width:1100px){.kb-kpis{grid-template-columns:repeat(2,minmax(0,1fr))}}
.kb-kpi{display:grid;grid-template-columns:auto minmax(0,1fr);column-gap:12px;align-content:start;background:#fff;border:1px solid var(--kb-line)!important;border-radius:14px;padding:14px 16px;box-shadow:var(--kb-shadow)}
.kb-kpi>.kb-ic{grid-row:1 / span 3}
.kb-kpi .l{font-size:12.5px;font-weight:500;color:var(--kb-ink2)}
.kb-kpi .v{font-size:22px;font-weight:700;margin-top:1px;line-height:1.25;overflow-wrap:anywhere;color:var(--kb-ink)!important;font-variant-numeric:tabular-nums}
.kb-kpi .s{font-size:12px;color:var(--kb-mute);margin-top:2px}
.kb-kpi .s a{color:var(--kb-acc)}

.kb-grid2{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px}
@media (max-width:1000px){.kb-grid2{grid-template-columns:minmax(0,1fr)}}
.kb-grid2>*{min-width:0}
.kb-section-title{display:flex;align-items:center;gap:10px;font-size:15px;font-weight:700;margin:0 0 4px}
.kb-section-title .kb-ic{width:30px;height:30px;border-radius:9px;font-size:13px}
.kb-section-sub{font-size:12.5px;color:var(--kb-mute);margin:0 0 12px}
.kb-subhead{display:flex;align-items:center;gap:6px;margin:16px 0 4px;font-size:12.5px;font-weight:600;color:var(--kb-ink2)}
.kb-subhead i{font-size:11.5px}

/* peringkat & nilai */
.kb-rank{font-size:12.5px;font-weight:600;color:var(--kb-mute)}
.kb-score{display:inline-block;font-size:20px;font-weight:700;color:#3730a3!important;background:var(--kb-acc-soft)!important;border:none!important;border-radius:10px;padding:2px 10px!important;white-space:nowrap;font-variant-numeric:tabular-nums}
.kb-medal{display:inline-flex;align-items:center;justify-content:center;min-width:26px;height:26px;border-radius:999px;font-size:12.5px;font-weight:600;color:var(--kb-mute);font-variant-numeric:tabular-nums}
.kb-medal-1{background:#fdf1d6;color:#a16207;font-weight:700;box-shadow:inset 0 0 0 1px #f5dc9b}
.kb-medal-2{background:#eef1f5;color:#475569;font-weight:700;box-shadow:inset 0 0 0 1px #d8dee8}
.kb-medal-3{background:#fbeadf;color:#9a3412;font-weight:700;box-shadow:inset 0 0 0 1px #f1cdb6}
.kb-num{min-width:20px;flex-shrink:0;font-size:12.5px;font-weight:500;color:var(--kb-mute);font-variant-numeric:tabular-nums}

.kb-chips{display:flex;flex-wrap:wrap;gap:6px 8px}
.kb-chip{display:inline-flex;gap:6px;align-items:baseline;border:1px solid var(--kb-line);border-radius:999px;padding:3px 11px;font-size:12.5px;background:var(--kb-soft);color:var(--kb-ink2)}
.kb-chip b{color:var(--kb-ink);font-weight:600}
.kb-chip small{color:var(--kb-mute)}

/* daftar mapel dengan bar */
.kb-list{display:flex;flex-direction:column}
.kb-row{padding:8px 0;border-bottom:1px dashed #edf0f5}
.kb-row:last-child{border-bottom:none}
.kb-row-top{display:flex;justify-content:space-between;gap:10px;align-items:center;font-size:13.5px}
.kb-row-top b{font-weight:600;font-variant-numeric:tabular-nums}
.kb-row-name{display:flex;gap:8px;align-items:center;min-width:0}
.kb-row-name span.n{white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.kb-bar{height:6px;background:#eef0f5;border-radius:3px;overflow:hidden;margin-top:6px}
.kb-bar>div{height:100%;border-radius:3px}
.kb-bar-a{background:#3fae84}.kb-bar-b{background:#6f93ec}.kb-bar-c{background:#a3b1c6}.kb-bar-d{background:#e3a13b}.kb-bar-none{background:#e2e8f0}

/* label kecil */
.kb-pill{display:inline-flex;align-items:center;gap:4px;font-size:11.5px;font-weight:500;padding:1px 9px;border-radius:999px;background:#f1f3f8;color:var(--kb-ink2);white-space:nowrap;border:none}
.kb-pill i{font-size:10.5px}
.kb-pill-warn{background:var(--kb-warn-soft);color:var(--kb-warn)}
.kb-pill-bad{background:var(--kb-bad-soft);color:var(--kb-bad)}
.kb-pill-ok{background:var(--kb-ok-soft);color:var(--kb-ok)}

/* tabel */
.kb-table-wrap{width:100%;overflow-x:auto;-webkit-overflow-scrolling:touch;border:1px solid var(--kb-line);border-radius:12px}
.kb .kb-table{width:100%;min-width:0;border-collapse:collapse;font-size:13.5px}
.kb .kb-table th{text-align:left;font-size:12.5px;font-weight:600;color:var(--kb-ink2);padding:9px 12px;border-bottom:1px solid var(--kb-line);background:var(--kb-soft);white-space:nowrap;text-transform:none;letter-spacing:0}
.kb-table td{padding:10px 12px;border-bottom:1px solid #f0f2f7;vertical-align:top}
.kb-table tr:last-child td{border-bottom:none}
.kb-table td.num,.kb-table th.num{text-align:right;white-space:nowrap;font-variant-numeric:tabular-nums}
.kb-table tbody tr:nth-child(even) td{background:#fcfcfe}
.kb-table tbody tr:hover td{background:#f5f6ff}
.kb-table-wrap.kb-tall{max-height:70vh;overflow:auto}
.kb-table-wrap.kb-tall .kb-table th{position:sticky;top:0;z-index:1}
.kb-table .kb-table-wrap,.kb-acc-body.kb-table-wrap{border:none;border-radius:0}
.kb-muted{color:var(--kb-mute)}
.kb-foot{font-size:12.5px;color:var(--kb-mute);margin-top:10px;padding-top:10px;border-top:1px dashed #e6e9f0;display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap}
.kb-foot a{color:var(--kb-acc);font-weight:600;text-decoration:none}
.kb-foot a i{font-size:11px}

/* lipatan */
.kb details.kb-how{margin:4px 0 16px;font-size:12.5px;color:var(--kb-mute)}
.kb details.kb-how summary{cursor:pointer;color:var(--kb-ink2);font-weight:500}
.kb details.kb-how ul{margin:8px 0 0;padding:10px 14px 10px 30px;line-height:1.7;background:var(--kb-soft);border-radius:10px}
.kb details.kb-more{margin-top:6px}
.kb details.kb-more>summary{cursor:pointer;font-size:12.5px;font-weight:600;color:var(--kb-acc);list-style:none;padding:6px 0}
.kb details.kb-more>summary::-webkit-details-marker{display:none}
.kb details.kb-more>summary::after{content:" ▾";font-size:11px}
.kb details.kb-more[open]>summary::after{content:" ▴"}

/* tab pilihan (kelas, rombel, dsb.) */
.kb-tabs{display:flex;gap:6px;flex-wrap:wrap;margin-bottom:12px}
.kb-tab{display:inline-flex;align-items:center;gap:6px;padding:6px 13px;border-radius:999px;border:1px solid var(--kb-line);background:#fff;font-size:13px;font-weight:500;color:var(--kb-ink2);text-decoration:none;white-space:nowrap}
.kb-tab:hover{border-color:#c7cbe8;color:var(--kb-ink)}
.kb-tab.active{background:var(--kb-acc-soft);color:var(--kb-acc);border-color:#d6daf9;font-weight:600}
.kb input.kb-search,.kb input.kb-input,.kb select.kb-input{width:auto!important;padding:7px 11px!important;font-size:13.5px!important;border-radius:10px!important;min-height:38px;height:auto!important;border:1px solid var(--kb-line)!important}
.kb input.kb-search{min-width:220px;padding-left:12px!important}
.kb-click{cursor:pointer}
.kb-empty{text-align:center;padding:26px 12px;color:var(--kb-mute)}
.kb a:focus-visible,.kb button:focus-visible,.kb select:focus-visible,.kb input:focus-visible,.kb summary:focus-visible{outline:2px solid var(--kb-acc);outline-offset:2px}

/* ringkasan peringkat (bar horizontal) */
.kb-rankbars{display:flex;flex-direction:column;gap:2px}
.kb-rankbar{display:grid;grid-template-columns:28px minmax(90px,150px) 1fr 58px;gap:12px;align-items:center;text-decoration:none;color:inherit;padding:6px 6px;border-radius:10px}
.kb-rankbar:hover{background:var(--kb-soft)}
.kb-rankbar .track{height:10px;background:#eef0f5;border-radius:5px;overflow:hidden}
.kb-rankbar .track>i{display:block;height:100%;border-radius:5px;background:#a5b4fc}
.kb-rankbar.top .track>i{background:linear-gradient(90deg,#f2c46b,#e3a13b)}
.kb-rankbar .nm{font-weight:500;font-size:13.5px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.kb-rankbar .vl{text-align:right;font-weight:600;font-variant-numeric:tabular-nums}

.kb-scorebox{text-align:right}
.kb-only-sm{display:none!important}
@media (min-width:641px){.kb .kb-table.kb-wide{min-width:760px}}

/* ---- HP ---- */
@media (max-width:640px){
    div.kb-only-sm{display:block!important}
    span.kb-only-sm{display:inline-flex!important}
    .kb-card{padding:14px;margin-bottom:12px;border-radius:12px}
    .kb-hero::after{width:140px}
    .kb-titlebox{gap:10px}
    .kb-titlebox .kb-ic{width:36px;height:36px;font-size:15px;border-radius:11px}
    .kb-head h1{font-size:18px}
    .kb-head p{font-size:12.5px}
    .kb-filter-main{display:grid;grid-template-columns:minmax(0,.85fr) minmax(0,1.15fr) auto;gap:8px;width:100%}
    .kb-filter-main .kb-select{width:100%;min-width:0;font-size:13px;padding:7px 8px}
    .kb-filter-main .kb-sync{order:1;justify-content:center;padding:7px 11px}
    .kb-filter-main .kb-extra{order:2}
    .kb-btn-label{display:none}
    .kb-status{font-size:11.5px;margin-top:10px}
    .kb-kpis{grid-template-columns:repeat(2,minmax(0,1fr));gap:8px;margin-bottom:12px}
    .kb-kpi{padding:11px 12px;border-radius:12px;column-gap:9px}
    .kb-kpi>.kb-ic{width:30px;height:30px;font-size:12.5px;border-radius:9px}
    .kb-kpi .l{font-size:11.5px}
    .kb-kpi .v{font-size:18px}
    .kb-kpi .s{font-size:11px}
    .kb-tabs{flex-wrap:nowrap;overflow-x:auto;-webkit-overflow-scrolling:touch;padding-bottom:4px}
    .kb-tabs .kb-tab{flex-shrink:0}
    .kb-hide-sm{display:none!important}
    .kb .kb-table{font-size:13px}
    .kb-table th,.kb-table td{padding:8px 8px}
    .kb input.kb-search{width:100%!important;min-width:0}
    .kb-rankbar{grid-template-columns:26px minmax(70px,110px) 1fr 48px;gap:8px}
    .kb-score{font-size:17px}
    .kb-alert{font-size:12.5px}
    .kb-scorebox{text-align:left;width:100%;display:flex;align-items:center;gap:10px;flex-wrap:wrap}
    .kb-scorebox .kb-muted{margin-top:0!important}
    /* tabel bertumpuk: tiap baris jadi kartu, label kolom dari data-label */
    .kb-table-wrap:has(.kb-stack){border:none;border-radius:0}
    .kb-table.kb-stack thead{display:none}
    .kb-table.kb-stack tr{display:block;border:1px solid var(--kb-line);border-radius:12px;margin-bottom:8px;padding:6px 12px;background:#fff;box-shadow:var(--kb-shadow)}
    .kb-table.kb-stack tbody tr:nth-child(even) td{background:transparent}
    .kb-table.kb-stack td{display:flex;justify-content:space-between;gap:12px;align-items:flex-start;border:none;padding:4px 0;text-align:right}
    .kb-table.kb-stack td::before{content:attr(data-label);font-size:12px;color:var(--kb-mute);text-align:left;flex-shrink:0}
    .kb-table.kb-stack td.kb-stack-title{display:block;text-align:left;border-bottom:1px dashed #e6e9f0;padding-bottom:6px;margin-bottom:2px}
    .kb-table.kb-stack td.kb-stack-title::before{content:none}
    .kb-table.kb-stack tr:hover td{background:transparent}
}
</style>
        <?php
    }
}

if (!function_exists('kbUiNav')) {
    /** Navigasi antar sub-bab (hanya yang boleh dibuka pengguna), membawa filter unit & tahun ajaran. */
    function kbUiNav(array $ctx): void
    {
        $pages = [
            'index.php'      => ['kinerja_baru_paralel',    'fa-ranking-star',         'Rata-rata paralel'],
            'mapel.php'      => ['kinerja_baru_mapel',      'fa-arrow-up-right-dots',  'Mapel'],
            'all_data.php'   => ['kinerja_baru_all_data',   'fa-users-viewfinder',     'Data santri'],
            'tahfidz.php'    => ['kinerja_baru_tahfidz',    'fa-book-quran',           'Tahfidz'],
            'tahfidz_ai.php' => ['kinerja_baru_tahfidz_ai', 'fa-pen-nib',              'Catatan guru'],
        ];
        $current = basename($_SERVER['PHP_SELF'] ?? '');
        $base = ['unit_id' => $ctx['unit_id']];
        if (!empty($ctx['ta_id'])) {
            $base['ta'] = $ctx['ta_id'];
        }
        echo '<nav class="kb-nav" aria-label="Sub-bab Penilaian Kinerja Baru">';
        foreach ($pages as $file => [$key, $icon, $label]) {
            if (function_exists('hasMenuAccess') && !hasMenuAccess($key)) {
                continue;
            }
            $active = $file === $current;
            echo '<a href="' . e($file . '?' . http_build_query($base)) . '"' . ($active ? ' class="active" aria-current="page"' : '') . '>'
                . '<i class="fa-solid ' . $icon . '"></i>' . e($label) . '</a>';
        }
        echo '</nav>';
    }
}

if (!function_exists('kbRankDot')) {
    /** Angka peringkat ringkas (emas/perak/perunggu untuk 1–3). */
    function kbRankDot(?int $rank): string
    {
        if ($rank === null) {
            return '<span class="kb-medal" title="Belum ada peringkat">–</span>';
        }
        $cls = $rank <= 3 ? ' kb-medal-' . $rank : '';
        return '<span class="kb-medal' . $cls . '">' . $rank . '</span>';
    }
}

if (!function_exists('kbUiHeader')) {
    /**
     * Kartu judul + filter unit/tahun ajaran + tombol Sinkronkan + status data.
     * Mengembalikan false jika data tidak tersedia (halaman sebaiknya berhenti).
     */
    function kbUiHeader(array $ctx, string $title, string $desc, array $keepParams = [], string $extraFilterHtml = ''): bool
    {
        $refreshUrl = kbUrl($ctx, $keepParams + ['refresh' => '1']);
        ?>
        <?php
        $pageIcons = [
            'index.php'      => ['fa-ranking-star', 'kb-t-indigo'],
            'mapel.php'      => ['fa-book', 'kb-t-teal'],
            'all_data.php'   => ['fa-users', 'kb-t-indigo'],
            'tahfidz.php'    => ['fa-book-quran', 'kb-t-green'],
            'tahfidz_ai.php' => ['fa-pen-nib', 'kb-t-amber'],
        ];
        [$pIcon, $pTint] = $pageIcons[basename($_SERVER['PHP_SELF'] ?? '')] ?? ['fa-chart-column', 'kb-t-indigo'];
        ?>
        <div class="kb-card kb-hero">
            <?php kbUiNav($ctx); ?>
            <div class="kb-head">
                <div class="kb-titlebox">
                    <span class="kb-ic <?= $pTint ?>" aria-hidden="true"><i class="fa-solid <?= $pIcon ?>"></i></span>
                    <div>
                        <h1><?= e($title) ?></h1>
                        <p><?= $desc ?></p>
                    </div>
                </div>
                <form method="GET" class="kb-filter kb-filter-main">
                    <?php foreach ($keepParams as $k => $v):
                        if ($v === null || in_array($k, ['unit_id', 'ta'], true) || strpos($extraFilterHtml, 'name="' . $k . '"') !== false) continue; ?>
                        <input type="hidden" name="<?= e($k) ?>" value="<?= e((string)$v) ?>">
                    <?php endforeach; ?>
                    <select name="unit_id" class="kb-select" onchange="this.form.ta.value='';this.form.submit()" aria-label="Unit">
                        <?php if (empty($ctx['units'])): ?>
                            <option value="<?= (int)$ctx['unit_id'] ?>">Unit #<?= (int)$ctx['unit_id'] ?></option>
                        <?php endif; ?>
                        <?php foreach ($ctx['units'] as $u): ?>
                            <option value="<?= (int)$u['id'] ?>" <?= (int)$u['id'] === (int)$ctx['unit_id'] ? 'selected' : '' ?>>Unit <?= e($u['nama']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <select name="ta" class="kb-select" onchange="this.form.submit()" aria-label="Tahun ajaran">
                        <?php if (empty($ctx['years'])): ?>
                            <option value="">Tahun ajaran terbaru</option>
                        <?php endif; ?>
                        <?php foreach ($ctx['years'] as $y): ?>
                            <option value="<?= (int)$y['id'] ?>" <?= (int)$y['id'] === (int)$ctx['ta_id'] ? 'selected' : '' ?>>
                                <?= e(str_replace(' ', '', (string)$y['nama'])) ?> · Smt <?= (int)$y['semester'] ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?= $extraFilterHtml ?>
                    <a class="kb-btn kb-btn-primary kb-sync" href="<?= e($refreshUrl) ?>" title="Tarik data terbaru langsung dari AIS" aria-label="Sinkronkan data dari AIS">
                        <i class="fa-solid fa-arrows-rotate"></i><span class="kb-btn-label"> Sinkronkan</span>
                    </a>
                </form>
            </div>
            <?php if ($ctx['fetched_at']): ?>
                <div class="kb-status">
                    <span title="Data diambil dari AIS dan disimpan 10 menit">
                        <span class="kb-dot <?= $ctx['source'] === 'offline_cache' ? 'kb-dot-off' : ($ctx['source'] === 'live' ? 'kb-dot-live' : 'kb-dot-cache') ?>"></span><?= $ctx['source'] === 'offline_cache' ? 'AIS tidak terhubung, data terakhir ' : 'Data AIS ' ?><?= e(date('d-m-Y H:i', (int)$ctx['fetched_at'])) ?>
                    </span>
                    <?php if ($ctx['a']): ?>
                        <span>PAS terisi <?= kbFmt($ctx['a']['unit']['kelengkapan'], 1) ?>%</span>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>

        <?php if (!$ctx['ok']): ?>
            <div class="kb-alert kb-alert-bad">
                <i class="fa-solid fa-plug-circle-xmark"></i>
                <div><b>Data belum bisa diambil dari AIS.</b> <?= e((string)$ctx['error']) ?></div>
            </div>
            <?php return false; ?>
        <?php endif; ?>

        <?php if ($ctx['source'] === 'offline_cache'): ?>
            <div class="kb-alert kb-alert-bad">
                <i class="fa-solid fa-triangle-exclamation"></i>
                <div>AIS sedang tidak terhubung. Yang tampil adalah data <?= e(kbAge((int)$ctx['fetched_at'])) ?>.</div>
            </div>
        <?php endif; ?>

        <?php if ($ctx['a'] && $ctx['a']['unit']['kelengkapan'] < KB_AMBANG_KELENGKAPAN && $ctx['a']['unit']['santri'] > 0): ?>
            <div class="kb-alert kb-alert-warn">
                <i class="fa-solid fa-hourglass-half"></i>
                <div>Nilai PAS baru terisi <?= kbFmt($ctx['a']['unit']['kelengkapan'], 1) ?>%. Angka masih bisa berubah sampai nilai lengkap.</div>
            </div>
        <?php endif; ?>

        <?php if ($ctx['a'] && $ctx['a']['unit']['santri'] === 0): ?>
            <div class="kb-alert kb-alert-info">
                <i class="fa-solid fa-circle-info"></i>
                <div>Belum ada nilai PAS untuk unit dan tahun ajaran ini.</div>
            </div>
            <?php return false; ?>
        <?php endif; ?>
        <?php
        return true;
    }
}

if (!function_exists('kbUiHowItWorks')) {
    function kbUiHowItWorks(): void
    {
        ?>
        <details class="kb-how">
            <summary>Cara menghitung</summary>
            <ul>
                <li>Hanya nilai <b>PAS</b> yang sudah terisi yang dihitung. Nilai <b>0</b> dianggap belum dinilai; nilai di luar 1–100 tidak dihitung.</li>
                <li>Jika satu santri punya dua nilai untuk mapel yang sama, yang dipakai adalah yang <b>terakhir diubah</b>.</li>
                <li><b>Mapel wajib</b> sebuah rombel = mapel yang sudah dinilai untuk minimal separuh santri rombel itu.</li>
                <li>Santri <b>lengkap</b> = punya nilai di semua mapel wajib rombelnya. Hanya santri lengkap yang diberi peringkat.</li>
                <li>Rata-rata santri = rata-rata nilai mapel wajibnya. Rata-rata rombel, paralel, dan unit = rata-rata dari rata-rata santri lengkap (setiap santri berbobot sama).</li>
                <li>Rata-rata mapel = rata-rata semua nilai sah mapel itu. Nilai yang sama persis mendapat peringkat yang sama.</li>
                <li>Santri yang keluar atau sudah dihapus di AIS tidak dihitung.</li>
            </ul>
        </details>
        <?php
    }
}
