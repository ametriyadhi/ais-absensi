<?php
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../config/auth.php';
require_once '../../config/security.php';
require_once '../../includes/functions.php';

requireAdmin();
$pageTitle = 'Laporan Absensi';
$isSuperAdmin = ($_SESSION['role'] ?? '') === 'super_admin';

$period = getReportPeriod();
$dateStart = $period['start'];
$dateEnd = $period['end'];

$name = trim($_GET['name'] ?? '');
$status = $_GET['status'] ?? '';

// Reset ID jika bukan super admin, agar datanya tidak bisa dimanipulasi
$gradeId = $isSuperAdmin ? (int)($_GET['grade_id'] ?? 0) : 0;
$classId = $isSuperAdmin ? (int)($_GET['class_group_id'] ?? 0) : 0;

$access = getStudentAccessCondition('g', 'cg');

$where = [
    "s.deleted_at IS NULL",
    "sa.attendance_date BETWEEN ? AND ?",
    $access['condition']
];
$params = [$dateStart, $dateEnd];
$params = array_merge($params, $access['params']);

if ($name !== '') { $where[] = "s.name LIKE ?"; $params[] = "%{$name}%"; }
if ($gradeId > 0) { $where[] = "g.id = ?"; $params[] = $gradeId; }
if ($classId > 0) { $where[] = "cg.id = ?"; $params[] = $classId; }
if ($status === 'tepat_waktu' || $status === 'terlambat') { $where[] = "sa.status = ?"; $params[] = $status; }

$whereSql = implode(' AND ', $where);

$grades = $pdo->query("SELECT * FROM grades ORDER BY sort_order, grade")->fetchAll();
$classGroups = $pdo->query("
    SELECT cg.id, cg.name, g.grade 
    FROM class_groups cg 
    INNER JOIN grades g ON g.id = cg.grade_id 
    ORDER BY g.sort_order, cg.name
")->fetchAll();

$stmt = $pdo->prepare("
    SELECT sa.*, s.name, s.nis, g.grade, cg.name AS class_name, a.name AS activity_name
    FROM student_attendances sa
    INNER JOIN students s ON s.id = sa.student_id
    INNER JOIN student_enrollments se ON se.id = sa.enrollment_id
    INNER JOIN class_groups cg ON cg.id = se.class_group_id
    INNER JOIN grades g ON g.id = cg.grade_id
    INNER JOIN activities a ON a.id = sa.activity_id
    WHERE {$whereSql}
    ORDER BY sa.attendance_date DESC, sa.time_in DESC, s.name
");
$stmt->execute($params);
$rows = $stmt->fetchAll();

$queryParams = [
    'preset' => 'custom',
    'start_date' => $dateStart,
    'end_date' => $dateEnd
];
if ($name !== '') { $queryParams['name'] = $name; }
if ($gradeId > 0) { $queryParams['grade_id'] = $gradeId; }
if ($classId > 0) { $queryParams['class_group_id'] = $classId; }
if ($status !== '') { $queryParams['status'] = $status; }
$exportQuery = http_build_query($queryParams);

require '../../includes/header.php';
?>

<div class="card">
    <div class="card-header">
        <div>
            <h3>Laporan Absensi</h3>
            <small><?= e($dateStart) ?> s/d <?= e($dateEnd) ?></small>
        </div>
    </div>

    <div style="display:flex; gap:8px; flex-wrap:wrap; margin-bottom:20px;">
        <a href="?preset=today" class="btn btn-success">Hari Ini</a>
        <a href="?preset=week" class="btn btn-success">Minggu Ini</a>
        <a href="?preset=month" class="btn btn-success">Bulan Ini</a>
    </div>

    <form method="GET">
        <input type="hidden" name="preset" value="custom">
        <div class="filter-grid">
            <div class="form-group">
                <label>Dari Tanggal</label>
                <input type="date" name="start_date" value="<?= e($dateStart) ?>">
            </div>
            <div class="form-group">
                <label>Sampai Tanggal</label>
                <input type="date" name="end_date" value="<?= e($dateEnd) ?>">
            </div>
            <div class="form-group">
                <label>Nama Siswa</label>
                <input type="text" name="name" value="<?= e($name) ?>" placeholder="Cari nama...">
            </div>

            <!-- Hanya Tampilkan Jika Role = Super Admin -->
            <?php if ($isSuperAdmin): ?>
            <div class="form-group">
                <label>Grade</label>
                <select name="grade_id">
                    <option value="0">Semua Grade</option>
                    <?php foreach ($grades as $grade): ?>
                        <option value="<?= $grade['id'] ?>" <?= $gradeId == $grade['id'] ? 'selected' : '' ?>><?= e($grade['grade']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>Subkelas</label>
                <select name="class_group_id">
                    <option value="0">Semua Subkelas</option>
                    <?php foreach ($classGroups as $class): ?>
                        <option value="<?= $class['id'] ?>" <?= $classId == $class['id'] ? 'selected' : '' ?>><?= e($class['grade']) ?> - <?= e($class['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>

            <div class="form-group">
                <label>Status</label>
                <select name="status">
                    <option value="">Semua Status</option>
                    <option value="tepat_waktu" <?= $status === 'tepat_waktu' ? 'selected' : '' ?>>Tepat Waktu</option>
                    <option value="terlambat" <?= $status === 'terlambat' ? 'selected' : '' ?>>Terlambat</option>
                </select>
            </div>
            <div>
                <button class="btn btn-primary" type="submit" style="margin-top:22px;">🔎 Tampilkan</button>
            </div>
        </div>
    </form>
</div>

<div class="card">
    <div style="display:flex; gap:10px; flex-wrap:wrap; margin-bottom:20px;">
        <a href="excel.php?<?= e($exportQuery) ?>" class="btn btn-success">📊 Export Excel</a>
        <a href="pdf.php?<?= e($exportQuery) ?>" class="btn btn-primary">📄 Export PDF</a>
    </div>

    <div class="table-wrapper">
        <table>
            <thead>
                <tr>
                    <th>No</th>
                    <th>Tanggal</th>
                    <th>Nama</th>
                    <th>NIS</th>
                    <th>Kelas</th>
                    <th>Kegiatan</th>
                    <th>Jam</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $index => $row): ?>
                <tr>
                    <td><?= $index + 1 ?></td>
                    <td><?= e($row['attendance_date']) ?></td>
                    <td><?= e($row['name']) ?></td>
                    <td><?= e($row['nis']) ?></td>
                    <td><?= e($row['grade']) ?> - <?= e($row['class_name']) ?></td>
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
</div>

<?php require '../../includes/footer.php'; ?>