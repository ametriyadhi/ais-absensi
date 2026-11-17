<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../config/security.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/kinerja_baru.php';
require_once __DIR__ . '/../../includes/kinerja_baru_ui.php';

requireLogin();
requireMenuAccess('kinerja_baru_mapel');

$pageTitle = 'Kinerja Baru · Mapel';
$ctx = kbLoadPage($pdo, $_GET);
$a = $ctx['a'];

require_once __DIR__ . '/../../includes/header.php';
kbUiStyles();

/** Kartu ringkas satu mapel (dipakai di blok 3 tertinggi / 3 terendah). */
$renderMapelCard = static function (array $m, string $tone): void {
    $pctBelow = $m['jumlah_nilai'] > 0 ? $m['di_bawah_batas'] / $m['jumlah_nilai'] * 100 : 0;
    ?>
    <div class="kb-row" style="padding:12px 14px">
        <div class="kb-row-top">
            <div class="kb-row-name"><span class="kb-num"><?= $m['peringkat'] ?? '–' ?></span><b class="n" title="<?= e($m['nama']) ?>"><?= e($m['nama']) ?></b></div>
            <span class="kb-score" style="font-size:17px;<?= $tone === 'bad' ? 'background:#fef2f2;color:#b91c1c;border-color:#fecaca' : '' ?>"><?= kbFmt($m['rata_rata']) ?></span>
        </div>
        <div class="kb-muted" style="font-size:12px;margin-top:6px">
            <?= (int)$m['jumlah_nilai'] ?> nilai · rentang <?= kbFmt($m['terendah'], 0) ?>–<?= kbFmt($m['tertinggi'], 0) ?> ·
            <span class="<?= $m['di_bawah_batas'] > 0 ? 'kb-pill kb-pill-warn' : 'kb-pill' ?>"><?= (int)$m['di_bawah_batas'] ?> santri &lt; <?= kbFmt(KB_BATAS_NILAI, 0) ?> (<?= kbFmt($pctBelow, 0) ?>%)</span>
        </div>
        <div class="kb-chips" style="margin-top:8px">
            <?php foreach ($m['per_paralel'] as $pp): ?>
                <span class="kb-chip"><?= e($pp['nama']) ?>: <b><?= kbFmt($pp['rata_rata']) ?></b></span>
            <?php endforeach; ?>
        </div>
    </div>
    <?php
};
?>
<div class="kb">
<?php if (kbUiHeader($ctx, 'Mapel tertinggi dan terendah',
    'Rata-rata nilai PAS per mata pelajaran, se-unit dan per paralel.')):
    $mapel = array_values(array_filter($a['mapel'], static fn($m) => $m['rata_rata'] !== null));
    $top3 = array_slice($mapel, 0, 3);
    $bottom3 = array_reverse(array_slice($mapel, -3));
    $totalBelow = array_sum(array_column($mapel, 'di_bawah_batas'));
    $totalScores = array_sum(array_column($mapel, 'jumlah_nilai'));
?>
    <div class="kb-kpis">
        <div class="kb-kpi">
            <span class="kb-ic kb-t-green" aria-hidden="true"><i class="fa-solid fa-arrow-trend-up"></i></span><div class="l">Mapel tertinggi</div>
            <div class="v" style="color:var(--kb-ok);font-size:18px"><?= e($top3[0]['nama'] ?? '-') ?></div>
            <div class="s">Rata-rata <?= kbFmt($top3[0]['rata_rata'] ?? null) ?></div>
        </div>
        <div class="kb-kpi">
            <span class="kb-ic kb-t-rose" aria-hidden="true"><i class="fa-solid fa-arrow-trend-down"></i></span><div class="l">Mapel terendah</div>
            <div class="v" style="color:var(--kb-bad);font-size:18px"><?= e($bottom3[0]['nama'] ?? '-') ?></div>
            <div class="s">Rata-rata <?= kbFmt($bottom3[0]['rata_rata'] ?? null) ?></div>
        </div>
        <div class="kb-kpi">
            <span class="kb-ic kb-t-indigo" aria-hidden="true"><i class="fa-solid fa-book"></i></span><div class="l">Jumlah mapel dinilai</div>
            <div class="v"><?= count($mapel) ?></div>
            <div class="s"><?= number_format($totalScores, 0, ',', '.') ?> nilai PAS</div>
        </div>
        <div class="kb-kpi">
            <span class="kb-ic kb-t-amber" aria-hidden="true"><i class="fa-solid fa-triangle-exclamation"></i></span><div class="l">Nilai di bawah <?= kbFmt(KB_BATAS_NILAI, 0) ?></div>
            <div class="v" style="color:var(--kb-warn)"><?= number_format($totalBelow, 0, ',', '.') ?></div>
            <div class="s"><?= kbFmt($totalScores > 0 ? $totalBelow / $totalScores * 100 : 0, 1) ?>% dari seluruh nilai</div>
        </div>
    </div>


    <div class="kb-grid2">
        <div class="kb-card">
            <h3 class="kb-section-title"><span class="kb-ic kb-t-green" aria-hidden="true"><i class="fa-solid fa-arrow-trend-up"></i></span>3 mapel tertinggi</h3>
            <div class="kb-list"><?php foreach ($top3 as $m) { $renderMapelCard($m, 'ok'); } ?></div>
        </div>
        <div class="kb-card">
            <h3 class="kb-section-title"><span class="kb-ic kb-t-rose" aria-hidden="true"><i class="fa-solid fa-arrow-trend-down"></i></span>3 mapel terendah</h3>
            <div class="kb-list"><?php foreach ($bottom3 as $m) { $renderMapelCard($m, 'bad'); } ?></div>
        </div>
    </div>

    <div class="kb-card">
        <h3 class="kb-section-title"><span class="kb-ic kb-t-indigo" aria-hidden="true"><i class="fa-solid fa-layer-group"></i></span>Per paralel</h3>
        <p class="kb-section-sub">Selisih = mapel tertinggi dikurangi mapel terendah.</p>
        <div class="kb-table-wrap">
            <table class="kb-table kb-stack">
                <thead><tr><th>Paralel</th><th>3 tertinggi</th><th>3 terendah</th><th class="num">Selisih</th></tr></thead>
                <tbody>
                <?php foreach ($a['paralel'] as $p):
                    $pm = array_values(array_filter($p['mapel'], static fn($m) => $m['rata_rata'] !== null));
                    if (!$pm) continue;
                    $pt = array_slice($pm, 0, 3);
                    $pb = array_reverse(array_slice($pm, -3));
                ?>
                    <tr>
                        <td class="kb-stack-title"><b><?= e($p['nama']) ?></b><div class="kb-muted" style="font-size:12px">Rata-rata <?= kbFmt($p['rata_rata']) ?></div></td>
                        <td data-label="3 tertinggi"><div><?php foreach ($pt as $m): ?><div><?= e($m['nama']) ?> <b><?= kbFmt($m['rata_rata']) ?></b></div><?php endforeach; ?></div></td>
                        <td data-label="3 terendah"><div><?php foreach ($pb as $m): ?><div><?= e($m['nama']) ?> <b><?= kbFmt($m['rata_rata']) ?></b></div><?php endforeach; ?></div></td>
                        <td class="num" data-label="Selisih"><b><?= kbFmt($pm[0]['rata_rata'] - $pm[count($pm) - 1]['rata_rata']) ?></b></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="kb-card">
        <h3 class="kb-section-title"><span class="kb-ic kb-t-teal" aria-hidden="true"><i class="fa-solid fa-list-ol"></i></span>Semua mapel se-unit</h3>
        <div class="kb-table-wrap">
            <table class="kb-table">
                <thead><tr>
                    <th class="num">#</th><th>Mata pelajaran</th><th class="num">Rata-rata</th><th class="num kb-hide-sm">Jumlah nilai</th>
                    <th class="num kb-hide-sm">Terendah</th><th class="num kb-hide-sm">Tertinggi</th><th class="num">Santri &lt; <?= kbFmt(KB_BATAS_NILAI, 0) ?></th>
                </tr></thead>
                <tbody>
                <?php foreach ($mapel as $m): ?>
                    <tr>
                        <td class="num"><?= kbRankDot($m['peringkat']) ?></td>
                        <td><?= e($m['nama']) ?><div class="kb-muted kb-only-sm" style="font-size:11.5px"><?= (int)$m['jumlah_nilai'] ?> nilai · <?= kbFmt($m['terendah'], 0) ?>–<?= kbFmt($m['tertinggi'], 0) ?></div></td>
                        <td class="num"><b><?= kbFmt($m['rata_rata']) ?></b></td>
                        <td class="num kb-hide-sm"><?= (int)$m['jumlah_nilai'] ?></td>
                        <td class="num kb-hide-sm"><?= kbFmt($m['terendah'], 0) ?></td>
                        <td class="num kb-hide-sm"><?= kbFmt($m['tertinggi'], 0) ?></td>
                        <td class="num"><?= (int)$m['di_bawah_batas'] ?> <span class="kb-muted">(<?= kbFmt($m['jumlah_nilai'] > 0 ? $m['di_bawah_batas'] / $m['jumlah_nilai'] * 100 : 0, 0) ?>%)</span></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php kbUiHowItWorks(); ?>
<?php endif; ?>
</div>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
