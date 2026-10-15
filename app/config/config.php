<?php

// Deteksi HTTPS dari direct connection, Cloudflare, atau reverse proxy
$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
    || (!empty($_SERVER['HTTP_CF_VISITOR']) && strpos($_SERVER['HTTP_CF_VISITOR'], 'https') !== false)
    || (!empty($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443);

$protocol = $isHttps ? 'https://' : 'http://';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost:8089';
$rootUrl = rtrim($protocol . $host, '/');

// Menggunakan root-relative path agar selalu bekerja baik di localhost maupun di domain Cloudflare
if (!defined('BASE_URL')) {
    define('BASE_URL', getenv('APP_BASE_URL') ?: '/public');
}

if (!defined('ASSET_URL')) {
    define('ASSET_URL', getenv('APP_ASSET_URL') ?: '/assets');
}

if (!defined('UPLOAD_URL')) {
    define('UPLOAD_URL', getenv('APP_UPLOAD_URL') ?: '/uploads');
}

if (!defined('UPLOAD_PATH')) {
    define('UPLOAD_PATH', dirname(__DIR__) . '/uploads');
}

if (!defined('UPLOAD_STUDENT')) {
    define('UPLOAD_STUDENT', dirname(__DIR__) . '/uploads/students/');
}
if (!defined('UPLOAD_STAFF')) {
    define('UPLOAD_STAFF', dirname(__DIR__) . '/uploads/staff/');
}
if (!defined('UPLOAD_ADMIN')) {
    define('UPLOAD_ADMIN', dirname(__DIR__) . '/uploads/admins/');
}

$uploadStudentPath = UPLOAD_STUDENT;

// Pastikan folder upload selalu ada dan memiliki izin tulis
foreach ([UPLOAD_PATH, UPLOAD_STUDENT, UPLOAD_PATH . '/staff/', UPLOAD_PATH . '/admins/', UPLOAD_PATH . '/permits/', UPLOAD_PATH . '/achievements/'] as $dir) {
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }
    @chmod($dir, 0777);
}

date_default_timezone_set('Asia/Jakarta');

// Forensic & Device Detector Helper
require_once __DIR__ . '/../includes/device_detector.php';

// Konfigurasi Geofencing Lokasi Sekolah (Default: An-Nahl Islamic School)
if (!defined('SCHOOL_NAME')) {
    define('SCHOOL_NAME', getenv('SCHOOL_NAME') ?: 'An-Nahl Islamic School');
}
if (!defined('SCHOOL_LATITUDE')) {
    define('SCHOOL_LATITUDE', (float)(getenv('SCHOOL_LATITUDE') ?: -6.377038));
}
if (!defined('SCHOOL_LONGITUDE')) {
    define('SCHOOL_LONGITUDE', (float)(getenv('SCHOOL_LONGITUDE') ?: 106.945892));
}
if (!defined('SCHOOL_RADIUS_METERS')) {
    define('SCHOOL_RADIUS_METERS', (int)(getenv('SCHOOL_RADIUS_METERS') ?: 250));
}

// PSR-4 Autoloader untuk namespace App\
spl_autoload_register(function ($class) {
    $prefix = 'App\\';
    $baseDir = dirname(__DIR__) . '/src/';
    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }
    $relativeClass = substr($class, $len);
    $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';
    if (file_exists($file)) {
        require_once $file;
    }
});
