<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../config/security.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/kinerja_baru.php';
require_once __DIR__ . '/../../includes/kinerja_baru_ui.php';

requireLogin();
requireMenuAccess('kinerja_baru_paralel');

$pageTitle = 'Kinerja Baru · Paralel';
$ctx = kbLoadPage($pdo, $_GET);
$a = $ctx['a'];

require_once __DIR__ . '/../../includes/header.php';
kbUiStyles();

/** Satu baris mapel (nomor peringkat, nama, nilai, bar). */
$mapelRow = static function (array $m): void {
    ?>
    <div class="kb-row">
        <div class="kb-row-top">
            <div class="kb-row-name">
                <span class="kb-num"><?= $m['peringkat'] ?? '–' ?></span>
                <span class="n" title="<?= e($m['nama']) ?>"><?= e($m['nama']) ?></span>
                <?php if (!$m['wajib']): ?><span class="kb-pill" title="Hanya sebagian santri yang dinilai">sebagian</span><?php endif; ?>
            </div>
            <b><?= kbFmt($m['rata_rata']) ?></b>
        </div>
        <div class="kb-bar"><div class="<?= kbBarClass($m['rata_rata']) ?>" style="width:<?= max(0, min(100, (float)$m['rata_rata'])) ?>%"></div></div>
    </div>
    <?php
};
?>
<div class="kb">
<?php if (kbUiHeader($ctx, 'Rata-rata nilai per paralel',
    'Nilai PAS, diurutkan dari rata-rata tertinggi.')):
    $ranked = $a['paralel_ranked'];
    $withAvg = array_values(array_filter($ranked, static fn($p) => $p['rata_rata'] !== null));
    $top = $withAvg[0] ?? null;
    $bottom = $withAvg ? $withAvg[count($withAvg) - 1] : null;
    // Skala bar dimulai sedikit di bawah nilai terendah agar selisih antarparalel terlihat
    $scaleLo = $withAvg ? max(0, floor(min(array_column($withAvg, 'rata_rata')) / 5) * 5 - 5) : 0;
?>
    <div class="kb-kpis">
        <div class="kb-kpi">
            <span class="kb-ic kb-t-indigo" aria-hidden="true"><i class="fa-solid fa-chart-line"></i></span><div class="l">Rata-rata unit <?= e($ctx['unit']['nama'] ?? '') ?></div>
            <div class="v" style="color:var(--kb-ok)"><?= kbFmt($a['unit']['rata_rata']) ?></div>
            <div class="s"><?= (int)$a['unit']['lengkap'] ?> dari <?= (int)$a['unit']['santri'] ?> santri lengkap</div>
        </div>
        <div class="kb-kpi">
            <span class="kb-ic kb-t-teal" aria-hidden="true"><i class="fa-solid fa-calendar-days"></i></span><div class="l">Periode</div>
            <div class="v"><?= e($ctx['ta']['nama'] ?? '-') ?></div>
            <div class="s">Semester <?= (int)($ctx['ta']['semester'] ?? 0) ?> · nilai PAS</div>
        </div>
        <div class="kb-kpi">
            <span class="kb-ic kb-t-amber" aria-hidden="true"><i class="fa-solid fa-trophy"></i></span><div class="l">Paralel tertinggi</div>
            <div class="v" style="color:#92400e"><?= e($top['nama'] ?? '-') ?></div>
            <div class="s">Rata-rata <?= kbFmt($top['rata_rata'] ?? null) ?></div>
        </div>
        <div class="kb-kpi">
            <span class="kb-ic kb-t-rose" aria-hidden="true"><i class="fa-solid fa-arrow-trend-down"></i></span><div class="l">Paralel terendah</div>
            <div class="v"><?= e($bottom['nama'] ?? '-') ?></div>
            <div class="s">Rata-rata <?= kbFmt($bottom['rata_rata'] ?? null) ?></div>
        </div>
    </div>

    <div class="kb-card">
        <h3 class="kb-section-title"><span class="kb-ic kb-t-amber" aria-hidden="true"><i class="fa-solid fa-ranking-star"></i></span>Peringkat paralel</h3>
        <p class="kb-section-sub">Skala dimulai dari <?= kbFmt($scaleLo, 0) ?>.</p>
        <div class="kb-rankbars">
            <?php foreach ($ranked as $p):
                $w = $p['rata_rata'] === null ? 0 : max(2, min(100, ($p['rata_rata'] - $scaleLo) / max(1, 100 - $scaleLo) * 100)); ?>
                <a class="kb-rankbar <?= $p['peringkat'] === 1 ? 'top' : '' ?>" href="#p<?= (int)$p['id'] ?>">
                    <?= kbRankDot($p['peringkat']) ?>
                    <span class="nm"><?= e($p['nama']) ?></span>
                    <span class="track"><i style="width:<?= round($w, 1) ?>%"></i></span>
                    <span class="vl"><?= kbFmt($p['rata_rata']) ?></span>
                </a>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="kb-grid2">
        <?php foreach ($ranked as $p):
            $scored = array_values(array_filter($p['mapel'], static fn($m) => $m['jumlah_nilai'] > 0));
            $noScore = array_filter($p['mapel'], static fn($m) => $m['jumlah_nilai'] === 0);
            $compact = count($scored) > 7;
        ?>
            <div class="kb-card<?= $p['peringkat'] === 1 ? ' kb-top' : '' ?>" id="p<?= (int)$p['id'] ?>" style="margin-bottom:0;scroll-margin-top:90px">
                <div class="kb-head" style="align-items:center;gap:12px">
                    <div style="display:flex;gap:12px;align-items:center">
                        <?= kbRankDot($p['peringkat']) ?>
                        <div>
                            <h3 style="margin:0 0 2px;font-size:17px;font-weight:800"><?= e($p['nama']) ?></h3>
                            <div class="kb-muted" style="font-size:12.5px">
                                <?= (int)$p['santri'] ?> santri · <?= count($p['rombel']) ?> rombel ·
                                <?= $p['mapel_wajib_min'] === $p['mapel_wajib'] ? (int)$p['mapel_wajib'] : ((int)$p['mapel_wajib_min'] . '–' . (int)$p['mapel_wajib']) ?> mapel wajib
                            </div>
                        </div>
                    </div>
                    <span class="kb-score" title="Rata-rata paralel"><?= kbFmt($p['rata_rata']) ?></span>
                </div>

                <?php if ($p['kelengkapan'] < KB_AMBANG_KELENGKAPAN || $p['lengkap'] < $p['santri']): ?>
                    <div style="margin-top:10px;display:flex;gap:6px;flex-wrap:wrap">
                        <?php if ($p['kelengkapan'] < KB_AMBANG_KELENGKAPAN): ?><span class="kb-pill kb-pill-warn">PAS terisi <?= kbFmt($p['kelengkapan'], 1) ?>%</span><?php endif; ?>
                        <?php if ($p['lengkap'] < $p['santri']): ?><span class="kb-pill"><?= (int)($p['santri'] - $p['lengkap']) ?> santri belum lengkap</span><?php endif; ?>
                    </div>
                <?php endif; ?>

                <div class="kb-subhead">Rombel</div>
                <div class="kb-chips">
                    <?php foreach ($p['rombel'] as $r): ?>
                        <span class="kb-chip" title="<?= (int)$r['lengkap'] ?> dari <?= (int)$r['santri'] ?> santri lengkap">
                            <?= e($r['nama']) ?> <b><?= kbFmt($r['rata_rata']) ?></b>
                            <?php if ($r['lengkap'] < $r['santri']): ?><small>(<?= (int)$r['lengkap'] ?>/<?= (int)$r['santri'] ?>)</small><?php endif; ?>
                        </span>
                    <?php endforeach; ?>
                </div>

                <?php if ($compact): ?>
                    <div class="kb-subhead"><i class="fa-solid fa-arrow-trend-up"></i> 3 mapel tertinggi</div>
                    <div class="kb-list"><?php foreach (array_slice($scored, 0, 3) as $m) { $mapelRow($m); } ?></div>
                    <div class="kb-subhead"><i class="fa-solid fa-arrow-trend-down"></i> 3 mapel terendah</div>
                    <div class="kb-list"><?php foreach (array_slice($scored, -3) as $m) { $mapelRow($m); } ?></div>
                    <details class="kb-more">
                        <summary>Semua mapel (<?= count($scored) ?>)</summary>
                        <div class="kb-list"><?php foreach ($scored as $m) { $mapelRow($m); } ?></div>
                    </details>
                <?php else: ?>
                    <div class="kb-subhead">Mata pelajaran</div>
                    <div class="kb-list"><?php foreach ($scored as $m) { $mapelRow($m); } ?></div>
                <?php endif; ?>

                <?php if ($noScore): ?>
                    <div class="kb-muted" style="font-size:12px;margin-top:8px">
                        <i class="fa-regular fa-circle"></i> Belum ada nilai PAS: <?= e(implode(', ', array_column($noScore, 'nama'))) ?>
                    </div>
                <?php endif; ?>

                <div class="kb-foot">
                    <span><?= number_format(array_sum(array_column($p['mapel'], 'jumlah_nilai')), 0, ',', '.') ?> nilai PAS dihitung</span>
                    <a href="<?= e(kbUrl($ctx, ['p' => $p['id']], 'all_data.php')) ?>">Lihat santri <i class="fa-solid fa-arrow-right"></i></a>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
    <?php kbUiHowItWorks(); ?>
<?php endif; ?>
</div>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
