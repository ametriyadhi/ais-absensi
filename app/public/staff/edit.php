<?php
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../config/auth.php';
require_once '../../config/security.php';
require_once '../../includes/functions.php';

requireSuperAdmin();
$pageTitle = 'Edit Staff';

$id = (int)($_GET['id'] ?? 0);

$stmt = $pdo->prepare("SELECT s.*, u.username, u.role FROM staff s LEFT JOIN users u ON u.id = s.user_id WHERE s.id = ? LIMIT 1");
$stmt->execute([$id]);
$staff = $stmt->fetch();

if (!$staff) { die('Staff tidak ditemukan.'); }

$units = $pdo->query("SELECT * FROM units ORDER BY id ASC")->fetchAll();
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $nik = trim($_POST['nik'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $unitId = (int)($_POST['unit_id'] ?? 0);
    $photoNameSave = $staff['photo']; // Default pakai foto lama

    try {
        $pdo->beginTransaction();
        
        // Proses Upload Foto Baru
        if (!empty($_FILES['photo']['name']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
            $ext = pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION);
            $photoNameSave = uniqid('staff_', true) . '.' . $ext;
            $uploadDir = '../../uploads/staff/';
            if (!is_dir($uploadDir)) { mkdir($uploadDir, 0755, true); }
            
            // Hapus file foto lama jika ada
            if (!empty($staff['photo']) && file_exists($uploadDir . $staff['photo'])) {
                unlink($uploadDir . $staff['photo']);
            }
            
            move_uploaded_file($_FILES['photo']['tmp_name'], $uploadDir . $photoNameSave);
        }

        $userId = $staff['user_id'];

        // Kelola Akun Pengguna (Users)
        if (!empty($username)) {
            if (empty($userId)) {
                // Skenario 1: Belum ada user, buat baru dengan role 'staff'
                $passToHash = !empty($password) ? $password : '123456'; // Password default jika kosong saat pembuatan baru
                $stmtUser = $pdo->prepare("INSERT INTO users (username, password, name, role, created_at) VALUES (?, ?, ?, 'staff', NOW())");
                $stmtUser->execute([$username, hashPassword($passToHash), $name]);
                $userId = $pdo->lastInsertId();
            } else {
                // Skenario 2: Sudah ada user, lakukan update data & pastikan role 'staff'
                if ($password !== '') {
                    $stmtUser = $pdo->prepare("UPDATE users SET username = ?, password = ?, name = ?, role = 'staff' WHERE id = ?");
                    $stmtUser->execute([$username, hashPassword($password), $name, $userId]);
                } else {
                    $stmtUser = $pdo->prepare("UPDATE users SET username = ?, name = ?, role = 'staff' WHERE id = ?");
                    $stmtUser->execute([$username, $name, $userId]);
                }
            }
        }

        // Update data pada tabel staff termasuk menghubungkan user_id-nya
        $stmt = $pdo->prepare("UPDATE staff SET unit_id = ?, user_id = ?, name = ?, nik = ?, photo = ? WHERE id = ?");
        $stmt->execute([$unitId, $userId, $name, $nik, $photoNameSave, $id]);

        $pdo->commit();
        flash('success', 'Staff berhasil diperbarui.');
        redirect('index.php');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        $error = $e->getMessage();
    }
}

require '../../includes/header.php';
?>

<div class="card">
    <h3>Edit Staff / Guru</h3>
    <?php if ($error): ?>
        <div class="alert alert-danger"><?= e($error) ?></div>
    <?php endif; ?>

    <form method="POST" enctype="multipart/form-data">
        <div class="form-grid">
            <div class="form-group">
                <label>Unit *</label>
                <select name="unit_id" required>
                    <option value="">Pilih Unit...</option>
                    <?php foreach ($units as $u): ?>
                        <option value="<?= $u['id'] ?>" <?= $staff['unit_id'] == $u['id'] ? 'selected' : '' ?>>
                            <?= e($u['unit']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>Nama Lengkap *</label>
                <input type="text" name="name" value="<?= e($staff['name']) ?>" required>
            </div>
            <div class="form-group">
                <label>NIK / Barcode *</label>
                <input type="text" name="nik" value="<?= e($staff['nik']) ?>" required>
            </div>
            <div class="form-group">
                <label>Username *</label>
                <input type="text" name="username" value="<?= e($staff['username']) ?>" required>
            </div>
            <div class="form-group">
                <label>Password Baru</label>
                <input type="password" name="password" placeholder="Kosongkan jika tidak diganti">
            </div>
            <div class="form-group full">
                <label>Ganti Foto (Opsional)</label>
                <?php if (!empty($staff['photo'])): ?>
                    <div style="margin-bottom:10px;">
                        <img src="../../uploads/staff/<?= e($staff['photo']) ?>" width="60" style="border-radius:10px;">
                    </div>
                <?php endif; ?>
                <input type="file" name="photo" accept="image/jpeg,image/png,image/webp">
                <small>Maksimal 2 MB.</small>
            </div>
        </div>
        <div style="margin-top:20px">
            <button class="btn btn-primary" type="submit">Simpan</button>
            <a href="index.php" class="btn btn-success">Kembali</a>
        </div>
    </form>
</div>

<?php require '../../includes/footer.php'; ?>