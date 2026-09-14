<?php
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../config/auth.php';
require_once '../../config/security.php';
require_once '../../includes/functions.php';

requireAdmin();
$pageTitle = 'Absensi Siswa';

$isSuperAdmin = ($_SESSION['role'] ?? '') === 'super_admin';

$name = trim($_GET['name'] ?? '');
$status = $_GET['status'] ?? '';
$dateStart = $_GET['date_start'] ?? date('Y-m-d');
$dateEnd = $_GET['date_end'] ?? date('Y-m-d');

// Filter ini hanya berlaku jika dia Super Admin
$gradeId = $isSuperAdmin ? (int)($_GET['grade_id'] ?? 0) : 0;
$classId = $isSuperAdmin ? (int)($_GET['class_group_id'] ?? 0) : 0;

$grades = $pdo->query("SELECT * FROM grades ORDER BY sort_order, grade")->fetchAll();
$classGroups = $pdo->query("
    SELECT cg.id, cg.name, g.grade 
    FROM class_groups cg 
    INNER JOIN grades g ON g.id = cg.grade_id 
    ORDER BY g.sort_order, cg.name
")->fetchAll();

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

$page = max(1, (int)($_GET['page'] ?? 1));
$limit = 50;
$offset = ($page - 1) * $limit;

$countSql = "
    SELECT COUNT(sa.id)
    FROM student_attendances sa
    INNER JOIN students s ON s.id = sa.student_id
    INNER JOIN student_enrollments se ON se.id = sa.enrollment_id
    INNER JOIN class_groups cg ON cg.id = se.class_group_id
    INNER JOIN grades g ON g.id = cg.grade_id
    INNER JOIN activities a ON a.id = sa.activity_id
    WHERE {$whereSql}
";
$countStmt = $pdo->prepare($countSql);
$countStmt->execute($params);
$totalRecords = (int)$countStmt->fetchColumn();
$totalPages = max(1, ceil($totalRecords / $limit));

$sql = "
    SELECT 
        sa.*, s.name, s.nis, s.photo, 
        g.grade, cg.name AS class_name, 
        a.name AS activity_name
    FROM student_attendances sa
    INNER JOIN students s ON s.id = sa.student_id
    INNER JOIN student_enrollments se ON se.id = sa.enrollment_id
    INNER JOIN class_groups cg ON cg.id = se.class_group_id
    INNER JOIN grades g ON g.id = cg.grade_id
    INNER JOIN activities a ON a.id = sa.activity_id
    WHERE {$whereSql}
    ORDER BY sa.attendance_date DESC, sa.time_in DESC, s.name
    LIMIT {$limit} OFFSET {$offset}
";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$attendances = $stmt->fetchAll();

require '../../includes/header.php';
?>

<div class="card">
    <div class="card-header">
        <div>
            <h3>Absensi Siswa</h3>
            <small>Total <?= number_format($totalRecords) ?> data | Halaman <?= $page ?> dari <?= $totalPages ?></small>
        </div>
    </div>

    <form method="GET">
        <div class="filter-grid">
            <div class="form-group">
                <label>Dari Tanggal</label>
                <input type="date" name="date_start" value="<?= e($dateStart) ?>">
            </div>
            <div class="form-group">
                <label>Sampai Tanggal</label>
                <input type="date" name="date_end" value="<?= e($dateEnd) ?>">
            </div>
            <div class="form-group">
                <label>Nama Siswa</label>
                <input type="text" name="name" value="<?= e($name) ?>" placeholder="Cari nama...">
            </div>

            <!-- Filter Grade & Subkelas HANYA tampil untuk Super Admin -->
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
                <button class="btn btn-primary" type="submit" style="margin-top:22px;">🔎 Cari</button>
            </div>
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
                    <th>NIS</th>
                    <th>Grade</th>
                    <th>Subkelas</th>
                    <th>Kegiatan</th>
                    <th>Jam</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($attendances as $attendance): ?>
                <tr>
                    <td><?= e($attendance['attendance_date']) ?></td>
                    <td><?= e($attendance['name']) ?></td>
                    <td><?= e($attendance['nis']) ?></td>
                    <td><?= e($attendance['grade']) ?></td>
                    <td><?= e($attendance['class_name']) ?></td>
                    <td><?= e($attendance['activity_name']) ?></td>
                    <td><?= e($attendance['time_in']) ?></td>
                    <td>
                        <?php if ($attendance['status'] === 'tepat_waktu'): ?>
                            <span class="badge badge-success">✓ Tepat Waktu</span>
                        <?php else: ?>
                            <span class="badge badge-danger">⚠ Terlambat</span>
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