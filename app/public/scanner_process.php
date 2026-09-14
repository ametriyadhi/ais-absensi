<?php
session_start();
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../config/security.php';
require_once '../config/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: scanner.php');
    exit;
}

$code = trim($_POST['code'] ?? '');
if ($code === '') {
    header('Location: scanner.php?error=empty');
    exit;
}

$scannedBy = currentUserId();
$result = \App\Services\AttendanceService::processScan($pdo, $code, $scannedBy);

if (!$result['success']) {
    $err = $result['error'] ?? 'not_found';
    header('Location: scanner.php?error=' . urlencode($err));
    exit;
}

$_SESSION['scan_result'] = $result;
header('Location: scanner.php');
exit;
