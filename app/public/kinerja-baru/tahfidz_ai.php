<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../config/security.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/kinerja_baru_tahfidz.php';
require_once __DIR__ . '/../../includes/kinerja_baru_ui.php';

requireLogin();
requireMenuAccess('kinerja_baru_tahfidz_ai');

$canEdit = canEdit('kinerja_baru_tahfidz_ai');
$tab = in_array($_GET['tab'] ?? '', ['antrean', 'komparasi', 'koreksi'], true) ? (string)$_GET['tab'] : 'antrean';

// ---------- POST: jalankan AI, setujui/tolak, koreksi manual ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$canEdit) {
        http_response_code(403);
        die('403 - Akses Ditolak: Anda tidak memiliki izin mengelola analisis AI atau koreksi tahfidz.');
    }
    requireCsrf();
    $action = (string)($_POST['action'] ?? '');
    $pUnit = max(1, (int)($_POST['unit_id'] ?? 2));
    $pTa = max(0, (int)($_POST['ta'] ?? 0));
    $pTab = in_array($_POST['tab'] ?? '', ['antrean', 'komparasi', 'koreksi'], true) ? (string)$_POST['tab'] : 'antrean';
    kbAssertUnitPost($pdo, $pUnit);
    $flash = ['type' => 'error', 'text' => 'Aksi tidak dikenal.'];

    try {
        if ($action === 'run_ai_batch') {
            $probe = kbFetchTahfidzData($pUnit, $pTa ?: null, 0, false);
            if (!$probe['ok']) {
                $flash = ['type' => 'error', 'text' => 'Data AIS tidak dapat diambil untuk dianalisis.'];
            } else {
                $api = $probe['payload'];
                $effTa = (int)($api['meta']['selected_tahun_ajaran']['id'] ?? $pTa);
                $pre = applyTahfidzAiCacheToStudents($pdo, $api, $pUnit, $effTa, KB_AI_HANYA_TERVERIFIKASI);
                if (empty($pre['tables_ready'])) {
                    $flash = ['type' => 'error', 'text' => 'Tabel cache AI belum siap. Jalankan php database/migrate_penilaian_kinerja_tahfidz_ai.php.'];
                } elseif (empty($pre['pending_notes'])) {
                    $flash = ['type' => 'success', 'text' => 'Semua catatan guru pada periode ini sudah dianalisis AI.'];
                } else {
                    $res = extractTahfidzNotesWithAiBatch($pdo, $pre['pending_notes'], 20);
                    $flash = !empty($res['success']) || ($res['processed'] ?? 0) > 0
                        ? ['type' => 'success', 'text' => 'AI membaca ' . (int)($res['processed'] ?? 0) . ' catatan. Sisa: ' . (int)($res['remaining'] ?? 0) . '. Hasilnya menunggu persetujuan Anda.']
                        : ['type' => 'error', 'text' => (string)($res['error'] ?? 'Analisis AI gagal.')];
                }
            }
        } elseif ($action === 'review_ai_note') {
            $hash = trim((string)($_POST['note_hash'] ?? ''));
            $decision = (string)($_POST['decision'] ?? '');
            if (!preg_match('/^[a-f0-9]{64}$/', $hash) || !in_array($decision, ['verified', 'rejected'], true)) {
                $flash = ['type' => 'error', 'text' => 'Parameter tinjauan tidak valid.'];
            } else {
                $st = $pdo->prepare('UPDATE kinerja_tahfidz_ai_cache SET review_status = ?, reviewed_by = ? WHERE note_hash = ?');
                $st->execute([$decision, (int)currentUserId(), $hash]);
                $flash = ['type' => 'success', 'text' => $decision === 'verified'
                    ? 'Bacaan AI disetujui dan ikut dihitung di Capaian Tahfidz.'
                    : 'Bacaan AI ditolak dan tidak dihitung.'];
            }
        } elseif ($action === 'save_override' || $action === 'clear_override') {
            $sid = (int)($_POST['siswa_id'] ?? 0);
            if ($sid <= 0 || $pTa <= 0) {
                $flash = ['type' => 'error', 'text' => 'Santri atau tahun ajaran tidak valid.'];
            } elseif ($action === 'clear_override') {
                $pdo->prepare('DELETE FROM kinerja_tahfidz_student_overrides WHERE unit_id = ? AND tahun_ajaran_id = ? AND siswa_id = ?')
                    ->execute([$pUnit, $pTa, $sid]);
                $flash = ['type' => 'success', 'text' => 'Koreksi manual dihapus.'];
            } else {
                $surahKey = trim((string)($_POST['surah_key'] ?? ''));
                $juzOnly = (int)($_POST['juz_fallback'] ?? 0);
                $ayatEnd = (int)($_POST['ayat_end'] ?? 0) > 0 ? (int)$_POST['ayat_end'] : null;
                $munaq = !empty($_POST['is_munaqasyah']);
                $reason = mb_substr(trim(strip_tags((string)($_POST['reason'] ?? ''))), 0, 250);
                $conv = convertAiExtractionToRanks([
                    'is_achievement' => 1,
                    'surah_key'      => $surahKey !== '' ? $surahKey : null,
                    'juz'            => ($surahKey === '' && $juzOnly > 0) ? $juzOnly : null,
                    'ayat_end'       => $ayatEnd,
                    'is_munaqasyah'  => $munaq ? 1 : 0,
                    'confidence'     => 1.0,
                ]);
                if ($conv['juz_stage'] <= 0 && $conv['juz30_rank'] <= 0) {
                    $flash = ['type' => 'error', 'text' => 'Pilih surat atau juz capaian yang valid.'];
                } elseif ($reason === '') {
                    $flash = ['type' => 'error', 'text' => 'Alasan koreksi wajib diisi.'];
                } else {
                    $label = 'Koreksi: ' . ($conv['surah_label'] !== '' ? $conv['surah_label'] : 'Capaian disesuaikan');
                    $m30 = ($conv['juz'] === 30 && $munaq) || $conv['juz_stage'] >= 2 ? 1 : 0;
                    $m29 = ($conv['juz'] === 29 && $munaq) || $conv['juz_stage'] >= 3 ? 1 : 0;
                    $m28 = ($conv['juz'] === 28 && $munaq) ? 1 : 0;
                    $pdo->prepare('
                        INSERT INTO kinerja_tahfidz_student_overrides
                            (unit_id, tahun_ajaran_id, siswa_id, juz30_rank, juz29_rank, juz28_rank, juz_stage,
                             has_munaqasyah_juz30, has_munaqasyah_juz29, has_munaqasyah_juz28, display_label, reason, updated_by)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                        ON DUPLICATE KEY UPDATE juz30_rank = VALUES(juz30_rank), juz29_rank = VALUES(juz29_rank), juz28_rank = VALUES(juz28_rank),
                            juz_stage = VALUES(juz_stage), has_munaqasyah_juz30 = VALUES(has_munaqasyah_juz30),
                            has_munaqasyah_juz29 = VALUES(has_munaqasyah_juz29), has_munaqasyah_juz28 = VALUES(has_munaqasyah_juz28),
                            display_label = VALUES(display_label), reason = VALUES(reason), updated_by = VALUES(updated_by)
                    ')->execute([$pUnit, $pTa, $sid, $conv['juz30_rank'], $conv['juz29_rank'], $conv['juz28_rank'], $conv['juz_stage'],
                        $m30, $m29, $m28, $label, $reason, (int)currentUserId()]);
                    $flash = ['type' => 'success', 'text' => "Koreksi manual tersimpan ({$label})."];
                }
            }
        }
    } catch (Throwable $e) {
        error_log('[kinerja-baru/tahfidz_ai.php] ' . $e->getMessage());
        $flash = ['type' => 'error', 'text' => 'Gagal memproses permintaan. Pastikan tabel AI tahfidz sudah dimigrasi.'];
    }
    $_SESSION['kb_flash'] = $flash;
    $q = ['unit_id' => $pUnit, 'tab' => $pTab];
    if ($pTa) {
        $q['ta'] = $pTa;
    }
    header('Location: tahfidz_ai.php?' . http_build_query($q));
    exit;
}

$flash = $_SESSION['kb_flash'] ?? null;
unset($_SESSION['kb_flash']);

$pageTitle = 'Kinerja Baru · Catatan guru';
$ctx = kbLoadTahfidzPage($pdo, $_GET);
$aiConfigured = defined('TAHFIDZ_AI_ENABLED') && TAHFIDZ_AI_ENABLED && defined('TAHFIDZ_AI_API_KEY') && TAHFIDZ_AI_API_KEY !== '';

$bucketLabels = [
    'low_confidence' => ['Menunggu persetujuan', 'kb-pill-warn'],
    'pending'        => ['Belum dianalisis AI', ''],
    'verified'       => ['Disetujui', 'kb-pill-ok'],
    'rejected'       => ['Ditolak', 'kb-pill-bad'],
    'auto_ai'        => ['Bukan catatan capaian', ''],
];
$filter = array_key_exists($_GET['f'] ?? '', $bucketLabels) ? (string)$_GET['f'] : 'low_confidence';
$search = mb_strtolower(trim((string)($_GET['q'] ?? '')));
$keep = ['tab' => $tab];

require_once __DIR__ . '/../../includes/header.php';
kbUiStyles();
?>
<style>
.kb-note{border:1px solid var(--kb-line);border-radius:10px;padding:12px 14px;margin-bottom:10px;background:#fff}
.kb-note-raw{font-size:14px;font-weight:500;background:var(--kb-soft);border-radius:8px;padding:8px 10px;margin:6px 0;white-space:pre-wrap;overflow-wrap:anywhere}
.kb .kb-input{width:auto;border:1px solid #cbd5e1;border-radius:8px;padding:6px 10px;font-size:13px;min-height:34px;max-width:100%}
</style>
<div class="kb">
<?php if ($flash): ?>
    <div class="kb-alert <?= $flash['type'] === 'success' ? 'kb-alert-info' : 'kb-alert-bad' ?>"><i class="fa-solid <?= $flash['type'] === 'success' ? 'fa-circle-check' : 'fa-circle-exclamation' ?>"></i><div><?= e($flash['text']) ?></div></div>
<?php endif; ?>

<?php if (kbUiHeader($ctx, 'Catatan guru',
    'Catatan hafalan yang ditulis bebas oleh guru, dibaca dengan AI. Hasilnya baru dihitung setelah disetujui.', $keep)):
    $audit = fetchTahfidzAiNoteCatalogForAudit($pdo, $ctx['api_mentah'], KB_AI_HANYA_TERVERIFIKASI);
    $cnt = $audit['counts'];
?>

    <div class="kb-kpis">
        <div class="kb-kpi"><span class="kb-ic kb-t-amber" aria-hidden="true"><i class="fa-solid fa-hourglass-half"></i></span><div class="l">Menunggu persetujuan</div><div class="v" style="color:var(--kb-warn)"><?= (int)$cnt['low_confidence'] ?></div><div class="s">sudah dibaca AI, belum dihitung</div></div>
        <div class="kb-kpi"><span class="kb-ic kb-t-indigo" aria-hidden="true"><i class="fa-solid fa-inbox"></i></span><div class="l">Belum dianalisis</div><div class="v"><?= (int)$cnt['pending'] ?></div><div class="s">catatan baru dari guru</div></div>
        <div class="kb-kpi"><span class="kb-ic kb-t-green" aria-hidden="true"><i class="fa-solid fa-check"></i></span><div class="l">Disetujui</div><div class="v" style="color:var(--kb-ok)"><?= (int)$cnt['verified'] ?></div><div class="s">ikut dihitung</div></div>
        <div class="kb-kpi"><span class="kb-ic kb-t-rose" aria-hidden="true"><i class="fa-solid fa-xmark"></i></span><div class="l">Ditolak</div><div class="v"><?= (int)$cnt['rejected'] ?></div><div class="s">tidak dihitung</div></div>
    </div>

    <div class="kb-tabs">
        <a class="kb-tab <?= $tab === 'antrean' ? 'active' : '' ?>" href="<?= e(kbUrl($ctx, ['tab' => 'antrean'])) ?>">Antrean</a>
        <a class="kb-tab <?= $tab === 'komparasi' ? 'active' : '' ?>" href="<?= e(kbUrl($ctx, ['tab' => 'komparasi'])) ?>">Sebelum dan sesudah</a>
        <a class="kb-tab <?= $tab === 'koreksi' ? 'active' : '' ?>" href="<?= e(kbUrl($ctx, ['tab' => 'koreksi'])) ?>">Koreksi manual</a>
    </div>

    <?php if ($tab === 'antrean'):
        $items = array_values(array_filter($audit['items'], static function ($it) use ($filter, $search) {
            if (($it['audit_bucket'] ?? '') !== $filter) {
                return false;
            }
            if ($search === '') {
                return true;
            }
            $hay = mb_strtolower(($it['raw_text'] ?? '') . ' ' . ($it['kd_nama'] ?? '') . ' ' . ($it['materi'] ?? '') . ' '
                . implode(' ', array_map(static fn($s) => ($s['nama'] ?? ''), $it['student_samples'] ?? [])));
            return mb_strpos($hay, $search) !== false;
        }));
        $shown = array_slice($items, 0, 150);
    ?>
        <div class="kb-card">
            <div class="kb-head" style="align-items:center;margin-bottom:12px">
                <div class="kb-tabs" style="margin:0">
                    <?php foreach ($bucketLabels as $bk => [$bl, $_]): ?>
                        <a class="kb-tab <?= $filter === $bk ? 'active' : '' ?>" href="<?= e(kbUrl($ctx, ['tab' => 'antrean', 'f' => $bk])) ?>"><?= e($bl) ?> (<?= (int)($cnt[$bk] ?? 0) ?>)</a>
                    <?php endforeach; ?>
                </div>
                <div class="kb-filter">
                    <form method="GET" class="kb-filter">
                        <input type="hidden" name="unit_id" value="<?= (int)$ctx['unit_id'] ?>"><input type="hidden" name="ta" value="<?= (int)$ctx['ta_id'] ?>">
                        <input type="hidden" name="tab" value="antrean"><input type="hidden" name="f" value="<?= e($filter) ?>">
                        <input type="search" name="q" class="kb-search" placeholder="Cari catatan / santri…" value="<?= e((string)($_GET['q'] ?? '')) ?>">
                    </form>
                    <?php if ($canEdit && $aiConfigured && $cnt['pending'] > 0): ?>
                        <form method="POST">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="run_ai_batch"><input type="hidden" name="unit_id" value="<?= (int)$ctx['unit_id'] ?>">
                            <input type="hidden" name="ta" value="<?= (int)$ctx['ta_id'] ?>"><input type="hidden" name="tab" value="antrean">
                            <button class="kb-btn kb-btn-primary" type="submit">Baca 20 catatan dengan AI</button>
                        </form>
                    <?php elseif ($cnt['pending'] > 0 && !$aiConfigured): ?>
                        <span class="kb-pill kb-pill-warn">AI belum dikonfigurasi di .env server</span>
                    <?php endif; ?>
                </div>
            </div>

            <?php foreach ($shown as $it): ?>
                <div class="kb-note">
                    <div style="display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap">
                        <span class="kb-muted" style="font-size:12px"><?= e(trim(($it['kd_nama'] ?? '') . ' · ' . ($it['materi'] ?? ''), ' ·')) ?: 'Catatan guru' ?></span>
                        <span class="kb-pill <?= $bucketLabels[$it['audit_bucket']][1] ?? '' ?>"><?= e($bucketLabels[$it['audit_bucket']][0] ?? $it['audit_bucket']) ?></span>
                    </div>
                    <div class="kb-note-raw"><?= e((string)$it['raw_text']) ?></div>
                    <?php if (!empty($it['in_cache'])): ?>
                        <div style="font-size:13px">Bacaan AI: <b><?= e((string)$it['surah_label']) ?></b>
                            <span class="kb-muted">· keyakinan <?= (int)$it['confidence_pct'] ?>%<?= !empty($it['ai_reason']) ? ' · ' . e((string)$it['ai_reason']) : '' ?></span></div>
                    <?php endif; ?>
                    <div class="kb-muted" style="font-size:12px;margin-top:4px">
                        <?= (int)$it['student_count'] ?> santri: <?= e(implode(', ', array_map(static fn($s) => ($s['nama'] ?? '') . (!empty($s['rombel_nama']) ? ' (' . $s['rombel_nama'] . ')' : ''), array_slice($it['student_samples'] ?? [], 0, 5)))) ?><?= $it['student_count'] > 5 ? ', …' : '' ?>
                    </div>
                    <?php if ($canEdit && !empty($it['in_cache']) && preg_match('/^[a-f0-9]{64}$/', (string)$it['note_hash'])): ?>
                        <div style="display:flex;gap:8px;margin-top:10px;flex-wrap:wrap">
                            <?php foreach (['verified' => ['Setujui', 'kb-btn-primary', 'fa-check'], 'rejected' => ['Tolak', 'kb-btn-ghost', 'fa-xmark']] as $dec => [$lbl, $cls, $ic]):
                                if ($it['review_status'] === $dec) continue; ?>
                                <form method="POST">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="action" value="review_ai_note"><input type="hidden" name="decision" value="<?= $dec ?>">
                                    <input type="hidden" name="note_hash" value="<?= e((string)$it['note_hash']) ?>">
                                    <input type="hidden" name="unit_id" value="<?= (int)$ctx['unit_id'] ?>"><input type="hidden" name="ta" value="<?= (int)$ctx['ta_id'] ?>">
                                    <input type="hidden" name="tab" value="antrean">
                                    <button class="kb-btn <?= $cls ?>" type="submit"><i class="fa-solid <?= $ic ?>"></i> <?= $lbl ?></button>
                                </form>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
            <?php if (!$items): ?><div class="kb-empty">Tidak ada catatan pada kelompok ini.</div><?php endif; ?>
            <?php if (count($items) > count($shown)): ?><p class="kb-muted" style="font-size:12.5px">Menampilkan 150 dari <?= count($items) ?> catatan. Gunakan pencarian untuk mempersempit.</p><?php endif; ?>
        </div>

    <?php elseif ($tab === 'komparasi'):
        $cmp = buildTahfidzAiComparisonData($ctx['hierarki_mentah'], $ctx['hierarki']);
        $ud = $cmp['unit_delta'];
    ?>
        <div class="kb-card">
            <h3 class="kb-section-title"><span class="kb-ic kb-t-indigo" aria-hidden="true"><i class="fa-solid fa-code-compare"></i></span>Ketuntasan sebelum dan sesudah</h3>
            <p class="kb-section-sub">Sebelum: data AIS saja. Sesudah: ditambah bacaan AI yang disetujui dan koreksi manual.</p>
            <div class="kb-table-wrap">
                <table class="kb-table">
                    <thead><tr><th>Paralel</th><th>Target</th><th class="num">Santri</th><th class="num">Sebelum</th><th class="num">Sesudah</th><th class="num">Selisih</th></tr></thead>
                    <tbody>
                    <?php foreach ($cmp['paralels'] as $pr): ?>
                        <tr><td><b><?= e($pr['paralel_nama']) ?></b></td><td class="kb-muted"><?= e($pr['target_label']) ?></td>
                            <td class="num"><?= (int)$pr['total_students'] ?></td>
                            <td class="num"><?= kbFmt((float)$pr['before_pct'], 1) ?>% <span class="kb-muted">(<?= (int)$pr['before_achieved'] ?>)</span></td>
                            <td class="num"><b><?= kbFmt((float)$pr['after_pct'], 1) ?>%</b> <span class="kb-muted">(<?= (int)$pr['after_achieved'] ?>)</span></td>
                            <td class="num" style="color:<?= $pr['delta_pct'] > 0 ? 'var(--kb-ok)' : 'inherit' ?>"><?= ($pr['delta_pct'] > 0 ? '+' : '') . kbFmt((float)$pr['delta_pct'], 1) ?></td></tr>
                    <?php endforeach; ?>
                    <tr style="background:var(--kb-soft)"><td colspan="2"><b>Unit</b></td><td class="num"><?= (int)$ud['total_students'] ?></td>
                        <td class="num"><?= kbFmt((float)$ud['before_pct'], 1) ?>%</td><td class="num"><b><?= kbFmt((float)$ud['after_pct'], 1) ?>%</b></td>
                        <td class="num"><?= ($ud['delta_pct'] > 0 ? '+' : '') . kbFmt((float)$ud['delta_pct'], 1) ?></td></tr>
                    </tbody>
                </table>
            </div>
        </div>
        <div class="kb-card">
            <h3 class="kb-section-title"><span class="kb-ic kb-t-teal" aria-hidden="true"><i class="fa-solid fa-user-pen"></i></span>Santri yang capaiannya berubah (<?= count($cmp['changed_students']) ?>)</h3>
            <div class="kb-table-wrap">
                <table class="kb-table">
                    <thead><tr><th>Santri</th><th>Sebelum</th><th>Sesudah</th><th>Sumber</th></tr></thead>
                    <tbody>
                    <?php foreach ($cmp['changed_students'] as $cs): ?>
                        <tr><td><b><?= e($cs['nama']) ?></b><div class="kb-muted" style="font-size:12px"><?= e($cs['paralel_nama'] . ' · ' . $cs['rombel_nama']) ?></div></td>
                            <td class="kb-muted"><?= e($cs['before_display']) ?></td>
                            <td><?= e($cs['after_display']) ?> <?= $cs['flipped_achieved'] ? '<span class="kb-pill kb-pill-ok">jadi tuntas</span>' : '' ?></td>
                            <td><span class="kb-pill <?= $cs['ai_badge'] === 'manual_override' ? 'kb-pill-warn' : 'kb-pill-ok' ?>"><?= $cs['ai_badge'] === 'manual_override' ? 'Koreksi manual' : 'AI disetujui' ?></span></td></tr>
                    <?php endforeach; ?>
                    <?php if (!$cmp['changed_students']): ?><tr><td colspan="4" class="kb-empty">Belum ada perubahan — setujui bacaan AI di tab Antrean atau isi koreksi manual.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

    <?php else: /* KOREKSI MANUAL */
        $dict = getTahfidzAiSurahDictionary();
        $students = $ctx['api_mentah']['students'] ?? [];
        usort($students, static fn($a, $b) => [(int)$a['paralel_id'], (string)($a['rombel_nama'] ?? ''), (string)$a['nama']] <=> [(int)$b['paralel_id'], (string)($b['rombel_nama'] ?? ''), (string)$b['nama']]);
        $overrides = [];
        try {
            $st = $pdo->prepare('SELECT siswa_id, display_label, reason, updated_at FROM kinerja_tahfidz_student_overrides WHERE unit_id = ? AND tahun_ajaran_id = ? ORDER BY updated_at DESC');
            $st->execute([(int)$ctx['unit_id'], (int)$ctx['ta_id']]);
            $overrides = $st->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
        }
        $namaById = [];
        foreach ($students as $s) {
            $namaById[(int)$s['siswa_id']] = $s['nama'] . ' · ' . ($s['paralel_nama'] ?? '') . ' ' . ($s['rombel_nama'] ?? '');
        }
        $dis = $canEdit ? '' : 'disabled';
    ?>
        <div class="kb-card">
            <h3 class="kb-section-title"><span class="kb-ic kb-t-amber" aria-hidden="true"><i class="fa-solid fa-pen-to-square"></i></span>Koreksi capaian santri</h3>
            <p class="kb-section-sub">Untuk capaian yang keliru. Koreksi menggantikan data AIS santri itu pada tahun ajaran ini.</p>
            <form method="POST" class="kb-filter" style="align-items:flex-end">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="save_override"><input type="hidden" name="tab" value="koreksi">
                <input type="hidden" name="unit_id" value="<?= (int)$ctx['unit_id'] ?>"><input type="hidden" name="ta" value="<?= (int)$ctx['ta_id'] ?>">
                <label style="display:flex;flex-direction:column;gap:4px;font-size:12.5px">Santri
                    <select class="kb-input" name="siswa_id" required <?= $dis ?>>
                        <option value="">— pilih santri —</option>
                        <?php foreach ($students as $s): ?>
                            <option value="<?= (int)$s['siswa_id'] ?>"><?= e($s['nama'] . ' · ' . ($s['paralel_nama'] ?? '') . ' ' . ($s['rombel_nama'] ?? '')) ?></option>
                        <?php endforeach; ?>
                    </select></label>
                <label style="display:flex;flex-direction:column;gap:4px;font-size:12.5px">Capaian sampai surat
                    <select class="kb-input" name="surah_key" <?= $dis ?>>
                        <option value="">— pilih surat —</option>
                        <?php foreach ($dict['surahs'] as $key => $info): ?>
                            <option value="<?= e((string)$key) ?>">Juz <?= (int)$info['juz'] ?> · <?= e((string)$info['label']) ?></option>
                        <?php endforeach; ?>
                    </select></label>
                <label style="display:flex;flex-direction:column;gap:4px;font-size:12.5px">atau satu juz penuh
                    <select class="kb-input" name="juz_fallback" <?= $dis ?>><option value="0">—</option><option value="30">Juz 30</option><option value="29">Juz 29</option><option value="28">Juz 28</option></select></label>
                <label style="display:flex;flex-direction:column;gap:4px;font-size:12.5px">Sampai ayat
                    <input class="kb-input" style="width:90px" type="number" min="1" name="ayat_end" <?= $dis ?>></label>
                <label style="font-size:12.5px"><input type="checkbox" name="is_munaqasyah" value="1" <?= $dis ?>> Sudah munaqasyah</label>
                <label style="display:flex;flex-direction:column;gap:4px;font-size:12.5px;flex:1;min-width:200px">Alasan (wajib)
                    <input class="kb-input" style="width:100%" type="text" name="reason" maxlength="250" required <?= $dis ?>></label>
                <?php if ($canEdit): ?><button class="kb-btn kb-btn-primary" type="submit"><i class="fa-solid fa-floppy-disk"></i> Simpan koreksi</button><?php endif; ?>
            </form>
        </div>
        <div class="kb-card">
            <h3 class="kb-section-title"><span class="kb-ic kb-t-teal" aria-hidden="true"><i class="fa-solid fa-list-check"></i></span>Koreksi yang berlaku (<?= count($overrides) ?>)</h3>
            <div class="kb-table-wrap">
                <table class="kb-table">
                    <thead><tr><th>Santri</th><th>Capaian koreksi</th><th>Alasan</th><th>Diubah</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($overrides as $ov): ?>
                        <tr><td><?= e($namaById[(int)$ov['siswa_id']] ?? ('Santri #' . (int)$ov['siswa_id'])) ?></td>
                            <td><b><?= e((string)$ov['display_label']) ?></b></td><td class="kb-muted"><?= e((string)$ov['reason']) ?></td>
                            <td class="kb-muted"><?= e(date('d-m-Y H:i', strtotime((string)$ov['updated_at']))) ?></td>
                            <td><?php if ($canEdit): ?>
                                <form method="POST" onsubmit="return confirm('Hapus koreksi ini?')">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="action" value="clear_override"><input type="hidden" name="tab" value="koreksi">
                                    <input type="hidden" name="siswa_id" value="<?= (int)$ov['siswa_id'] ?>">
                                    <input type="hidden" name="unit_id" value="<?= (int)$ctx['unit_id'] ?>"><input type="hidden" name="ta" value="<?= (int)$ctx['ta_id'] ?>">
                                    <button class="kb-btn kb-btn-ghost" type="submit"><i class="fa-solid fa-trash"></i> Hapus</button>
                                </form><?php endif; ?></td></tr>
                    <?php endforeach; ?>
                    <?php if (!$overrides): ?><tr><td colspan="5" class="kb-empty">Belum ada koreksi manual.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>
<?php endif; ?>
</div>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
