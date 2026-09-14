<?php
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../config/auth.php';
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
    WHERE {$whereSql} ORDER BY so.overtime_date ASC, s.name ASC
";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$overtimes = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Laporan Lembur Staff</title>
    <style>
        body { font-family: Arial, sans-serif; font-size: 14px; margin: 20px; }
        .header { text-align: center; margin-bottom: 20px; border-bottom: 2px solid #000; padding-bottom: 10px; }
        table { width: 100%; border-collapse: collapse; margin-top: 15px; }
        th, td { border: 1px solid #000; padding: 8px; text-align: left; }
        th { background-color: #f3f4f6; }
    </style>
</head>
<body onload="window.print()">

<div class="header">
    <h2>Laporan Lembur Staff - An-Nahl Islamic School</h2>
    <p>Periode: Bulan <?= $filterMonth ?> Tahun <?= $filterYear ?></p>
</div>

<table>
    <thead>
        <tr>
            <th>No</th>
            <th>Tanggal</th>
            <th>Nama Staff</th>
            <th>NIK</th>
            <th>Batas Waktu Normal</th>
            <th>Waktu Absen Keluar</th>
            <th>Total Waktu Lembur</th>
        </tr>
    </thead>
    <tbody>
        <?php if (!$overtimes): ?>
            <tr><td colspan="7" style="text-align:center;">Data lembur kosong.</td></tr>
        <?php endif; ?>
        <?php foreach ($overtimes as $index => $row): ?>
            <?php
                $durasi = max(0, strtotime($row['time_out']) - strtotime($row['normal_out']));
                $jam = floor($durasi / 3600); $menit = floor(($durasi % 3600) / 60);
            ?>
        <tr>
            <td style="text-align: center;"><?= $index + 1 ?></td>
            <td><?= date('d/m/Y', strtotime($row['overtime_date'])) ?></td>
            <td><?= $row['name'] ?></td>
            <td><?= $row['nik'] ?></td>
            <td style="text-align: center;"><?= $row['normal_out'] ?></td>
            <td style="text-align: center;"><?= $row['time_out'] ?></td>
            <td><strong><?= $jam ?> Jam <?= $menit ?> Menit</strong></td>
        </tr>
        <?php endforeach; ?>
    </tbody>
</table>

</body>
</html>