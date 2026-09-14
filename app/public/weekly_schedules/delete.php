<?php
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../config/security.php';
require_once '../../config/auth.php';
require_once '../../includes/functions.php';

requireSuperAdmin();
requireCsrf();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int)($_POST['id'] ?? 0);
    
    if ($id > 0) {
        $stmt = $pdo->prepare("DELETE FROM weekly_schedules WHERE id = ?");
        $stmt->execute([$id]);
        logAudit($pdo, 'delete', 'weekly_schedules', $id);
        flash('success', 'Jadwal pekanan berhasil dihapus.');
    }
}

header('Location: index.php');
exit;