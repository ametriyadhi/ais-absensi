<?php
session_start();
require_once '../config/database.php';
require_once '../config/security.php';
require_once '../config/auth.php';
require_once '../includes/functions.php';

requireLogin();
requireCsrf();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
    $userId = currentUserId();
    
    $allowedMimes = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
    ];
    
    if ($_FILES['photo']['size'] > 2 * 1024 * 1024) {
        flash('error', 'Ukuran foto maksimal 2 MB.');
    } else {
        $mime = mime_content_type($_FILES['photo']['tmp_name']);
        if (!isset($allowedMimes[$mime])) {
            flash('error', 'Format foto tidak valid. Hanya JPG, PNG, atau WEBP yang diizinkan.');
        } else {
            // Tarik nama foto lama untuk dihapus
            $stmt = $pdo->prepare("SELECT photo FROM users WHERE id = ?");
            $stmt->execute([$userId]);
            $oldPhoto = $stmt->fetchColumn();
            
            $ext = $allowedMimes[$mime];
            $photoName = uniqid('admin_', true) . '.' . $ext;
            
            $uploadDir = __DIR__ . '/../uploads/admins/';
            if (!is_dir($uploadDir)) { mkdir($uploadDir, 0755, true); }
            
            if (move_uploaded_file($_FILES['photo']['tmp_name'], $uploadDir . $photoName)) {
                if ($oldPhoto && file_exists($uploadDir . $oldPhoto)) {
                    unlink($uploadDir . $oldPhoto);
                }
                $stmt = $pdo->prepare("UPDATE users SET photo = ? WHERE id = ?");
                $stmt->execute([$photoName, $userId]);
                logAudit($pdo, 'update_photo', 'users', $userId, ['photo' => $photoName]);
                flash('success', 'Foto profil berhasil diperbarui.');
            } else {
                flash('error', 'Terjadi kesalahan sistem saat mengunggah foto.');
            }
        }
    }
} else {
    flash('error', 'Pilih file foto yang valid terlebih dahulu.');
}

// Redirect aman ke halaman dashboard atau referer internal
$referer = $_SERVER['HTTP_REFERER'] ?? '';
$safeRedirect = 'dashboard.php';
if (!empty($referer)) {
    $parsed = parse_url($referer, PHP_URL_PATH);
    if (!empty($parsed)) {
        $safeRedirect = $parsed;
    }
}
header('Location: ' . $safeRedirect);
exit;