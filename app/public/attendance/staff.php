<?php
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../config/auth.php';
require_once '../../config/security.php';
require_once '../../includes/functions.php';

requireSuperAdmin();
$pageTitle = 'Absensi Staff';

$name = trim($_GET['name'] ?? '');
$status = $_GET['status'] ?? '';
$unitId = (int)($_GET['unit_id'] ?? 0);
$dateStart = $_GET['date_start'] ?? date('Y-m-d');
$dateEnd = $_GET['date_end'] ?? date('Y-m-d');

$units = $pdo->query("SELECT * FROM units ORDER BY id ASC")->fetchAll();

$where = [
    "s.deleted_at IS NULL",
    "sa.attendance_date BETWEEN ? AND ?"
];
$params = [$dateStart, $dateEnd];

if ($name !== '') {
    $where[] = "s.name LIKE ?";
    $params[] = "%{$name}%";
}
if ($status === 'tepat_waktu' || $status === 'terlambat') {
    $where[] = "sa.status = ?";
    $params[] = $status;
}
if ($unitId > 0) {
    $where[] = "s.unit_id = ?";
    $params[] = $unitId;
}

$whereSql = implode(' AND ', $where);

$page = max(1, (int)($_GET['page'] ?? 1));
$limit = 50;
$offset = ($page - 1) * $limit;

$countSql = "
    SELECT COUNT(sa.id)
    FROM staff_attendances sa
    INNER JOIN staff s ON s.id = sa.staff_id
    INNER JOIN activities a ON a.id = sa.activity_id
    LEFT JOIN units u ON u.id = s.unit_id
    WHERE {$whereSql}
";
$countStmt = $pdo->prepare($countSql);
$countStmt->execute($params);
$totalRecords = (int)$countStmt->fetchColumn();
$totalPages = max(1, ceil($totalRecords / $limit));

$stmt = $pdo->prepare("
    SELECT sa.*, s.name, s.nik, a.name AS activity_name, u.unit AS unit_name
    FROM staff_attendances sa
    INNER JOIN staff s ON s.id = sa.staff_id
    INNER JOIN activities a ON a.id = sa.activity_id
    LEFT JOIN units u ON u.id = s.unit_id
    WHERE {$whereSql}
    ORDER BY sa.attendance_date DESC, sa.time_in DESC, s.name
    LIMIT {$limit} OFFSET {$offset}
");
$stmt->execute($params);
$attendance = $stmt->fetchAll();

require '../../includes/header.php';
?>

<div class="card">
    <div class="card-header">
        <div>
            <h3>Absensi Staff / Guru</h3>
            <small>Total <?= number_format($totalRecords) ?> data | Halaman <?= $page ?> dari <?= $totalPages ?></small>
        </div>
    </div>
    <form method="GET">
        <div class="filter-grid">
            <div class="form-group"><label>Dari</label><input type="date" name="date_start" value="<?= e($dateStart) ?>"></div>
            <div class="form-group"><label>Sampai</label><input type="date" name="date_end" value="<?= e($dateEnd) ?>"></div>
            <div class="form-group"><label>Nama</label><input type="text" name="name" value="<?= e($name) ?>"></div>
            
            <div class="form-group">
                <label>Unit</label>
                <select name="unit_id">
                    <option value="0">Semua Unit</option>
                    <?php foreach ($units as $unit): ?>
                        <option value="<?= $unit['id'] ?>" <?= $unitId == $unit['id'] ? 'selected' : '' ?>><?= e($unit['unit']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label>Status</label>
                <select name="status">
                    <option value="">Semua</option>
                    <option value="tepat_waktu" <?= $status === 'tepat_waktu' ? 'selected' : '' ?>>Tepat Waktu</option>
                    <option value="terlambat" <?= $status === 'terlambat' ? 'selected' : '' ?>>Terlambat</option>
                </select>
            </div>
            <div><button class="btn btn-primary" style="margin-top: 22px;">🔎 Cari</button></div>
        </div>
    </form>
</div>

<div class="card">
    <div class="table-wrapper">
        <table>
            <thead>
                <tr>
                    <th>Tanggal</th>
                    <th>Nama</th>
                    <th>NIK</th>
                    <th>Unit</th>
                    <th>Kegiatan</th>
                    <th>Jam</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($attendance as $row): ?>
                <tr>
                    <td><?= e($row['attendance_date']) ?></td>
                    <td><?= e($row['name']) ?></td>
                    <td><?= e($row['nik']) ?></td>
                    <td><span class="badge badge-primary" style="background:#0284c7; color:white;"><?= e($row['unit_name'] ?? '-') ?></span></td>
                    <td><?= e($row['activity_name']) ?></td>
                    <td><?= e($row['time_in']) ?></td>
                    <td>
                        <?php if ($row['status'] === 'tepat_waktu'): ?>
                            <span class="badge badge-success">Tepat Waktu</span>
                        <?php else: ?>
                            <span class="badge badge-danger">Terlambat</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?= renderPagination($page, $totalPages, $_GET) ?>
</div>
<?php require '../../includes/footer.php'; ?>