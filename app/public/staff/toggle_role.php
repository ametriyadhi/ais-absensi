<?php
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../config/security.php';
require_once '../../config/auth.php';
require_once '../../includes/functions.php';

requireSuperAdmin();
requireCsrf();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('index.php');
}

$staffId = (int)($_POST['id'] ?? 0);

// Cari user_id yang terhubung dengan staff ini
$stmt = $pdo->prepare("SELECT user_id, name FROM staff WHERE id = ? LIMIT 1");
$stmt->execute([$staffId]);
$staff = $stmt->fetch();

if (!$staff || empty($staff['user_id'])) {
    flash('error', 'Gagal merubah role. Akun pengguna tidak ditemukan.');
    redirect('index.php');
    exit;
}

// Cek role pengguna saat ini
$stmtUser = $pdo->prepare("SELECT role FROM users WHERE id = ? LIMIT 1");
$stmtUser->execute([$staff['user_id']]);
$user = $stmtUser->fetch();

if ($user) {
    // Balikkan (Toggle) Role-nya
    $newRole = ($user['role'] === 'admin') ? 'staff' : 'admin';
    
    $updateStmt = $pdo->prepare("UPDATE users SET role = ? WHERE id = ?");
    $updateStmt->execute([$newRole, $staff['user_id']]);
    
    // Jika diturunkan jadi staff, bersihkan akses kelas/grade admin sebelumnya
    if ($newRole === 'staff') {
        $pdo->prepare("DELETE FROM admin_grade_permissions WHERE user_id = ?")->execute([$staff['user_id']]);
        $pdo->prepare("DELETE FROM admin_class_permissions WHERE user_id = ?")->execute([$staff['user_id']]);
    }

    logAudit($pdo, 'toggle_role', 'users', $staff['user_id'], ['staff_name' => $staff['name'], 'new_role' => $newRole]);
    flash('success', "Akses untuk " . $staff['name'] . " berhasil diubah menjadi " . ucfirst($newRole) . ".");
} else {
    flash('error', 'Terjadi kesalahan saat memproses data pengguna.');
}

redirect('index.php');