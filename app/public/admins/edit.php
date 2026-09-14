<?php
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../config/auth.php';
require_once '../../config/security.php';
require_once '../../includes/functions.php';

requireSuperAdmin();
$pageTitle = 'Edit Admin';

$id = (int)($_GET['id'] ?? 0);

$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? AND role = 'admin' LIMIT 1");
$stmt->execute([$id]);
$admin = $stmt->fetch();

if (!$admin) { die('Admin tidak ditemukan.'); }

$grades = $pdo->query("SELECT * FROM grades ORDER BY sort_order, grade")->fetchAll();
$classGroups = $pdo->query("
    SELECT cg.id, cg.name, cg.grade_id, g.grade
    FROM class_groups cg
    INNER JOIN grades g ON g.id = cg.grade_id
    ORDER BY g.sort_order, cg.name
")->fetchAll();

$stmt = $pdo->prepare("SELECT grade_id FROM admin_grade_permissions WHERE user_id = ?");
$stmt->execute([$id]);
$selectedGrades = array_map('intval', array_column($stmt->fetchAll(), 'grade_id'));

$stmt = $pdo->prepare("SELECT class_group_id FROM admin_class_permissions WHERE user_id = ?");
$stmt->execute([$id]);
$selectedClasses = array_map('intval', array_column($stmt->fetchAll(), 'class_group_id'));

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $name = trim($_POST['name'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $gradeIds = array_map('intval', $_POST['grade_ids'] ?? []);
    $classIds = array_map('intval', $_POST['class_ids'] ?? []);
    
    $photoNameSave = $admin['photo'];

    try {
        $pdo->beginTransaction();

        // PROSES UPLOAD FOTO BARU
        if (!empty($_FILES['photo']['name']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
            $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
            $mime = mime_content_type($_FILES['photo']['tmp_name']);
            if (!isset($allowed[$mime])) {
                throw new Exception('Format foto tidak valid. Gunakan JPG, PNG, atau WEBP.');
            }
            if ($_FILES['photo']['size'] > 2 * 1024 * 1024) {
                throw new Exception('Ukuran foto maksimal 2 MB.');
            }
            $photoNameSave = uniqid('admin_', true) . '.' . $allowed[$mime];
            $uploadDir = '../../uploads/admins/';
            if (!is_dir($uploadDir)) { mkdir($uploadDir, 0755, true); }
            
            // Hapus file lama jika ada
            if (!empty($admin['photo']) && file_exists($uploadDir . $admin['photo'])) {
                unlink($uploadDir . $admin['photo']);
            }
            move_uploaded_file($_FILES['photo']['tmp_name'], $uploadDir . $photoNameSave);
        }

        if ($password !== '') {
            $stmt = $pdo->prepare("UPDATE users SET name = ?, username = ?, password = ?, photo = ? WHERE id = ?");
            $stmt->execute([$name, $username, hashPassword($password), $photoNameSave, $id]);
        } else {
            $stmt = $pdo->prepare("UPDATE users SET name = ?, username = ?, photo = ? WHERE id = ?");
            $stmt->execute([$name, $username, $photoNameSave, $id]);
        }

        // RESET PERMISSIONS
        $stmt = $pdo->prepare("DELETE FROM admin_grade_permissions WHERE user_id = ?");
        $stmt->execute([$id]);
        $stmt = $pdo->prepare("DELETE FROM admin_class_permissions WHERE user_id = ?");
        $stmt->execute([$id]);

        // INSERT NEW PERMISSIONS
        $stmtGrade = $pdo->prepare("INSERT INTO admin_grade_permissions (user_id, grade_id) VALUES (?, ?)");
        foreach (array_unique($gradeIds) as $gradeId) {
            if ($gradeId > 0) { $stmtGrade->execute([$id, $gradeId]); }
        }

        $stmtClass = $pdo->prepare("INSERT INTO admin_class_permissions (user_id, class_group_id) VALUES (?, ?)");
        foreach (array_unique($classIds) as $classId) {
            if ($classId > 0) { $stmtClass->execute([$id, $classId]); }
        }

        $pdo->commit();
        logAudit($pdo, 'update', 'admins', $id, ['username' => $username]);
        flash('success', 'Data admin berhasil diperbarui.');
        redirect('index.php');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        $error = $e->getMessage();
    }
}

require '../../includes/header.php';
?>

<div class="card">
    <h3>Edit Admin</h3>
    <?php if ($error): ?>
        <div class="alert alert-danger"><?= e($error) ?></div>
    <?php endif; ?>

    <form method="POST" enctype="multipart/form-data">
        <?= csrfField() ?>
        <div class="form-grid">
            <div class="form-group">
                <label>Nama</label>
                <input type="text" name="name" required value="<?= e($admin['name']) ?>">
            </div>
            <div class="form-group">
                <label>Username</label>
                <input type="text" name="username" required value="<?= e($admin['username']) ?>">
            </div>
            <div class="form-group">
                <label>Password Baru</label>
                <input type="password" name="password" placeholder="Kosongkan jika tidak diganti">
            </div>
            <div class="form-group">
                <label>Ganti Foto (Opsional)</label>
                <?php if (!empty($admin['photo'])): ?>
                    <div style="margin-bottom:10px;">
                        <img src="../../uploads/admins/<?= e($admin['photo']) ?>" width="60" style="border-radius:10px;">
                    </div>
                <?php endif; ?>
                <input type="file" name="photo" accept="image/jpeg,image/png,image/webp">
            </div>

            <div class="form-group full">
                <label>Akses Grade</label>
                <div style="display:grid; grid-template-columns: repeat(auto-fit,minmax(130px,1fr)); gap:10px;">
                    <?php foreach ($grades as $grade): ?>
                    <label style="padding:12px; border:1px solid #e5e7eb; border-radius:10px; cursor:pointer;">
                        <input type="checkbox" name="grade_ids[]" value="<?= $grade['id'] ?>" <?= in_array((int)$grade['id'], $selectedGrades, true) ? 'checked' : '' ?>>
                        <?= e($grade['grade']) ?>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="form-group full">
                <label>Akses Subkelas</label>
                <div style="display:grid; grid-template-columns: repeat(auto-fit,minmax(130px,1fr)); gap:10px;">
                    <?php foreach ($classGroups as $class): ?>
                    <label style="padding:12px; border:1px solid #e5e7eb; border-radius:10px; cursor:pointer;">
                        <input type="checkbox" name="class_ids[]" value="<?= $class['id'] ?>" <?= in_array((int)$class['id'], $selectedClasses, true) ? 'checked' : '' ?>>
                        <?= e($class['grade']) ?> - <?= e($class['name']) ?>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <div style="margin-top:20px">
            <button type="submit" class="btn btn-primary">Simpan</button>
            <a href="index.php" class="btn btn-success">Kembali</a>
        </div>
    </form>
</div>

<?php require '../../includes/footer.php'; ?>