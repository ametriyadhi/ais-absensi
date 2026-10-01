-- ==============================================================================
-- AIS ABSENSI - MIGRATION SCRIPT
-- Update Role Kepala Sekolah, Status Absensi, dan Hak Akses Unit
-- ==============================================================================

USE `library_v4`;

-- 1. Tambahkan role 'kepala_sekolah' pada tabel users
ALTER TABLE users 
MODIFY COLUMN role ENUM('super_admin', 'admin', 'staff', 'kepala_sekolah') NOT NULL DEFAULT 'staff';

-- 2. Update tabel student_attendances (Tambah status izin/sakit dan kolom keterangan)
ALTER TABLE student_attendances 
MODIFY COLUMN status ENUM('tepat_waktu', 'terlambat', 'izin', 'sakit') NOT NULL,
ADD COLUMN IF NOT EXISTS keterangan VARCHAR(255) NULL AFTER status;

-- 3. Update tabel staff_attendances (Tambah status izin/sakit dan kolom keterangan)
ALTER TABLE staff_attendances 
MODIFY COLUMN status ENUM('tepat_waktu', 'terlambat', 'izin', 'sakit') NOT NULL,
ADD COLUMN IF NOT EXISTS keterangan VARCHAR(255) NULL AFTER status;

-- 4. Buat tabel baru untuk hak akses Kepala Sekolah (berdasarkan Unit)
CREATE TABLE IF NOT EXISTS admin_unit_permissions (
    id BIGINT(20) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT(20) UNSIGNED NOT NULL,
    unit_id INT(10) UNSIGNED NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_admin_unit_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_admin_unit_unit FOREIGN KEY (unit_id) REFERENCES units(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- 5. Buat tabel system_settings jika belum ada (untuk konfigurasi hak akses Kepala Sekolah)
CREATE TABLE IF NOT EXISTS system_settings (
    setting_key VARCHAR(50) PRIMARY KEY,
    setting_value VARCHAR(255) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO system_settings (setting_key, setting_value) 
VALUES ('ks_delete_attendance', '0'), ('ks_edit_time', '0')
ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value);
