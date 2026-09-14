<?php
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../config/auth.php';
require_once '../../config/security.php';
require_once '../../includes/functions.php';

requireSuperAdmin();
$pageTitle = 'Edit Grade';

$id = (int)($_GET['id'] ?? 0);
$stmt = $pdo->prepare("SELECT * FROM grades WHERE id = ? LIMIT 1");
$stmt->execute([$id]);
$grade = $stmt->fetch();

if (!$grade) { die('Grade tidak ditemukan.'); }

$units = $pdo->query("SELECT * FROM units ORDER BY id ASC")->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $unitId = (int)($_POST['unit_id'] ?? 0);
    $name = trim($_POST['grade'] ?? '');
    $sortOrder = (int)($_POST['sort_order'] ?? 0);

    $stmt = $pdo->prepare("UPDATE grades SET unit_id = ?, grade = ?, sort_order = ? WHERE id = ?");
    $stmt->execute([$unitId, $name, $sortOrder, $id]);

    flash('success', 'Grade berhasil diperbarui.');
    redirect('index.php');
}

require '../../includes/header.php';
?>

<div class="card">
    <h3>Edit Grade</h3>
    <form method="POST">
        <div class="form-grid">
            <div class="form-group">
                <label>Unit *</label>
                <select name="unit_id" required>
                    <option value="">Pilih Unit...</option>
                    <?php foreach ($units as $u): ?>
                        <option value="<?= $u['id'] ?>" <?= $grade['unit_id'] == $u['id'] ? 'selected' : '' ?>>
                            <?= e($u['unit']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>Nama Grade *</label>
                <input type="text" name="grade" value="<?= e($grade['grade']) ?>" required>
            </div>
            <div class="form-group">
                <label>Urutan</label>
                <input type="number" name="sort_order" value="<?= e($grade['sort_order']) ?>">
            </div>
        </div>
        <div style="margin-top:20px">
            <button class="btn btn-primary">Simpan</button>
            <a href="index.php" class="btn btn-success">Kembali</a>
        </div>
    </form>
</div>

<?php require '../../includes/footer.php'; ?>