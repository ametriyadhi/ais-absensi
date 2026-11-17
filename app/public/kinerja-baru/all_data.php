<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../config/security.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/kinerja_baru.php';
require_once __DIR__ . '/../../includes/kinerja_baru_ui.php';

requireLogin();
requireMenuAccess('kinerja_baru_all_data');

$pageTitle = 'Kinerja Baru · Data santri';
$ctx = kbLoadPage($pdo, $_GET);
$a = $ctx['a'];

// ---------- Pilihan paralel & rombel ----------
$paralel = null;
$rombelSel = isset($_GET['r']) ? (int)$_GET['r'] : 0;
if ($a && !empty($a['paralel'])) {
    $pReq = isset($_GET['p']) ? (int)$_GET['p'] : 0;
    $paralel = $a['paralel'][$pReq] ?? reset($a['paralel']);
    $rombelIds = array_column($paralel['rombel'], 'id');
    if (!in_array($rombelSel, $rombelIds, true)) {
        $rombelSel = 0;
    }
}

$santriAll = [];
$santriLengkap = [];
$santriKurang = [];
$mapelView = [];
$mapelNoScore = [];
$issues = [];
if ($paralel) {
    foreach ($paralel['santri_list'] as $s) {
        if ($rombelSel && $s['rombel_id'] !== $rombelSel) {
            continue;
        }
        $santriAll[] = $s;
    }
    // Peringkat di dalam pilihan (paralel atau rombel); peringkat paralel disimpan terpisah
    foreach ($santriAll as &$s) {
        $s['peringkat_paralel'] = $s['peringkat'];
    }
    unset($s);
    $santriAll = kbAssignRanks($santriAll, 'rata_rata');
    foreach ($santriAll as $s) {
        if ($s['lengkap']) {
            $santriLengkap[] = $s;
        } else {
            $santriKurang[] = $s;
        }
    }

    // Statistik mapel untuk pilihan saat ini
    $rombelName = null;
    foreach ($paralel['rombel'] as $r) {
        if ($r['id'] === $rombelSel) {
            $rombelName = $r['nama'];
        }
    }
    foreach ($paralel['mapel'] as $m) {
        $list = $m['santri_nilai'];
        if ($rombelName !== null) {
            $list = array_values(array_filter($list, static fn($x) => $x['rombel'] === $rombelName));
            $list = kbAssignRanks($list, 'nilai');
        }
        if (!$list) {
            $mapelNoScore[] = $m['nama'];   // belum ada nilai PAS sama sekali di pilihan ini
            continue;
        }
        $stats = kbScoreStats(array_column($list, 'nilai'));
        $mapelView[] = array_merge(['id' => $m['id'], 'nama' => $m['nama'], 'wajib' => $m['wajib'], 'santri_nilai' => $list], $stats);
    }
    $mapelView = kbAssignRanks($mapelView, 'rata_rata');

    foreach ($a['masalah'] as $is) {
        if ($is['paralel_id'] === $paralel['id'] && ($rombelName === null || $is['rombel'] === $rombelName)) {
            $issues[] = $is;
        }
    }
}

// ---------- Ekspor CSV (Excel) ----------
if ($paralel && ($_GET['export'] ?? '') === 'csv') {
    $mapelCols = $paralel['mapel'];
    usort($mapelCols, static fn($x, $y) => strcmp($x['nama'], $y['nama']));
    $fname = 'kinerja-baru_' . preg_replace('/[^A-Za-z0-9]+/', '-', ($ctx['unit']['nama'] ?? 'unit') . '_' . $paralel['nama'] . '_' . ($ctx['ta']['nama'] ?? '') . '_smt' . ($ctx['ta']['semester'] ?? '')) . '.csv';
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $fname . '"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    $num = static fn($v) => $v === null ? '' : number_format((float)$v, 2, ',', '');
    $head = ['Peringkat', 'Nama', 'NIS', 'Rombel', 'Status', 'Rata-rata', 'Jumlah mapel'];
    foreach ($mapelCols as $mc) {
        $head[] = $mc['nama'];
    }
    fputcsv($out, $head, ';');
    foreach ($santriAll as $s) {
        $row = [$s['peringkat'] ?? '', $s['nama'], $s['nis'], $s['rombel'], $s['lengkap'] ? 'Lengkap' : 'Belum lengkap',
            $num($s['lengkap'] ? $s['rata_rata'] : $s['rata_sementara']), $s['jumlah_mapel']];
        foreach ($mapelCols as $mc) {
            $row[] = $num($s['nilai'][$mc['id']] ?? null);
        }
        fputcsv($out, $row, ';');
    }
    fclose($out);
    exit;
}

require_once __DIR__ . '/../../includes/header.php';
kbUiStyles();
$keep = $paralel ? ['p' => $paralel['id'], 'r' => $rombelSel ?: null] : [];
?>
<div class="kb">
<?php if (kbUiHeader($ctx, 'Data santri',
    'Peringkat santri per kelas dan nilai tiap mata pelajaran.', $keep) && $paralel):
    $selAvg = kbMean(array_column($santriLengkap, 'rata_rata'));
    $topS = $santriLengkap[0] ?? null;
    $lowS = $santriLengkap ? $santriLengkap[count($santriLengkap) - 1] : null;
?>
    <div class="kb-tabs">
        <?php foreach ($a['paralel'] as $p): ?>
            <a class="kb-tab <?= $p['id'] === $paralel['id'] ? 'active' : '' ?>" href="<?= e(kbUrl($ctx, ['p' => $p['id']])) ?>"><?= e($p['nama']) ?></a>
        <?php endforeach; ?>
    </div>
    <div class="kb-tabs">
        <a class="kb-tab <?= $rombelSel === 0 ? 'active' : '' ?>" href="<?= e(kbUrl($ctx, ['p' => $paralel['id']])) ?>">Semua rombel</a>
        <?php foreach ($paralel['rombel'] as $r): ?>
            <a class="kb-tab <?= $r['id'] === $rombelSel ? 'active' : '' ?>" href="<?= e(kbUrl($ctx, ['p' => $paralel['id'], 'r' => $r['id']])) ?>"><?= e($r['nama']) ?></a>
        <?php endforeach; ?>
    </div>

    <div class="kb-kpis">
        <div class="kb-kpi">
            <span class="kb-ic kb-t-indigo" aria-hidden="true"><i class="fa-solid fa-users"></i></span><div class="l">Santri</div>
            <div class="v"><?= count($santriAll) ?></div>
            <div class="s"><?= count($santriLengkap) ?> lengkap · <?= count($santriKurang) ?> belum lengkap</div>
        </div>
        <div class="kb-kpi">
            <span class="kb-ic kb-t-teal" aria-hidden="true"><i class="fa-solid fa-chart-simple"></i></span><div class="l">Rata-rata <?= $rombelSel ? 'rombel' : 'paralel' ?></div>
            <div class="v" style="color:var(--kb-ok)"><?= kbFmt($selAvg) ?></div>
            <div class="s">rata-rata dari rata-rata santri lengkap</div>
        </div>
        <div class="kb-kpi">
            <span class="kb-ic kb-t-amber" aria-hidden="true"><i class="fa-solid fa-star"></i></span><div class="l">Rata-rata tertinggi</div>
            <div class="v" style="font-size:16px"><?= e($topS['nama'] ?? '-') ?></div>
            <div class="s"><?= kbFmt($topS['rata_rata'] ?? null) ?> · <?= e($topS['rombel'] ?? '') ?></div>
        </div>
        <div class="kb-kpi">
            <span class="kb-ic kb-t-rose" aria-hidden="true"><i class="fa-solid fa-arrow-down"></i></span><div class="l">Rata-rata terendah</div>
            <div class="v" style="font-size:16px"><?= e($lowS['nama'] ?? '-') ?></div>
            <div class="s"><?= kbFmt($lowS['rata_rata'] ?? null) ?> · <?= e($lowS['rombel'] ?? '') ?></div>
        </div>
    </div>


    <!-- BAGIAN 1: PERINGKAT SANTRI -->
    <div class="kb-card">
        <div class="kb-head" style="align-items:center;margin-bottom:12px">
            <div>
                <h3 class="kb-section-title"><span class="kb-ic kb-t-amber" aria-hidden="true"><i class="fa-solid fa-ranking-star"></i></span>Peringkat santri <?= e($paralel['nama']) ?><?= $rombelSel ? ' · ' . e($rombelName) : '' ?></h3>
                <p class="kb-section-sub" style="margin:0">Hanya santri yang nilainya lengkap.</p>
            </div>
            <div class="kb-filter">
                <input type="search" class="kb-search" placeholder="Cari nama / NIS…" oninput="kbFilter(this.value,'kbSantri')">
                <a class="kb-btn kb-btn-ghost" href="<?= e(kbUrl($ctx, ['p' => $paralel['id'], 'r' => $rombelSel ?: null, 'export' => 'csv'])) ?>"><i class="fa-solid fa-file-excel" style="color:#16a34a"></i> Ekspor Excel</a>
            </div>
        </div>
        <div class="kb-table-wrap kb-tall">
            <table class="kb-table" id="kbSantri">
                <thead><tr>
                    <th class="num">#</th><?php if ($rombelSel): ?><th class="num kb-hide-sm">Di paralel</th><?php endif; ?>
                    <th>Nama</th><th class="kb-hide-sm">NIS</th><th class="kb-hide-sm">Rombel</th><th class="num">Rata-rata</th><th class="kb-hide-sm">Predikat</th><th class="num kb-hide-sm">Mapel</th>
                </tr></thead>
                <tbody>
                <?php foreach ($santriLengkap as $s): ?>
                    <tr data-q="<?= e(mb_strtolower($s['nama'] . ' ' . $s['nis'])) ?>">
                        <td class="num"><?= kbRankDot($s['peringkat']) ?></td>
                        <?php if ($rombelSel): ?><td class="num kb-muted kb-hide-sm"><?= (int)$s['peringkat_paralel'] ?></td><?php endif; ?>
                        <td><b><?= e($s['nama']) ?></b><div class="kb-muted kb-only-sm" style="font-size:11.5px"><?= e($s['rombel']) ?><?= $s['nis'] ? ' · ' . e($s['nis']) : '' ?></div></td>
                        <td class="kb-muted kb-hide-sm"><?= e($s['nis'] ?: '-') ?></td>
                        <td class="kb-hide-sm"><?= e($s['rombel']) ?></td>
                        <td class="num"><b><?= kbFmt($s['rata_rata']) ?></b> <span class="kb-pill kb-only-sm"><?= kbPredikat($s['rata_rata']) ?></span></td>
                        <td class="kb-hide-sm"><span class="kb-pill"><?= kbPredikat($s['rata_rata']) ?></span></td>
                        <td class="num kb-hide-sm"><?= (int)$s['jumlah_mapel'] ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$santriLengkap): ?>
                    <tr><td colspan="8" class="kb-empty">Belum ada santri dengan nilai lengkap.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php if ($santriKurang): ?>
    <div class="kb-card" style="border-color:#fde68a">
        <h3 class="kb-section-title"><span class="kb-ic kb-t-amber" aria-hidden="true"><i class="fa-solid fa-hourglass-half"></i></span>Belum lengkap (<?= count($santriKurang) ?>)</h3>
        <p class="kb-section-sub">Belum diberi peringkat sampai semua mapel wajib terisi.</p>
        <div class="kb-table-wrap">
            <table class="kb-table">
                <thead><tr><th>Nama</th><th class="kb-hide-sm">Rombel</th><th>Mapel yang kurang</th><th class="num kb-hide-sm">Rata-rata sementara</th></tr></thead>
                <tbody>
                <?php foreach ($santriKurang as $s): ?>
                    <tr>
                        <td><b><?= e($s['nama']) ?></b><div class="kb-muted" style="font-size:12px"><?= e($s['rombel']) ?><?= $s['nis'] ? ' · ' . e($s['nis']) : '' ?></div></td>
                        <td class="kb-hide-sm"><?= e($s['rombel']) ?></td>
                        <td><?php foreach ($s['kurang'] as $k): ?>
                            <div><?= e($k['mapel']) ?> <span class="kb-pill <?= $k['alasan'] === 'kosong' || $k['alasan'] === 'tidak_ada' ? '' : 'kb-pill-bad' ?>"><?= e(kbAlasanLabel($k['alasan'])) ?></span></div>
                        <?php endforeach; ?></td>
                        <td class="num kb-muted kb-hide-sm"><?= kbFmt($s['rata_sementara']) ?> <span style="font-size:11.5px">(<?= (int)$s['jumlah_mapel'] ?> mapel)</span></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <!-- BAGIAN 2: MATA PELAJARAN -->
    <div class="kb-card">
        <h3 class="kb-section-title"><span class="kb-ic kb-t-indigo" aria-hidden="true"><i class="fa-solid fa-book-open"></i></span>Mata pelajaran</h3>
        <p class="kb-section-sub">Klik mapel untuk melihat nilai per santri.</p>
        <div class="kb-table-wrap">
            <table class="kb-table">
                <thead><tr>
                    <th class="num">#</th><th>Mata pelajaran</th><th class="num">Rata-rata</th><th class="num kb-hide-sm">Jumlah nilai</th>
                    <th class="num kb-hide-sm">Terendah</th><th class="num kb-hide-sm">Tertinggi</th><th class="num">Santri &lt; <?= kbFmt(KB_BATAS_NILAI, 0) ?></th><th></th>
                </tr></thead>
                <tbody>
                <?php foreach ($mapelView as $i => $m): ?>
                    <tr class="kb-click" onclick="kbToggle('kbM<?= $i ?>', this)" aria-expanded="false">
                        <td class="num"><?= kbRankDot($m['peringkat']) ?></td>
                        <td><b><?= e($m['nama']) ?></b> <?php if (!$m['wajib']): ?><span class="kb-pill">sebagian</span><?php endif; ?></td>
                        <td class="num"><b><?= kbFmt($m['rata_rata']) ?></b></td>
                        <td class="num kb-hide-sm"><?= (int)$m['jumlah_nilai'] ?></td>
                        <td class="num kb-hide-sm"><?= kbFmt($m['terendah'], 0) ?></td>
                        <td class="num kb-hide-sm"><?= kbFmt($m['tertinggi'], 0) ?></td>
                        <td class="num"><?= $m['di_bawah_batas'] > 0 ? '<span class="kb-pill kb-pill-warn">' . (int)$m['di_bawah_batas'] . '</span>' : '0' ?></td>
                        <td class="num kb-muted"><i class="fa-solid fa-chevron-down"></i></td>
                    </tr>
                    <tr id="kbM<?= $i ?>" hidden>
                        <td colspan="8" style="background:var(--kb-soft)">
                            <table class="kb-table">
                                <thead><tr><th class="num">#</th><th>Nama</th><th class="kb-hide-sm">Rombel</th><th class="num">Nilai</th><th class="num">Selisih</th></tr></thead>
                                <tbody>
                                <?php foreach ($m['santri_nilai'] as $sn): $dev = $sn['nilai'] - (float)$m['rata_rata']; ?>
                                    <tr>
                                        <td class="num"><?= (int)$sn['peringkat'] ?></td>
                                        <td><?= e($sn['nama']) ?></td>
                                        <td class="kb-muted kb-hide-sm"><?= e($sn['rombel']) ?></td>
                                        <td class="num"><b style="<?= $sn['nilai'] < KB_BATAS_NILAI ? 'color:var(--kb-bad)' : '' ?>"><?= kbFmt($sn['nilai'], 0) ?></b></td>
                                        <td class="num kb-muted"><?= ($dev >= 0 ? '+' : '') . kbFmt($dev) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if ($mapelNoScore): ?>
            <p class="kb-muted" style="font-size:12px;margin:10px 0 0">Belum ada nilai PAS: <?= e(implode(', ', $mapelNoScore)) ?></p>
        <?php endif; ?>
    </div>

    <!-- CEK DATA -->
    <div class="kb-card">
        <h3 class="kb-section-title"><span class="kb-ic kb-t-teal" aria-hidden="true"><i class="fa-solid fa-clipboard-check"></i></span>Catatan data <?= e($paralel['nama']) ?><?= $rombelSel ? ' · ' . e($rombelName) : '' ?></h3>
        <?php if (!$issues): ?>
            <p class="kb-section-sub" style="margin:0">Tidak ada nilai ganda, nilai 0, atau nilai tidak wajar.</p>
        <?php else: ?>
            <p class="kb-section-sub">Sebaiknya dirapikan di AIS.</p>
            <div class="kb-table-wrap">
                <table class="kb-table">
                    <thead><tr><th>Jenis</th><th class="kb-hide-sm">Rombel</th><th>Santri</th><th>Mapel</th><th>Keterangan</th></tr></thead>
                    <tbody>
                    <?php foreach ($issues as $is): ?>
                        <tr>
                            <td><span class="kb-pill <?= in_array($is['jenis'], ['tidak_wajar', 'ganda'], true) ? 'kb-pill-bad' : 'kb-pill-warn' ?>"><?= e(kbIssueLabel($is['jenis'])) ?></span></td>
                            <td class="kb-hide-sm"><?= e($is['rombel']) ?></td>
                            <td><?= e($is['santri']) ?><div class="kb-muted kb-only-sm" style="font-size:11.5px"><?= e($is['rombel']) ?></div></td>
                            <td><?= e($is['mapel']) ?></td>
                            <td class="kb-muted" style="font-size:12.5px"><?= e($is['detail']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <script>
    function kbToggle(id, tr) {
        var row = document.getElementById(id);
        if (!row) return;
        row.hidden = !row.hidden;
        tr.setAttribute('aria-expanded', row.hidden ? 'false' : 'true');
        var ic = tr.querySelector('.fa-chevron-down, .fa-chevron-up');
        if (ic) { ic.classList.toggle('fa-chevron-down', row.hidden); ic.classList.toggle('fa-chevron-up', !row.hidden); }
    }
    function kbFilter(q, tableId) {
        q = (q || '').toLowerCase().trim();
        document.querySelectorAll('#' + tableId + ' tbody tr[data-q]').forEach(function (tr) {
            tr.style.display = (!q || tr.getAttribute('data-q').indexOf(q) !== -1) ? '' : 'none';
        });
    }
    </script>
    <?php kbUiHowItWorks(); ?>
<?php endif; ?>
</div>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
