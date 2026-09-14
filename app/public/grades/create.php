<?php
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../config/auth.php';
require_once '../../config/security.php';
require_once '../../includes/functions.php';

requireSuperAdmin();
$pageTitle = 'Tambah Grade';

$units = $pdo->query("SELECT * FROM units ORDER BY id ASC")->fetchAll();
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $unitId = (int)($_POST['unit_id'] ?? 0);
    $grade = trim($_POST['grade'] ?? '');
    $sortOrder = (int)($_POST['sort_order'] ?? 0);

    if ($unitId <= 0 || $grade === '') {
        $error = 'Unit dan Nama grade wajib diisi.';
    } else {
        try {
            $stmt = $pdo->prepare("INSERT INTO grades (unit_id, grade, sort_order) VALUES (?, ?, ?)");
            $stmt->execute([$unitId, $grade, $sortOrder]);

            flash('success', 'Grade berhasil dibuat.');
            redirect('index.php');
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    }
}

require '../../includes/header.php';
?>

<div class="card">
    <h3>Tambah Grade</h3>
    <?php if ($error): ?>
        <div class="alert alert-danger"><?= e($error) ?></div>
    <?php endif; ?>

    <form method="POST">
        <div class="form-grid">
            <div class="form-group">
                <label>Unit *</label>
                <select name="unit_id" required>
                    <option value="">Pilih Unit...</option>
                    <?php foreach ($units as $u): ?>
                        <option value="<?= $u['id'] ?>"><?= e($u['unit']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>Nama Grade *</label>
                <input type="text" name="grade" placeholder="Contoh: Kelas 7" required>
            </div>
            <div class="form-group">
                <label>Urutan (Opsional)</label>
                <input type="number" name="sort_order" value="0">
            </div>
        </div>
        <div style="margin-top:20px">
            <button class="btn btn-primary" type="submit">Simpan</button>
            <a href="index.php" class="btn btn-success">Kembali</a>
        </div>
    </form>
</div>

<?php require '../../includes/footer.php'; ?>