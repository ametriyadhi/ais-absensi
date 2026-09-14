<?php
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../config/auth.php';
require_once '../../config/security.php';
require_once '../../includes/functions.php';

requireSuperAdmin();
$pageTitle = 'Edit Siswa';

$id = (int)($_GET['id'] ?? 0);

if ($id <= 0) {
    redirect('index.php');
}

$stmt = $pdo->prepare("SELECT * FROM students WHERE id = ? AND deleted_at IS NULL LIMIT 1");
$stmt->execute([$id]);
$student = $stmt->fetch();

if (!$student) {
    die('Data siswa tidak ditemukan.');
}

// Cek status pendaftaran aktif (enrollment)
$stmt = $pdo->prepare("
    SELECT se.* FROM student_enrollments se
    INNER JOIN academic_years ay ON ay.id = se.academic_year_id
    WHERE se.student_id = ? AND ay.status = 'active' LIMIT 1
");
$stmt->execute([$id]);
$enrollment = $stmt->fetch();

// Ambil ID Tahun Ajaran Aktif untuk skenario insert
$stmtAy = $pdo->query("SELECT id FROM academic_years WHERE status = 'active' LIMIT 1");
$activeAy = $stmtAy->fetch();
$activeAyId = $activeAy['id'] ?? 0;

$classGroups = $pdo->query("
    SELECT cg.id, cg.name, g.grade FROM class_groups cg
    INNER JOIN grades g ON g.id = cg.grade_id
    ORDER BY g.sort_order, cg.name
")->fetchAll();

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $name = trim($_POST['name'] ?? '');
    $nis = trim($_POST['nis'] ?? '');
    $nik = trim($_POST['nik'] ?? '');
    $parentPhone = trim($_POST['parent_phone'] ?? '');
    $classGroupId = (int)($_POST['class_group_id'] ?? 0);

    if ($name === '' || $classGroupId <= 0) {
        $error = 'Nama dan subkelas wajib diisi.';
    } else {
        $newPhoto = null;
        try {
            $pdo->beginTransaction();

            if (!empty($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
                $mime = mime_content_type($_FILES['photo']['tmp_name']);
                $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
                if (!isset($allowed[$mime])) { throw new Exception('Format foto tidak diperbolehkan.'); }
                if ($_FILES['photo']['size'] > 2 * 1024 * 1024) { throw new Exception('Ukuran foto maksimal 2 MB.'); }
                if (!is_dir(UPLOAD_STUDENT)) { mkdir(UPLOAD_STUDENT, 0755, true); }
                
                $newPhoto = uniqid('student_', true) . '.' . $allowed[$mime];
                move_uploaded_file($_FILES['photo']['tmp_name'], UPLOAD_STUDENT . $newPhoto);
            }

            // Update profil siswa dasar
            if ($newPhoto) {
                $sql = "UPDATE students SET name = ?, nis = ?, nik = ?, parent_phone = ?, photo = ? WHERE id = ?";
                $params = [$name, $nis !== '' ? $nis : null, $nik !== '' ? $nik : null, $parentPhone !== '' ? $parentPhone : null, $newPhoto, $id];
            } else {
                $sql = "UPDATE students SET name = ?, nis = ?, nik = ?, parent_phone = ? WHERE id = ?";
                $params = [$name, $nis !== '' ? $nis : null, $nik !== '' ? $nik : null, $parentPhone !== '' ? $parentPhone : null, $id];
            }
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);

            // LOGIKA PERBAIKAN: Jika pendaftaran belum ada, maka buatkan pendaftaran baru (INSERT)
            if ($enrollment) {
                $stmt = $pdo->prepare("UPDATE student_enrollments SET class_group_id = ? WHERE id = ?");
                $stmt->execute([$classGroupId, $enrollment['id']]);
            } else {
                if ($activeAyId > 0) {
                    $stmt = $pdo->prepare("INSERT INTO student_enrollments (student_id, academic_year_id, class_group_id, status, created_at) VALUES (?, ?, ?, 'active', NOW())");
                    $stmt->execute([$id, $activeAyId, $classGroupId]);
                } else {
                    throw new Exception('Gagal menyimpan kelas. Tidak ada Tahun Ajaran yang berstatus aktif.');
                }
            }

            $pdo->commit();
            logAudit($pdo, 'update', 'students', $id, ['name' => $name, 'nis' => $nis]);

            if ($newPhoto && !empty($student['photo']) && file_exists(UPLOAD_STUDENT . $student['photo'])) {
                unlink(UPLOAD_STUDENT . $student['photo']);
            }

            flash('success', 'Data siswa berhasil diperbarui dan kelas telah terpasang.');
            redirect('index.php');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            if ($newPhoto && file_exists(UPLOAD_STUDENT . $newPhoto)) { unlink(UPLOAD_STUDENT . $newPhoto); }
            $error = $e->getMessage();
        }
    }
}

require '../../includes/header.php';
?>

<div class="card">
    <div class="card-header">
        <h3>Edit Siswa</h3>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-danger"><?= e($error) ?></div>
    <?php endif; ?>

    <form method="POST" enctype="multipart/form-data">
        <?= csrfField() ?>
        <div class="form-grid">
            <div class="form-group">
                <label>Nama Siswa</label>
                <input type="text" name="name" required value="<?= e($student['name']) ?>">
            </div>
            <div class="form-group">
                <label>NIS</label>
                <input type="text" name="nis" value="<?= e($student['nis']) ?>">
            </div>
            <div class="form-group">
                <label>NIK</label>
                <input type="text" name="nik" value="<?= e($student['nik']) ?>">
            </div>
            <div class="form-group">
                <label>No. WhatsApp Orang Tua / Wali</label>
                <input type="text" name="parent_phone" placeholder="Contoh: 08123456789" value="<?= e($student['parent_phone'] ?? '') ?>">
            </div>

            <div class="form-group">
                <label>Subkelas Tahun Aktif</label>
                <select name="class_group_id" required>
                    <option value="">-- Pilih Subkelas --</option>
                    <?php foreach ($classGroups as $class): ?>
                        <option value="<?= $class['id'] ?>" <?= ($enrollment && $enrollment['class_group_id'] == $class['id']) ? 'selected' : '' ?>>
                            <?= e($class['grade']) ?> - <?= e($class['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group full">
                <label>Foto Baru</label>
                <?php if (!empty($student['photo'])): ?>
                    <div style="margin-bottom:10px;">
                        <img src="../../uploads/students/<?= e($student['photo']) ?>" width="60" style="border-radius:10px;">
                    </div>
                <?php endif; ?>
                <input type="file" name="photo" accept="image/jpeg,image/png,image/webp">
                <small>Maksimal 2 MB.</small>
            </div>
        </div>

        <div style="margin-top:20px">
            <button type="submit" class="btn btn-primary">Simpan Perubahan</button>
            <a href="index.php" class="btn btn-success">Kembali</a>
        </div>
    </form>
</div>

<?php require '../../includes/footer.php'; ?>