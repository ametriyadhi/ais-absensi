<?php
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../config/auth.php';
require_once '../../config/security.php';
require_once '../../includes/functions.php';

requireAdmin();
$pageTitle = 'Data Siswa';

$name = trim($_GET['name'] ?? '');
$gradeId = (int)($_GET['grade_id'] ?? 0);
$classId = (int)($_GET['class_group_id'] ?? 0);
$unitId = (int)($_GET['unit_id'] ?? 0); // NEW UNIT FILTER

$grades = $pdo->query("SELECT * FROM grades ORDER BY sort_order, grade")->fetchAll();
$units = $pdo->query("SELECT * FROM units ORDER BY id ASC")->fetchAll(); // AMBIL MASTER UNIT
$classGroups = $pdo->query("
    SELECT cg.id, cg.name, cg.grade_id, g.grade
    FROM class_groups cg
    INNER JOIN grades g ON g.id = cg.grade_id
    ORDER BY g.sort_order, cg.name
")->fetchAll();

$access = getStudentAccessCondition('g', 'cg');

$where = [
    "s.deleted_at IS NULL",
    $access['condition']
];
$params = $access['params'];

if ($name !== '') { $where[] = "s.name LIKE ?"; $params[] = "%{$name}%"; }
if ($gradeId > 0) { $where[] = "g.id = ?"; $params[] = $gradeId; }
if ($classId > 0) { $where[] = "cg.id = ?"; $params[] = $classId; }
if ($unitId > 0) { $where[] = "un.id = ?"; $params[] = $unitId; } // APPLY UNIT FILTER

$whereSql = implode(' AND ', $where);

$page = max(1, (int)($_GET['page'] ?? 1));
$limit = 50;
$offset = ($page - 1) * $limit;

$countSql = "
    SELECT COUNT(DISTINCT s.id)
    FROM students s
    LEFT JOIN student_enrollments se ON se.student_id = s.id AND se.academic_year_id = (SELECT id FROM academic_years WHERE status = 'active' LIMIT 1)
    LEFT JOIN class_groups cg ON cg.id = se.class_group_id
    LEFT JOIN grades g ON g.id = cg.grade_id
    LEFT JOIN units un ON un.id = g.unit_id
    WHERE {$whereSql}
";
$stmt = $pdo->prepare($countSql);
$stmt->execute($params);
$total = (int)$stmt->fetchColumn();
$totalPages = max(1, ceil($total / $limit));

$sql = "
    SELECT 
        s.id, s.name, s.nis, s.nik, s.parent_phone, s.photo,
        COALESCE(cg.name, 'Belum Masuk Kelas') AS class_name,
        COALESCE(g.grade, '-') AS grade,
        COALESCE(un.unit, '-') AS unit_name
    FROM students s
    LEFT JOIN student_enrollments se ON se.student_id = s.id AND se.academic_year_id = (SELECT id FROM academic_years WHERE status = 'active' LIMIT 1)
    LEFT JOIN class_groups cg ON cg.id = se.class_group_id
    LEFT JOIN grades g ON g.id = cg.grade_id
    LEFT JOIN units un ON un.id = g.unit_id
    WHERE {$whereSql}
    ORDER BY g.sort_order IS NULL, g.sort_order, cg.name, s.name
    LIMIT {$limit} OFFSET {$offset}
";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$students = $stmt->fetchAll();

require '../../includes/header.php';
?>

<?php
$exportParams = $_GET;
unset($exportParams['page']);
$exportQuery = http_build_query($exportParams);
?>

<div class="card">
    <div class="card-header">
        <div>
            <h3>Data Siswa</h3>
            <small><?= number_format($total) ?> siswa</small>
        </div>
        <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
            <a href="excel.php?<?= e($exportQuery) ?>" class="btn btn-success" style="display: inline-flex; align-items: center; gap: 6px;">📊 Export Excel</a>
            <?php if (($_SESSION['role'] ?? '') === 'super_admin'): ?>
                <a href="import.php" class="btn" style="background-color: #10b981; color: white; display: inline-flex; align-items: center; gap: 6px;"><i class="fa-solid fa-file-import"></i> Import Siswa</a>
                <a href="create.php" class="btn btn-primary">+ Tambah Siswa</a>
            <?php endif; ?>
        </div>
    </div>

    <form method="GET">
        <div class="filter-grid">
            <div class="form-group">
                <label>Nama</label>
                <input type="text" name="name" value="<?= e($name) ?>" placeholder="Cari nama...">
            </div>
            
            <div class="form-group">
                <label>Unit</label>
                <select name="unit_id">
                    <option value="0">Semua Unit</option>
                    <?php foreach ($units as $u): ?>
                        <option value="<?= $u['id'] ?>" <?= $unitId == $u['id'] ? 'selected' : '' ?>><?= e($u['unit']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label>Grade</label>
                <select name="grade_id" id="gradeFilter">
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
                        <option value="<?= $class['id'] ?>" <?= $classId == $class['id'] ? 'selected' : '' ?>><?= e($class['name']) ?> - <?= e($class['grade']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <button class="btn btn-primary" type="submit" style="margin-top: 22px;">🔎 Cari</button>
            </div>
        </div>
    </form>
</div>

<div class="card">
    <div class="table-wrapper">
        <table>
            <thead>
                <tr>
                    <th>No</th>
                    <th>Foto</th>
                    <th>Nama</th>
                    <th>NIS</th>
                    <th>NIK</th>
                    <th>Unit</th>
                    <th>Grade</th>
                    <th>Subkelas</th>
                    <?php if (($_SESSION['role'] ?? '') === 'super_admin'): ?><th>Aksi</th><?php endif; ?>
                </tr>
            </thead>
            <tbody>
            <?php if (!$students): ?>
                <tr><td colspan="9" style="text-align:center">Data siswa tidak ditemukan.</td></tr>
            <?php endif; ?>

            <?php foreach ($students as $index => $student): ?>
                <tr>
                    <td><?= $offset + $index + 1 ?></td>
                    <td>
                        <?php if (!empty($student['photo'])): ?>
                            <img src="../../uploads/students/<?= e($student['photo']) ?>" width="45" height="45" style="object-fit:cover; border-radius:10px;">
                        <?php else: ?>
                            <div class="avatar"><?= strtoupper(substr($student['name'], 0, 1)) ?></div>
                        <?php endif; ?>
                    </td>
                    <td><?= e($student['name']) ?></td>
                    <td><?= e($student['nis']) ?></td>
                    <td><?= e($student['nik']) ?></td>
                    <td>
                        <span class="badge badge-primary" style="background:#0284c7; color:white;">
                            <?= e($student['unit_name']) ?>
                        </span>
                    </td>
                    <td><?= e($student['grade']) ?></td>
                    <td><?= e($student['class_name']) ?></td>

                    <?php if (($_SESSION['role'] ?? '') === 'super_admin'): ?>
                    <td>
                        <a href="edit.php?id=<?= $student['id'] ?>" class="btn btn-success" style="padding: 4px 8px; font-size:12px;">Edit</a>
                        <form action="delete.php" method="POST" style="display:inline" onsubmit="return confirm('Hapus siswa ini?')">
                            <?= csrfField() ?>
                            <input type="hidden" name="id" value="<?= $student['id'] ?>">
                            <button type="submit" class="btn btn-danger" style="padding: 4px 8px; font-size:12px;">Hapus</button>
                        </form>
                    </td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    
    <?php if ($totalPages > 1): ?>
    <div style="display:flex; gap:8px; margin-top:20px; flex-wrap:wrap;">
        <?php for ($i = 1; $i <= $totalPages; $i++): ?>
            <a href="?<?= buildQuery(['page' => $i]) ?>" class="btn <?= $page == $i ? 'btn-primary' : 'btn-success' ?>"><?= $i ?></a>
        <?php endfor; ?>
    </div>
    <?php endif; ?>
</div>

<?php require '../../includes/footer.php'; ?>