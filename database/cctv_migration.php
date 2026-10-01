<?php
/**
 * Migration: Data CCTV, Lokasi Utama & Sub (Lantai), dan Jalur Hik-Connect Web
 */
require_once __DIR__ . '/../config/database.php';

echo "=== MEMULAI MIGRASI DATA CCTV & HIK-CONNECT ===\n";

try {
    // 1. Buat tabel cctv_locations (Utama & Sub / Lantai)
    $sqlLocations = "
    CREATE TABLE IF NOT EXISTS `cctv_locations` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `parent_id` INT UNSIGNED NULL DEFAULT NULL COMMENT 'NULL = Lokasi Utama (Gedung/Area), NOT NULL = Sub Lokasi (Lantai/Zona)',
        `name` VARCHAR(100) NOT NULL,
        `code` VARCHAR(50) NULL,
        `sort_order` INT NOT NULL DEFAULT 0,
        `description` VARCHAR(255) NULL,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_parent_id` (`parent_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
    ";
    $pdo->exec($sqlLocations);
    echo "[+] Tabel 'cctv_locations' siap.\n";

    // 2. Buat tabel cctv_devices (Titik Kamera CCTV)
    $sqlDevices = "
    CREATE TABLE IF NOT EXISTS `cctv_devices` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `location_id` INT UNSIGNED NOT NULL COMMENT 'Relasi ke cctv_locations (Lantai/Sub-lokasi)',
        `dvr_server` VARCHAR(100) NULL,
        `room_name` VARCHAR(150) NULL,
        `floor_name` VARCHAR(100) NULL,
        `name` VARCHAR(150) NOT NULL,
        `channel_number` INT NULL DEFAULT NULL,
        `sort_order` INT NOT NULL DEFAULT 0,
        `ip_address` VARCHAR(50) NULL DEFAULT NULL,
        `hikconnect_url` VARCHAR(500) NULL DEFAULT NULL,
        `device_serial` VARCHAR(100) NULL DEFAULT NULL,
        `status` ENUM('normal', 'error', 'maintenance') NOT NULL DEFAULT 'normal',
        `error_type` VARCHAR(100) NULL,
        `error_notes` TEXT NULL,
        `error_date` DATETIME NULL,
        `location_detail` VARCHAR(255) NULL DEFAULT NULL,
        `notes` TEXT NULL DEFAULT NULL,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_location_id` (`location_id`),
        KEY `idx_sort_order` (`sort_order`),
        KEY `idx_status` (`status`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
    ";
    $pdo->exec($sqlDevices);
    echo "[+] Tabel 'cctv_devices' siap.\n";

    // Tambah kolom ke cctv_devices jika tabel lama sudah ada
    $colsCheck = [
        'dvr_server' => "ALTER TABLE `cctv_devices` ADD COLUMN IF NOT EXISTS `dvr_server` VARCHAR(100) NULL AFTER `location_id`",
        'room_name' => "ALTER TABLE `cctv_devices` ADD COLUMN IF NOT EXISTS `room_name` VARCHAR(150) NULL AFTER `dvr_server`",
        'floor_name' => "ALTER TABLE `cctv_devices` ADD COLUMN IF NOT EXISTS `floor_name` VARCHAR(100) NULL AFTER `room_name`",
        'error_type' => "ALTER TABLE `cctv_devices` ADD COLUMN IF NOT EXISTS `error_type` VARCHAR(100) NULL AFTER `ip_address`",
        'error_notes' => "ALTER TABLE `cctv_devices` ADD COLUMN IF NOT EXISTS `error_notes` TEXT NULL AFTER `error_type`",
        'error_date' => "ALTER TABLE `cctv_devices` ADD COLUMN IF NOT EXISTS `error_date` DATETIME NULL AFTER `error_notes`",
    ];
    foreach ($colsCheck as $col => $sql) {
        $pdo->exec($sql);
    }
    $pdo->exec("ALTER TABLE `cctv_devices` MODIFY COLUMN `status` ENUM('normal', 'error', 'maintenance', 'active', 'inactive') NOT NULL DEFAULT 'normal'");

    // 3. Buat tabel cctv_settings (Konfigurasi URL Hik-Connect dsb)
    $sqlSettings = "
    CREATE TABLE IF NOT EXISTS `cctv_settings` (
        `key_name` VARCHAR(50) NOT NULL PRIMARY KEY,
        `key_value` TEXT NULL,
        `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
    ";
    $pdo->exec($sqlSettings);
    echo "[+] Tabel 'cctv_settings' siap.\n";

    // Default Hik-Connect Web URL
    $stmtSet = $pdo->prepare("
        INSERT INTO `cctv_settings` (`key_name`, `key_value`)
        VALUES ('hikconnect_url', 'https://www.hik-connect.com')
        ON DUPLICATE KEY UPDATE `key_value` = VALUES(`key_value`)
    ");
    $stmtSet->execute();
    echo "[+] Setting default 'hikconnect_url' tersimpan.\n";

    // 4. Tambah Menu Baru 'data_cctv' ke app_menus
    $stmtCheckMenu = $pdo->prepare("SELECT id FROM app_menus WHERE menu_key = 'data_cctv' LIMIT 1");
    $stmtCheckMenu->execute();
    $existingMenu = $stmtCheckMenu->fetch();

    if (!$existingMenu) {
        $stmtInsertMenu = $pdo->prepare("
            INSERT INTO `app_menus` (`menu_key`, `menu_name`, `category`, `menu_url`, `menu_icon`, `sort_order`, `is_active`)
            VALUES ('data_cctv', 'Data CCTV', 'PENGATURAN', 'cctv/index.php', 'fa-video', 20, 1)
        ");
        $stmtInsertMenu->execute();
        $menuId = (int)$pdo->lastInsertId();
        echo "[+] Menu 'data_cctv' (id: {$menuId}) berhasil dibuat di app_menus.\n";
    } else {
        $menuId = (int)$existingMenu['id'];
        $stmtUpdateMenu = $pdo->prepare("
            UPDATE `app_menus`
            SET `menu_name` = 'Data CCTV',
                `category` = 'PENGATURAN',
                `menu_url` = 'cctv/index.php',
                `menu_icon` = 'fa-video',
                `sort_order` = 20,
                `is_active` = 1
            WHERE `id` = ?
        ");
        $stmtUpdateMenu->execute([$menuId]);
        echo "[=] Menu 'data_cctv' (id: {$menuId}) diperbarui di app_menus.\n";
    }

    // 5. Beri hak akses ke role 'super_admin'
    $stmtRole = $pdo->query("SELECT id FROM roles WHERE role_key = 'super_admin' LIMIT 1");
    $superAdminRoleId = $stmtRole->fetchColumn();

    if ($superAdminRoleId && $menuId) {
        $stmtRma = $pdo->prepare("
            INSERT IGNORE INTO `role_menu_access` (`role_id`, `menu_id`)
            VALUES (?, ?)
        ");
        $stmtRma->execute([$superAdminRoleId, $menuId]);
        echo "[+] Akses menu diberikan ke role 'super_admin' di role_menu_access.\n";

        // Sinkronkan ke user_menu_access bagi user super_admin
        $stmtUma = $pdo->prepare("
            INSERT IGNORE INTO `user_menu_access` (`user_id`, `menu_id`)
            SELECT id, ? FROM `users` WHERE `role` = 'super_admin'
        ");
        $stmtUma->execute([$menuId]);
        echo "[+] Akses menu disinkronkan ke user super_admin di user_menu_access.\n";
    }

    echo "\n=== MIGRASI CCTV SELESAI DENGAN SUKSES! ===\n";
} catch (Exception $e) {
    echo "[-] ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
