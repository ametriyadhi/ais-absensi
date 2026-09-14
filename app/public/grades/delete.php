<?php
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../config/auth.php';
require_once '../../config/security.php';
require_once '../../includes/functions.php';

requireSuperAdmin();
requireCsrf();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('index.php');
}

$id = (int)($_POST['id'] ?? 0);

// Pengecekan keamanan, jangan hapus jika masih ada relasi subkelas
$stmt = $pdo->prepare("SELECT COUNT(*) FROM class_groups WHERE grade_id = ?");
$stmt->execute([$id]);
$count = (int)$stmt->fetchColumn();

if ($count > 0) {
    flash('error', 'Grade tidak bisa dihapus karena masih terhubung dengan subkelas aktif.');
    redirect('index.php');
    exit;
}

$stmt = $pdo->prepare("DELETE FROM grades WHERE id = ?");
$stmt->execute([$id]);

logAudit($pdo, 'delete', 'grades', $id);

flash('success', 'Grade berhasil dihapus.');
redirect('index.php');