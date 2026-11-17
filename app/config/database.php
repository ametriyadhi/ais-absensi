<?php
if (!function_exists('appEnv')) {
    /**
     * Baca konfigurasi environment secara andal: getenv() lalu $_ENV.
     * Di Apache thread-safe (mis. Laragon/Windows) nilai putenv() dari loader .env bisa dihapus oleh
     * permintaan lain yang selesai lebih dulu, sehingga getenv() saja kadang mengembalikan false.
     * @return string|false
     */
    function appEnv(string $key)
    {
        $v = getenv($key);
        if ($v !== false) {
            return $v;
        }
        return (isset($_ENV[$key]) && is_string($_ENV[$key])) ? $_ENV[$key] : false;
    }
}

date_default_timezone_set('Asia/Jakarta');

$host = appEnv('DB_HOST') ?: '127.0.0.1';
$port = appEnv('DB_PORT') ?: '3306';
$dbname = appEnv('DB_NAME') ?: 'library_v4';
$username = appEnv('DB_USER') ?: 'root';
$password = appEnv('DB_PASSWORD') !== false ? appEnv('DB_PASSWORD') : '';

try {
    $dsn = "mysql:host={$host};port={$port};dbname={$dbname};charset=utf8mb4";
    $pdo = new PDO(
        $dsn,
        $username,
        $password,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false
        ]
    );
    $pdo->exec("SET time_zone = '+07:00'");
} catch (PDOException $e) {
    error_log('Database connection error: ' . $e->getMessage());
    die('Database tidak dapat terhubung: ' . (appEnv('APP_DEBUG') === 'true' ? $e->getMessage() : 'Silakan hubungi administrator.'));
}
