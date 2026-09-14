<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/auth.php';
requireAdmin();

$filterYear = (int)($_GET['filter_year'] ?? date('Y'));
$filterMonth = (int)($_GET['filter_month'] ?? date('n'));
$nameFilter = trim($_GET['name'] ?? '');

$where = ["YEAR(so.overtime_date) = ?", "MONTH(so.overtime_date) = ?"];
$params = [$filterYear, $filterMonth];
if ($nameFilter !== '') { $where[] = "s.name LIKE ?"; $params[] = "%{$nameFilter}%"; }

$whereSql = implode(' AND ', $where);
$sql = "
    SELECT so.overtime_date, so.time_out, s.name, s.nik, a.staff_out as normal_out
    FROM staff_overtimes so
    JOIN staff s ON s.id = so.staff_id
    JOIN activities a ON a.id = so.activity_id
    WHERE {$whereSql} ORDER BY so.overtime_date DESC, s.name ASC
";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$overtimes = $stmt->fetchAll();

header('Content-Type: text/csv; charset=UTF-8');
header("Content-Disposition: attachment; filename=\"laporan_lembur_staff_bulan_{$filterMonth}_{$filterYear}.csv\"");
header('Pragma: no-cache');
header('Expires: 0');

$output = fopen('php://output', 'w');
// Write UTF-8 BOM so Excel opens with proper Indonesian encoding
fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));

// Header Kolom
fputcsv($output, ['No', 'Tanggal', 'Nama Staff', 'NIK', 'Batas Normal Keluar', 'Jam Keluar', 'Durasi Lembur']);

foreach ($overtimes as $index => $row) {
    $durasi = max(0, strtotime($row['time_out']) - strtotime($row['normal_out']));
    $jam = floor($durasi / 3600);
    $menit = floor(($durasi % 3600) / 60);

    fputcsv($output, [
        $index + 1,
        $row['overtime_date'],
        $row['name'],
        !empty($row['nik']) ? "'" . $row['nik'] : '-',
        $row['normal_out'],
        $row['time_out'],
        "{$jam} Jam {$menit} Menit"
    ]);
}

fclose($output);
exit;