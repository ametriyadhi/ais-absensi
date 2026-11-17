<?php
/**
 * Migrasi: Menu "Penilaian Kinerja Baru" (bab terpisah dari "Penilaian Kinerja" lama).
 *
 * Mendaftarkan menu induk kinerja_baru_parent + 5 sub-bab:
 *   1. kinerja_baru_paralel    — Nilai Rata-rata Per Paralel     (kinerja-baru/index.php)
 *   2. kinerja_baru_mapel      — Mapel Tertinggi & Terendah      (kinerja-baru/mapel.php)
 *   3. kinerja_baru_all_data   — ALL Data                        (kinerja-baru/all_data.php)
 *   4. kinerja_baru_tahfidz    — Capaian Tahfidz & Jurnal Guru   (kinerja-baru/tahfidz.php)
 *   5. kinerja_baru_tahfidz_ai — Analisis Catatan AI Tahfidz     (kinerja-baru/tahfidz_ai.php)
 * Serta tabel milik bab baru: kinerja_baru_tahfidz_targets (disalin sekali dari target lama),
 * kinerja_baru_guru_map (pemetaan guru AIS -> staf absensi), kinerja_baru_settings (batas kuadran).
 *
 * Hak akses bawaan: hanya Super Admin. Role lain dibuka lewat Matriks Hak Akses.
 * Aman dijalankan berulang (idempoten). Menu "Penilaian Kinerja" lama tidak disentuh.
 *
 * Jalankan: php database/migrate_kinerja_baru_menu.php
 */

require_once __DIR__ . '/../config/database.php';

echo "=== MIGRASI MENU PENILAIAN KINERJA BARU ===\n";

$category = 'PENILAIAN KINERJA BARU';

$upsertMenu = static function (PDO $pdo, string $key, string $name, string $url, string $icon, ?int $parentId, int $sort) use ($category): int {
    $stmt = $pdo->prepare('SELECT id FROM app_menus WHERE menu_key = ? LIMIT 1');
    $stmt->execute([$key]);
    $id = (int)$stmt->fetchColumn();
    if ($id) {
        $pdo->prepare('UPDATE app_menus SET menu_name = ?, category = ?, menu_url = ?, menu_icon = ?, parent_id = ?, sort_order = ?, is_active = 1 WHERE id = ?')
            ->execute([$name, $category, $url, $icon, $parentId, $sort, $id]);
        echo "[OK] Menu '{$key}' diperbarui (ID {$id}).\n";
        return $id;
    }
    $pdo->prepare('INSERT INTO app_menus (menu_key, menu_name, category, menu_url, menu_icon, parent_id, sort_order, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, 1)')
        ->execute([$key, $name, $category, $url, $icon, $parentId, $sort]);
    $id = (int)$pdo->lastInsertId();
    echo "[OK] Menu '{$key}' dibuat (ID {$id}).\n";
    return $id;
};

try {
    // ---- Tabel milik bab baru (DDL dijalankan di luar transaksi) ----
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS kinerja_baru_tahfidz_targets (
            id INT NOT NULL AUTO_INCREMENT,
            unit_id INT NOT NULL,
            paralel_id INT NOT NULL,
            paralel_nama VARCHAR(100) NOT NULL,
            semester TINYINT NOT NULL DEFAULT 1,
            target_type ENUM('juz30_rank','juz29_rank','juz28_rank','juz29_coverage','juz28_coverage','juz_stage','teacher_individual') NOT NULL DEFAULT 'juz30_rank',
            target_value DECIMAL(6,2) NOT NULL DEFAULT 13.00,
            target_label VARCHAR(150) NOT NULL,
            require_munaqasyah TINYINT(1) NOT NULL DEFAULT 0,
            updated_by INT NULL,
            updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_kb_unit_paralel_sem (unit_id, paralel_id, semester)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS kinerja_baru_guru_map (
            ais_guru_id INT NOT NULL,
            ais_guru_nama VARCHAR(150) NOT NULL DEFAULT '',
            staff_id BIGINT UNSIGNED NOT NULL,
            updated_by INT NULL,
            updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (ais_guru_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS kinerja_baru_settings (
            setting_key VARCHAR(64) NOT NULL,
            setting_value VARCHAR(255) NOT NULL,
            updated_by INT NULL,
            updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (setting_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo "[OK] Tabel kinerja_baru_tahfidz_targets, kinerja_baru_guru_map, kinerja_baru_settings siap.\n";

    // Salin target tahfidz dari bab lama SEKALI saja (hanya jika tabel baru masih kosong)
    $hasOld = (bool)$pdo->query("SHOW TABLES LIKE 'kinerja_tahfidz_targets'")->fetchColumn();
    $newCount = (int)$pdo->query('SELECT COUNT(*) FROM kinerja_baru_tahfidz_targets')->fetchColumn();
    if ($hasOld && $newCount === 0) {
        $copied = $pdo->exec("
            INSERT INTO kinerja_baru_tahfidz_targets
                (unit_id, paralel_id, paralel_nama, semester, target_type, target_value, target_label, require_munaqasyah, updated_by)
            SELECT unit_id, paralel_id, paralel_nama, semester, target_type, target_value, target_label, require_munaqasyah, updated_by
            FROM kinerja_tahfidz_targets
        ");
        echo "[OK] {$copied} target tahfidz disalin dari bab lama sebagai titik awal.\n";
    }

    $pdo->beginTransaction();

    // Urutan: tepat setelah menu induk "Penilaian Kinerja" lama
    $stmtOld = $pdo->prepare("SELECT sort_order FROM app_menus WHERE menu_key = 'kinerja_parent' LIMIT 1");
    $stmtOld->execute();
    $oldSort = $stmtOld->fetchColumn();
    $parentSort = $oldSort !== false ? ((int)$oldSort + 1) : 24;

    $parentId = $upsertMenu($pdo, 'kinerja_baru_parent', 'Penilaian Kinerja Baru', '#', 'fa-chart-column', null, $parentSort);

    $children = [
        ['kinerja_baru_paralel',  'Nilai Rata-rata Per Paralel', 'kinerja-baru/index.php',    'fa-ranking-star',        1],
        ['kinerja_baru_mapel',    'Mapel Tertinggi & Terendah',  'kinerja-baru/mapel.php',    'fa-arrow-up-right-dots', 2],
        ['kinerja_baru_all_data', 'ALL Data',                    'kinerja-baru/all_data.php', 'fa-users-viewfinder',    3],
        ['kinerja_baru_tahfidz',    'Capaian Tahfidz & Jurnal Guru', 'kinerja-baru/tahfidz.php',    'fa-book-quran', 4],
        ['kinerja_baru_tahfidz_ai', 'Analisis Catatan AI Tahfidz',   'kinerja-baru/tahfidz_ai.php', 'fa-robot',      5],
    ];
    $menuIds = [$parentId];
    foreach ($children as [$key, $name, $url, $icon, $sort]) {
        $menuIds[] = $upsertMenu($pdo, $key, $name, $url, $icon, $parentId, $sort);
    }

    // Hak akses: Super Admin penuh
    $stmtSuper = $pdo->prepare("SELECT id FROM roles WHERE role_key = 'super_admin' LIMIT 1");
    $stmtSuper->execute();
    $superRoleId = (int)$stmtSuper->fetchColumn();
    if (!$superRoleId) {
        throw new RuntimeException("Role 'super_admin' tidak ditemukan.");
    }
    $stmtHas = $pdo->prepare('SELECT 1 FROM role_menu_access WHERE role_id = ? AND menu_id = ? LIMIT 1');
    $stmtIns = $pdo->prepare('INSERT INTO role_menu_access (role_id, menu_id, can_read, can_create, can_edit, can_delete) VALUES (?, ?, 1, 1, 1, 1)');
    $stmtUpd = $pdo->prepare('UPDATE role_menu_access SET can_read = 1, can_create = 1, can_edit = 1, can_delete = 1 WHERE role_id = ? AND menu_id = ?');
    foreach ($menuIds as $mId) {
        $stmtHas->execute([$superRoleId, $mId]);
        if ($stmtHas->fetchColumn()) {
            $stmtUpd->execute([$superRoleId, $mId]);
        } else {
            $stmtIns->execute([$superRoleId, $mId]);
        }
    }
    echo "[OK] Akses Super Admin diaktifkan untuk " . count($menuIds) . " menu.\n";
    echo "     Role lain belum diberi akses — atur lewat Kelola > Matriks Hak Akses.\n";

    $pdo->commit();
    echo "=== SELESAI ===\n";
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo 'ERROR: ' . $e->getMessage() . "\n";
    exit(1);
}
