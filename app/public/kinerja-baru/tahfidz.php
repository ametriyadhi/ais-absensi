<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../config/security.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/kinerja_baru_tahfidz.php';
require_once __DIR__ . '/../../includes/kinerja_baru_ui.php';

requireLogin();
requireMenuAccess('kinerja_baru_tahfidz');

$canEdit = canEdit('kinerja_baru_tahfidz');
$tab = in_array($_GET['tab'] ?? '', ['santri', 'guru', 'atur'], true) ? (string)$_GET['tab'] : 'santri';

// ---------- POST: pengaturan (target, batas kuadran, pemetaan guru) ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$canEdit) {
        http_response_code(403);
        die('403 - Akses Ditolak: Anda tidak memiliki izin mengubah pengaturan.');
    }
    requireCsrf();
    $action = (string)($_POST['action'] ?? '');
    $pUnit  = max(1, (int)($_POST['unit_id'] ?? 2));
    $pTa    = max(0, (int)($_POST['ta'] ?? 0));
    $pMonth = max(0, min(12, (int)($_POST['month'] ?? 0)));
    kbAssertUnitPost($pdo, $pUnit);
    $uid = (int)currentUserId();
    $flash = ['type' => 'error', 'text' => 'Aksi tidak dikenal.'];

    try {
        if ($action === 'save_targets') {
            $sem = ((int)($_POST['semester'] ?? 1) === 2) ? 2 : 1;
            $types = ['juz30_rank', 'juz29_rank', 'juz28_rank', 'juz_stage', 'juz29_coverage', 'juz28_coverage', 'teacher_individual'];
            $catalog = getTahfidzSurahCatalog();
            $st = $pdo->prepare('
                INSERT INTO kinerja_baru_tahfidz_targets
                    (unit_id, paralel_id, paralel_nama, semester, target_type, target_value, target_label, require_munaqasyah, updated_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE paralel_nama = VALUES(paralel_nama), target_type = VALUES(target_type),
                    target_value = VALUES(target_value), target_label = VALUES(target_label),
                    require_munaqasyah = VALUES(require_munaqasyah), updated_by = VALUES(updated_by)
            ');
            $n = 0;
            $pdo->beginTransaction();
            foreach ((array)($_POST['targets'] ?? []) as $pid => $row) {
                $pid = (int)$pid;
                if ($pid <= 0 || !is_array($row)) {
                    continue;
                }
                $type = in_array($row['target_type'] ?? '', $types, true) ? (string)$row['target_type'] : 'juz30_rank';
                $val = max(0.0, min(100.0, (float)($row['target_value'] ?? 13)));
                $label = $catalog[$type][(int)round($val)] ?? 'Target Tahfidz';
                $st->execute([$pUnit, $pid, mb_substr(trim(strip_tags((string)($row['paralel_nama'] ?? ''))), 0, 100),
                    $sem, $type, $val, mb_substr($label, 0, 150), !empty($row['require_munaqasyah']) ? 1 : 0, $uid]);
                $n++;
            }
            $pdo->commit();
            $flash = ['type' => 'success', 'text' => "Target {$n} paralel (semester {$sem}) tersimpan."];
        } elseif ($action === 'save_settings') {
            $hi = (float)($_POST['jurnal_tinggi'] ?? 85);
            $lo = (float)($_POST['jurnal_rendah'] ?? 60);
            $tb = (float)($_POST['tuntas_batas'] ?? 75);
            if ($hi <= 0 || $hi > 100 || $lo < 0 || $lo >= $hi || $tb <= 0 || $tb > 100) {
                $flash = ['type' => 'error', 'text' => 'Batas tidak valid: harus 0–100 dan batas jurnal rendah < batas jurnal tinggi.'];
            } else {
                $st = $pdo->prepare('INSERT INTO kinerja_baru_settings (setting_key, setting_value, updated_by) VALUES (?, ?, ?)
                    ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_by = VALUES(updated_by)');
                foreach (['jurnal_tinggi' => $hi, 'jurnal_rendah' => $lo, 'tuntas_batas' => $tb] as $k => $v) {
                    $st->execute([$k, (string)$v, $uid]);
                }
                $flash = ['type' => 'success', 'text' => 'Batas kuadran kinerja guru tersimpan.'];
            }
        } elseif ($action === 'save_guru_map') {
            $staffIds = array_map('intval', $pdo->query('SELECT id FROM staff WHERE deleted_at IS NULL')->fetchAll(PDO::FETCH_COLUMN));
            $up = $pdo->prepare('INSERT INTO kinerja_baru_guru_map (ais_guru_id, ais_guru_nama, staff_id, updated_by) VALUES (?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE ais_guru_nama = VALUES(ais_guru_nama), staff_id = VALUES(staff_id), updated_by = VALUES(updated_by)');
            $del = $pdo->prepare('DELETE FROM kinerja_baru_guru_map WHERE ais_guru_id = ?');
            $n = 0;
            foreach ((array)($_POST['map'] ?? []) as $gid => $sid) {
                $gid = (int)$gid;
                $sid = (int)$sid;
                if ($gid <= 0) {
                    continue;
                }
                if ($sid > 0 && in_array($sid, $staffIds, true)) {
                    $up->execute([$gid, mb_substr(trim(strip_tags((string)($_POST['map_nama'][$gid] ?? ''))), 0, 150), $sid, $uid]);
                    $n++;
                } else {
                    $del->execute([$gid]);
                }
            }
            $flash = ['type' => 'success', 'text' => "Pemetaan guru tersimpan ({$n} dipasangkan manual)."];
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('[kinerja-baru/tahfidz.php] ' . $e->getMessage());
        $flash = ['type' => 'error', 'text' => 'Gagal menyimpan. Pastikan migrasi database/migrate_kinerja_baru_menu.php sudah dijalankan.'];
    }
    $_SESSION['kb_flash'] = $flash;
    $q = ['unit_id' => $pUnit, 'tab' => 'atur'];
    if ($pTa) {
        $q['ta'] = $pTa;
    }
    if ($pMonth) {
        $q['month'] = $pMonth;
    }
    header('Location: tahfidz.php?' . http_build_query($q));
    exit;
}

$flash = $_SESSION['kb_flash'] ?? null;
unset($_SESSION['kb_flash']);

$pageTitle = 'Kinerja Baru · Tahfidz';
$ctx = kbLoadTahfidzPage($pdo, $_GET);
$settings = kbSettings($pdo);
$h = $ctx['hierarki'];
$guru = $ctx['ok'] ? kbGuruKinerja($pdo, $ctx['api'], $h['teacher_stats'] ?? [], $settings, (int)$ctx['month']) : null;

$bulan = [1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
$keep = ['tab' => $tab, 'month' => $ctx['month'] ?: null, 'sort' => $ctx['sort'] !== 'grade_asc' ? $ctx['sort'] : null];

require_once __DIR__ . '/../../includes/header.php';
kbUiStyles();
?>
<style>
.kb-q{display:inline-block;font-size:11.5px;font-weight:700;padding:2px 8px;border-radius:6px;border:1px solid;white-space:nowrap}
.kb-q-emerald{background:#eef7f1;color:#15803d;border-color:transparent}.kb-q-blue,.kb-q-teal{background:#f1f3f7;color:#334155;border-color:transparent}
.kb-q-amber{background:#fdf6ea;color:#b45309;border-color:transparent}.kb-q-rose{background:#fdf1f1;color:#b91c1c;border-color:transparent}
.kb-q-slate{background:transparent;color:#7b8499;border-color:transparent;padding-left:0}
.kb details.kb-acc{border:1px solid var(--kb-line);border-radius:10px;margin-bottom:8px;background:#fff}
.kb details.kb-acc>summary{cursor:pointer;padding:10px 14px;list-style:none;display:flex;justify-content:space-between;gap:10px;align-items:center;flex-wrap:wrap}
.kb details.kb-acc>summary::-webkit-details-marker{display:none}
.kb details.kb-acc[open]>summary{border-bottom:1px solid var(--kb-line);background:var(--kb-soft)}
.kb-acc-body{padding:10px 14px}
.kb .kb-input{width:auto;border:1px solid #cbd5e1;border-radius:8px;padding:6px 10px;font-size:13px;min-height:34px;max-width:100%}
.kb-pct-bar{display:inline-block;width:70px;height:6px;background:#f1f5f9;border-radius:6px;overflow:hidden;vertical-align:middle;margin-left:6px}
.kb-pct-bar>i{display:block;height:100%;background:#10b981}
</style>
<div class="kb">
<?php if ($flash): ?>
    <div class="kb-alert <?= $flash['type'] === 'success' ? 'kb-alert-info' : 'kb-alert-bad' ?>"><i class="fa-solid <?= $flash['type'] === 'success' ? 'fa-circle-check' : 'fa-circle-exclamation' ?>"></i><div><?= e($flash['text']) ?></div></div>
<?php endif; ?>

<?php
ob_start(); ?>
    <select name="month" class="kb-select kb-extra" onchange="this.form.submit()" aria-label="Bulan">
        <option value="0">Semester</option>
        <?php foreach ($ctx['months'] as $m): ?>
            <option value="<?= (int)$m ?>" <?= (int)$m === (int)$ctx['month'] ? 'selected' : '' ?>><?= e($bulan[(int)$m] ?? (string)$m) ?></option>
        <?php endforeach; ?>
    </select>
    <select name="sort" class="kb-select kb-extra" onchange="this.form.submit()" aria-label="Urutan">
        <option value="grade_asc" <?= $ctx['sort'] === 'grade_asc' ? 'selected' : '' ?>>Urut kelas</option>
        <option value="highest" <?= $ctx['sort'] === 'highest' ? 'selected' : '' ?>>Tuntas tertinggi</option>
        <option value="lowest" <?= $ctx['sort'] === 'lowest' ? 'selected' : '' ?>>Tuntas terendah</option>
    </select>
<?php $extraFilter = (string)ob_get_clean();

if (kbUiHeader($ctx, 'Capaian tahfidz',
    'Ketuntasan hafalan santri dan pengisian jurnal Guru Qur\'an.', $keep, $extraFilter)):
    $u = $h['unit_summary'];
    $um = $ctx['hierarki_mentah']['unit_summary'];
    $ai = $ctx['ai'];
?>

    <div class="kb-tabs">
        <a class="kb-tab <?= $tab === 'santri' ? 'active' : '' ?>" href="<?= e(kbUrl($ctx, ['tab' => 'santri'] + $keep)) ?>">Santri</a>
        <a class="kb-tab <?= $tab === 'guru' ? 'active' : '' ?>" href="<?= e(kbUrl($ctx, ['tab' => 'guru'] + $keep)) ?>">Guru Qur'an</a>
        <a class="kb-tab <?= $tab === 'atur' ? 'active' : '' ?>" href="<?= e(kbUrl($ctx, ['tab' => 'atur'] + $keep)) ?>">Pengaturan</a>
    </div>

    <?php if ($tab === 'santri'): ?>
        <div class="kb-kpis">
            <div class="kb-kpi">
                <span class="kb-ic kb-t-green" aria-hidden="true"><i class="fa-solid fa-circle-check"></i></span><div class="l">Santri tuntas target</div>
                <div class="v" style="color:var(--kb-ok)"><?= kbFmt((float)$u['percentage'], 1) ?>%</div>
                <div class="s"><?= (int)$u['achieved_students'] ?> dari <?= (int)$u['total_students'] ?> santri</div>
            </div>
            <div class="kb-kpi">
                <span class="kb-ic kb-t-indigo" aria-hidden="true"><i class="fa-solid fa-gauge-high"></i></span><div class="l">Rata-rata progres</div>
                <div class="v"><?= kbFmt((float)$u['avg_progress_pct'], 1) ?>%</div>
                <div class="s">menuju target masing-masing paralel</div>
            </div>
            <div class="kb-kpi">
                <span class="kb-ic kb-t-amber" aria-hidden="true"><i class="fa-solid fa-trophy"></i></span><div class="l">Paralel terbaik</div>
                <div class="v" style="font-size:18px"><?= e($u['best_paralel']['paralel_nama'] ?? '-') ?></div>
                <div class="s"><?= isset($u['best_paralel']) ? kbFmt((float)$u['best_paralel']['percentage'], 1) . '% tuntas' : '' ?></div>
            </div>
            <div class="kb-kpi">
                <span class="kb-ic kb-t-rose" aria-hidden="true"><i class="fa-solid fa-arrow-trend-down"></i></span><div class="l">Rombel terendah</div>
                <div class="v" style="font-size:18px"><?= e(($u['lowest_rombel']['paralel_nama'] ?? '-') . ' · ' . ($u['lowest_rombel']['rombel_nama'] ?? '')) ?></div>
                <div class="s"><?= isset($u['lowest_rombel']) ? kbFmt((float)$u['lowest_rombel']['percentage'], 1) . '% tuntas' : '' ?></div>
            </div>
        </div>

        <div class="kb-alert kb-alert-info">
            <i class="fa-solid fa-circle-info"></i>
            <div>Dihitung dari data AIS<?php if ($ai['ai_upgraded_count'] > 0 || $ai['override_count'] > 0): ?>,
                ditambah <b><?= (int)$ai['ai_upgraded_count'] ?></b> catatan AI yang sudah disetujui dan <b><?= (int)$ai['override_count'] ?></b> koreksi manual<?php endif; ?>.
                Tanpa tambahan itu ketuntasan unit <?= kbFmt((float)$um['percentage'], 1) ?>%.
                <?php if ($ai['pending_review_count'] > 0 || $ai['pending_count'] > 0): ?>
                    <br><?php if ($ai['pending_review_count'] > 0): ?><b><?= (int)$ai['pending_review_count'] ?></b> santri capaiannya bisa naik jika bacaan AI atas catatan gurunya disetujui. <?php endif; ?>
                    <?php if ($ai['pending_count'] > 0): ?><b><?= (int)$ai['pending_count'] ?></b> catatan guru belum dianalisis AI. <?php endif; ?>
                    <a href="<?= e(kbUrl($ctx, [], 'tahfidz_ai.php')) ?>" style="font-weight:700">Tinjau di Catatan guru</a>.
                <?php endif; ?>
            </div>
        </div>

        <?php foreach ($h['paralels'] as $p): $cfg = $p['target_config'] ?? null; ?>
            <div class="kb-card">
                <div class="kb-head" style="align-items:center">
                    <div>
                        <h3 style="margin:0 0 4px;font-size:17px;font-weight:800"><?= e($p['paralel_nama']) ?></h3>
                        <div class="kb-muted" style="font-size:12.5px">
                            Target: <b><?= e($p['target_label']) ?></b>
                            <?php if (!empty($cfg['require_munaqasyah'])): ?> · <span class="kb-pill kb-pill-warn">wajib munaqasyah</span><?php endif; ?>
                            <?php if (!$cfg): ?> <span class="kb-pill">target bawaan — atur di Pengaturan</span><?php endif; ?>
                        </div>
                    </div>
                    <div class="kb-scorebox">
                        <span class="kb-score" style="background:#ecfdf5;color:#047857;border-color:#a7f3d0"><?= kbFmt((float)$p['percentage'], 1) ?>%</span>
                        <div class="kb-muted" style="font-size:12px;margin-top:3px"><?= (int)$p['achieved_students'] ?>/<?= (int)$p['total_students'] ?> tuntas · progres <?= kbFmt((float)$p['avg_progress_pct'], 1) ?>%</div>
                    </div>
                </div>
                <div style="margin-top:12px">
                    <?php foreach ($p['rombels'] as $r): ?>
                        <details class="kb-acc">
                            <summary>
                                <span><b><?= e($r['rombel_nama']) ?></b> <span class="kb-muted">(<?= (int)$r['total_students'] ?> santri)</span></span>
                                <span><?= (int)$r['achieved_students'] ?>/<?= (int)$r['total_students'] ?> tuntas · <b><?= kbFmt((float)$r['percentage'], 1) ?>%</b>
                                    <span class="kb-pct-bar"><i style="width:<?= max(0, min(100, (float)$r['percentage'])) ?>%"></i></span></span>
                            </summary>
                            <div class="kb-acc-body kb-table-wrap">
                                <table class="kb-table kb-stack">
                                    <thead><tr><th>Santri</th><th>Capaian hafalan</th><th class="num">Progres</th><th>Status</th><th>Sumber</th><th>Guru</th></tr></thead>
                                    <tbody>
                                    <?php foreach ($r['students'] as $s):
                                        $src = $s['ai_badge'] === 'manual_override' ? ['Koreksi manual', 'kb-pill-warn']
                                            : ($s['ai_badge'] === 'ai_verified' ? ['AI disetujui', 'kb-pill-ok'] : ['AIS', '']); ?>
                                        <tr>
                                            <td class="kb-stack-title"><b><?= e($s['nama']) ?></b>
                                                <div class="kb-only-sm" style="margin-top:4px"><span class="kb-pill <?= $s['is_achieved'] ? 'kb-pill-ok' : '' ?>"><?= e($s['status_label']) ?></span>
                                                    <span class="kb-pill <?= $src[1] ?>"><?= $src[0] ?></span> <span class="kb-muted" style="font-size:11.5px"><?= kbFmt((float)$s['progress_pct'], 0) ?>% · <?= e($s['pengajar_nama']) ?></span></div></td>
                                            <td data-label="Capaian"><?= e($s['actual_display']) ?></td>
                                            <td class="num kb-hide-sm" data-label="Progres"><?= kbFmt((float)$s['progress_pct'], 0) ?>%</td>
                                            <td class="kb-hide-sm" data-label="Status"><span class="kb-pill <?= $s['is_achieved'] ? 'kb-pill-ok' : '' ?>"><?= e($s['status_label']) ?></span></td>
                                            <td class="kb-hide-sm" data-label="Sumber"><span class="kb-pill <?= $src[1] ?>"><?= $src[0] ?></span></td>
                                            <td class="kb-muted kb-hide-sm" data-label="Guru"><?= e($s['pengajar_nama']) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </details>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endforeach; ?>

    <?php elseif ($tab === 'guru'): $g = $guru['ringkas']; ?>
        <div class="kb-kpis">
            <div class="kb-kpi">
                <span class="kb-ic kb-t-indigo" aria-hidden="true"><i class="fa-solid fa-book-open"></i></span><div class="l">Rata-rata % jurnal</div>
                <div class="v"><?= $g['rata_jurnal'] === null ? '–' : kbFmt($g['rata_jurnal'], 1) . '%' ?></div>
                <div class="s"><?= (int)$g['guru_terukur'] ?> dari <?= (int)$g['guru'] ?> guru terukur</div>
            </div>
            <div class="kb-kpi">
                <span class="kb-ic kb-t-green" aria-hidden="true"><i class="fa-solid fa-star"></i></span><div class="l">Kinerja prima</div>
                <div class="v" style="color:var(--kb-ok)"><?= (int)$g['prima'] ?></div>
                <div class="s">jurnal ≥ <?= kbFmt($settings['jurnal_tinggi'], 0) ?>% dan santri tuntas ≥ <?= kbFmt($settings['tuntas_batas'], 0) ?>%</div>
            </div>
            <div class="kb-kpi">
                <span class="kb-ic kb-t-rose" aria-hidden="true"><i class="fa-solid fa-flag"></i></span><div class="l">Prioritas supervisi</div>
                <div class="v" style="color:var(--kb-bad)"><?= (int)$g['prioritas'] ?></div>
                <div class="s">jurnal &lt; <?= kbFmt($settings['jurnal_rendah'], 0) ?>% dan santri tuntas &lt; <?= kbFmt($settings['tuntas_batas'], 0) ?>%</div>
            </div>
            <div class="kb-kpi">
                <span class="kb-ic kb-t-amber" aria-hidden="true"><i class="fa-solid fa-link-slash"></i></span><div class="l">Belum dipetakan</div>
                <div class="v" style="color:<?= $g['belum_dipetakan'] ? 'var(--kb-warn)' : 'inherit' ?>"><?= (int)$g['belum_dipetakan'] ?></div>
                <div class="s"><?= $g['belum_dipetakan'] ? '<a href="' . e(kbUrl($ctx, ['tab' => 'atur'] + $keep)) . '#peta">pasangkan dengan data staf</a>' : 'semua guru cocok dengan data staf' ?></div>
            </div>
        </div>

        <?php if ($g['guru'] > 0 && $g['guru_terukur'] === 0):
            $alasan = array_count_values(array_column($guru['rows'], 'kuadran')); ?>
            <div class="kb-alert kb-alert-warn"><i class="fa-solid fa-circle-info"></i>
                <div><b>% jurnal belum bisa dihitung untuk periode ini.</b>
                    <?php if (!empty($alasan['no_attendance'])): ?> <?= (int)$alasan['no_attendance'] ?> guru belum punya catatan hadir di sistem absensi antara <?= e(date('d-m-Y', strtotime($guru['periode']['mulai']))) ?> dan <?= e(date('d-m-Y', strtotime($guru['periode']['selesai']))) ?>.<?php endif; ?>
                    <?php if (!empty($alasan['unmatched'])): ?> <?= (int)$alasan['unmatched'] ?> guru belum dipasangkan dengan data staf.<?php endif; ?>
                    <?php if (!empty($alasan['no_basis'])): ?> <?= (int)$alasan['no_basis'] ?> guru tidak punya hari hadir yang bertepatan dengan kegiatan halaqah.<?php endif; ?>
                    Ketuntasan santri binaan tetap ditampilkan di bawah.</div></div>
        <?php endif; ?>

        <details class="kb-how">
            <summary>Cara menghitung % jurnal</summary>
            <ul>
                <li><b>Hari hadir + halaqah</b> = hari guru tercatat hadir (tepat waktu/terlambat) di sistem absensi <b>dan</b> pada hari itu ada kegiatan halaqah di unit.</li>
                <li><b>% Jurnal</b> = hari jurnal diisi pada hari-hari tersebut ÷ jumlah hari hadir + halaqah. Hari hadir tanpa kegiatan halaqah tidak menurunkan persentase.</li>
                <li><b>Jurnal tanpa tap hadir</b> = hari jurnal diisi tetapi guru tidak tercatat hadir — sebaiknya dicek (lupa tap atau salah tanggal).</li>
                <li>Periode absensi: <?= e(date('d-m-Y', strtotime($guru['periode']['mulai']))) ?> s.d. <?= e(date('d-m-Y', strtotime($guru['periode']['selesai']))) ?> · <?= (int)$guru['periode']['hari_halaqah_unit'] ?> hari kegiatan halaqah di unit.</li>
            </ul>
        </details>

        <div class="kb-card">
            <div class="kb-head" style="align-items:center;margin-bottom:12px">
                <h3 class="kb-section-title" style="margin:0"><span class="kb-ic kb-t-indigo" aria-hidden="true"><i class="fa-solid fa-chalkboard-user"></i></span>Guru Qur'an</h3>
                <div class="kb-filter">
                    <select class="kb-select" id="kbQ" onchange="kbGuruFilter()" aria-label="Kuadran">
                        <option value="">Semua kategori</option>
                        <?php foreach (array_unique(array_column($guru['rows'], 'kuadran_label', 'kuadran')) as $k => $l): ?>
                            <option value="<?= e($k) ?>"><?= e($l) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <input type="search" class="kb-search" id="kbS" placeholder="Cari guru…" oninput="kbGuruFilter()">
                </div>
            </div>
            <div class="kb-table-wrap">
                <table class="kb-table kb-stack" id="kbGuru">
                    <thead><tr>
                        <th>Guru</th><th class="num">Hari hadir</th><th class="num">Hadir + halaqah</th><th class="num">Jurnal diisi</th>
                        <th class="num">% Jurnal</th><th class="num">Jurnal tanpa tap hadir</th><th class="num">Santri tuntas</th><th>Kategori</th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($guru['rows'] as $r): ?>
                        <tr data-q="<?= e($r['kuadran']) ?>" data-s="<?= e(mb_strtolower($r['guru_nama'] . ' ' . $r['guru_nik'] . ' ' . $r['staff_nama'])) ?>">
                            <td class="kb-stack-title"><b><?= e($r['guru_nama']) ?></b>
                                <div class="kb-muted" style="font-size:12px"><?= e($r['level_list']) ?> · <?= (int)$r['jml_halaqah'] ?> halaqah
                                    <?php if ($r['cocok_lewat'] === 'nama'): ?> · <span class="kb-pill kb-pill-warn" title="Dicocokkan lewat nama: <?= e($r['staff_nama']) ?>">cocok lewat nama</span><?php endif; ?>
                                    <?php if ($r['cocok_lewat'] === 'manual'): ?> · <span class="kb-pill">dipasangkan manual</span><?php endif; ?>
                                </div></td>
                            <td class="num" data-label="Hari hadir"><?= $r['staff_id'] ? (int)$r['hari_hadir'] : '–' ?></td>
                            <td class="num" data-label="Hadir + halaqah"><?= $r['staff_id'] ? (int)$r['hari_basis'] : '–' ?></td>
                            <td class="num" data-label="Jurnal diisi"><span><?= (int)$r['jurnal_pada_basis'] ?><span class="kb-muted" style="font-size:11.5px"> / <?= (int)$r['jurnal_total'] ?> total</span></span></td>
                            <td class="num" data-label="% Jurnal"><b><?= $r['jurnal_pct'] === null ? '–' : kbFmt($r['jurnal_pct'], 1) . '%' ?></b></td>
                            <td class="num" data-label="Jurnal tanpa tap hadir"><?= $r['jurnal_tanpa_hadir'] > 0 ? '<span class="kb-pill kb-pill-warn">' . (int)$r['jurnal_tanpa_hadir'] . '</span>' : '0' ?></td>
                            <td class="num" data-label="Santri tuntas"><span><?= kbFmt($r['binaan_pct'], 1) ?>% <span class="kb-muted" style="font-size:11.5px">(<?= (int)$r['binaan_tuntas'] ?>/<?= (int)$r['binaan_total'] ?>)</span></span></td>
                            <td data-label="Kategori"><span class="kb-q kb-q-<?= e($r['kuadran_warna']) ?>" title="<?= e($r['kuadran_ket']) ?>"><?= e($r['kuadran_label']) ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$guru['rows']): ?><tr><td colspan="8" class="kb-empty">Belum ada data jurnal guru pada periode ini.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <script>
        function kbGuruFilter() {
            var q = document.getElementById('kbQ').value, s = (document.getElementById('kbS').value || '').toLowerCase().trim();
            document.querySelectorAll('#kbGuru tbody tr[data-q]').forEach(function (tr) {
                var ok = (!q || tr.getAttribute('data-q') === q) && (!s || tr.getAttribute('data-s').indexOf(s) !== -1);
                tr.style.display = ok ? '' : 'none';
            });
        }
        </script>

    <?php else: /* PENGATURAN */
        $catalog = getTahfidzSurahCatalog();
        $typeLabels = [
            'juz30_rank' => 'Urutan surat Juz 30', 'juz29_rank' => 'Urutan surat Juz 29', 'juz28_rank' => 'Urutan surat Juz 28',
            'juz_stage' => 'Tahap juz', 'juz29_coverage' => 'Cakupan Juz 29', 'juz28_coverage' => 'Cakupan Juz 28',
            'teacher_individual' => 'Target individu dari guru',
        ];
        $paralelMeta = $ctx['api']['meta']['paralels'] ?? [];
        $staffList = $pdo->query('SELECT id, name, nik FROM staff WHERE deleted_at IS NULL ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);
        $manual = kbGuruMap($pdo);
        $dis = $canEdit ? '' : 'disabled';
    ?>
        <?php if (!$canEdit): ?>
            <div class="kb-alert kb-alert-info"><i class="fa-solid fa-lock"></i><div>Anda hanya dapat melihat pengaturan. Perubahan memerlukan izin ubah pada menu ini.</div></div>
        <?php endif; ?>

        <div class="kb-card">
            <h3 class="kb-section-title"><span class="kb-ic kb-t-rose" aria-hidden="true"><i class="fa-solid fa-bullseye"></i></span>Target kurikulum semester <?= (int)($ctx['ta']['semester'] ?? 1) ?></h3>
            <p class="kb-section-sub">Tidak memengaruhi menu Penilaian Kinerja lama.</p>
            <form method="POST">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="save_targets">
                <input type="hidden" name="unit_id" value="<?= (int)$ctx['unit_id'] ?>">
                <input type="hidden" name="ta" value="<?= (int)$ctx['ta_id'] ?>">
                <input type="hidden" name="month" value="<?= (int)$ctx['month'] ?>">
                <input type="hidden" name="semester" value="<?= (int)($ctx['ta']['semester'] ?? 1) ?>">
                <div class="kb-table-wrap">
                    <table class="kb-table">
                        <thead><tr><th>Paralel</th><th>Jenis target</th><th>Target</th><th>Wajib munaqasyah</th></tr></thead>
                        <tbody>
                        <?php foreach ($paralelMeta as $pm):
                            $pid = (int)$pm['paralel_id'];
                            $cfg = $ctx['targets'][$pid] ?? ['target_type' => 'juz30_rank', 'target_value' => 13, 'require_munaqasyah' => false]; ?>
                            <tr>
                                <td><b><?= e($pm['paralel_nama']) ?></b><input type="hidden" name="targets[<?= $pid ?>][paralel_nama]" value="<?= e($pm['paralel_nama']) ?>"></td>
                                <td><select class="kb-input" name="targets[<?= $pid ?>][target_type]" data-pid="<?= $pid ?>" onchange="kbTargetOpts(this)" <?= $dis ?>>
                                    <?php foreach ($typeLabels as $tk => $tl): ?>
                                        <option value="<?= e($tk) ?>" <?= $cfg['target_type'] === $tk ? 'selected' : '' ?>><?= e($tl) ?></option>
                                    <?php endforeach; ?>
                                </select></td>
                                <td><select class="kb-input" name="targets[<?= $pid ?>][target_value]" id="kbTv<?= $pid ?>" data-val="<?= (int)round((float)$cfg['target_value']) ?>" <?= $dis ?>></select></td>
                                <td><input type="checkbox" name="targets[<?= $pid ?>][require_munaqasyah]" value="1" <?= !empty($cfg['require_munaqasyah']) ? 'checked' : '' ?> <?= $dis ?>></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php if ($canEdit): ?><div style="margin-top:12px"><button class="kb-btn kb-btn-primary" type="submit"><i class="fa-solid fa-floppy-disk"></i> Simpan target</button></div><?php endif; ?>
            </form>
        </div>

        <div class="kb-card">
            <h3 class="kb-section-title"><span class="kb-ic kb-t-teal" aria-hidden="true"><i class="fa-solid fa-sliders"></i></span>Batas kategori guru</h3>
            <p class="kb-section-sub">Dipakai di tab Guru Qur'an.</p>
            <form method="POST" class="kb-filter">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="save_settings">
                <input type="hidden" name="unit_id" value="<?= (int)$ctx['unit_id'] ?>">
                <input type="hidden" name="ta" value="<?= (int)$ctx['ta_id'] ?>">
                <label>Jurnal tertib ≥ <input class="kb-input" style="width:80px" type="number" step="1" min="1" max="100" name="jurnal_tinggi" value="<?= kbFmt($settings['jurnal_tinggi'], 0) ?>" <?= $dis ?>> %</label>
                <label>Jurnal rendah &lt; <input class="kb-input" style="width:80px" type="number" step="1" min="0" max="99" name="jurnal_rendah" value="<?= kbFmt($settings['jurnal_rendah'], 0) ?>" <?= $dis ?>> %</label>
                <label>Santri tuntas baik ≥ <input class="kb-input" style="width:80px" type="number" step="1" min="1" max="100" name="tuntas_batas" value="<?= kbFmt($settings['tuntas_batas'], 0) ?>" <?= $dis ?>> %</label>
                <?php if ($canEdit): ?><button class="kb-btn kb-btn-primary" type="submit"><i class="fa-solid fa-floppy-disk"></i> Simpan</button><?php endif; ?>
            </form>
        </div>

        <div class="kb-card" id="peta">
            <h3 class="kb-section-title"><span class="kb-ic kb-t-indigo" aria-hidden="true"><i class="fa-solid fa-link"></i></span>Pasangan guru dan data staf</h3>
            <p class="kb-section-sub">Otomatis lewat NIK, lalu nama. Pilih staf hanya jika hasilnya salah atau kosong.</p>
            <form method="POST">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="save_guru_map">
                <input type="hidden" name="unit_id" value="<?= (int)$ctx['unit_id'] ?>">
                <input type="hidden" name="ta" value="<?= (int)$ctx['ta_id'] ?>">
                <div class="kb-table-wrap">
                    <table class="kb-table">
                        <thead><tr><th>Guru di AIS</th><th>Hasil sekarang</th><th>Pasangkan dengan staf</th></tr></thead>
                        <tbody>
                        <?php foreach ($guru['rows'] as $r): $gid = (int)$r['guru_id']; ?>
                            <tr>
                                <td><b><?= e($r['guru_nama']) ?></b><div class="kb-muted" style="font-size:12px">NIK AIS: <?= e($r['guru_nik'] ?: '-') ?></div>
                                    <input type="hidden" name="map_nama[<?= $gid ?>]" value="<?= e($r['guru_nama']) ?>"></td>
                                <td><?= $r['staff_id'] ? e($r['staff_nama']) . ' <span class="kb-pill">' . e(['manual' => 'manual', 'nik' => 'lewat NIK', 'nama' => 'lewat nama'][$r['cocok_lewat']] ?? '') . '</span>'
                                    : '<span class="kb-pill kb-pill-warn">belum cocok</span>' ?></td>
                                <td><select class="kb-input kb-staff" name="map[<?= $gid ?>]" data-cur="<?= (int)($manual[$gid] ?? 0) ?>" <?= $dis ?>>
                                    <option value="0">Otomatis (NIK / nama)</option>
                                </select></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php if ($canEdit): ?><div style="margin-top:12px"><button class="kb-btn kb-btn-primary" type="submit"><i class="fa-solid fa-floppy-disk"></i> Simpan pemetaan</button></div><?php endif; ?>
            </form>
        </div>
        <script>
        var KB_CATALOG = <?= json_encode($catalog, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>;
        function kbTargetOpts(sel) {
            var pid = sel.getAttribute('data-pid'), tv = document.getElementById('kbTv' + pid), list = KB_CATALOG[sel.value] || {};
            var cur = tv.getAttribute('data-val'); tv.innerHTML = '';
            Object.keys(list).forEach(function (k) {
                var o = document.createElement('option'); o.value = k; o.textContent = list[k];
                if (String(k) === String(cur)) o.selected = true; tv.appendChild(o);
            });
            tv.setAttribute('data-val', tv.value);
        }
        document.querySelectorAll('select[data-pid]').forEach(kbTargetOpts);
        var KB_STAFF = <?= json_encode(array_map(static fn($s) => [(int)$s['id'], $s['name'] . ($s['nik'] ? ' · ' . $s['nik'] : '')], $staffList), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>;
        document.querySelectorAll('select.kb-staff').forEach(function (sel) {
            var cur = sel.getAttribute('data-cur'), frag = document.createDocumentFragment();
            KB_STAFF.forEach(function (s) {
                var o = document.createElement('option'); o.value = s[0]; o.textContent = s[1];
                if (String(s[0]) === cur) o.selected = true; frag.appendChild(o);
            });
            sel.appendChild(frag);
        });
        </script>
    <?php endif; ?>
<?php endif; ?>
</div>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
