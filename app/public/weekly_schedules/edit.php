<?php
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../config/auth.php';
require_once '../../config/security.php';
require_once '../../includes/functions.php';

requireSuperAdmin();
$pageTitle = 'Edit Jadwal Pekanan';

$id = (int)($_GET['id'] ?? 0);
$stmt = $pdo->prepare("SELECT * FROM weekly_schedules WHERE id = ?");
$stmt->execute([$id]);
$schedule = $stmt->fetch();

if (!$schedule) {
    die('Jadwal tidak ditemukan.');
}

$units = $pdo->query("SELECT * FROM units ORDER BY id ASC")->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $unitId = (int)$_POST['unit_id'];
    $dayCode = (int)$_POST['day_code'];
    $days = [1=>'Senin', 2=>'Selasa', 3=>'Rabu', 4=>'Kamis', 5=>'Jumat', 6=>'Sabtu', 7=>'Minggu'];
    $dayName = $days[$dayCode] ?? '';

    $update = $pdo->prepare("
        UPDATE weekly_schedules 
        SET unit_id = ?, day_name = ?, day_code = ?, 
            student_in = ?, student_late = ?, student_out = ?, 
            staff_in = ?, staff_late = ?, staff_out = ?, is_active = ?
        WHERE id = ?
    ");

    $update->execute([
        $unitId, $dayName, $dayCode,
        $_POST['student_in'], $_POST['student_late'], $_POST['student_out'],
        $_POST['staff_in'], $_POST['staff_late'], $_POST['staff_out'],
        $_POST['is_active'], $id
    ]);

    redirect('index.php');
}

require '../../includes/header.php';
?>

<div class="card">
    <h3>Edit Jadwal Pekanan</h3>
    <form method="POST">
        <div class="form-grid">
            <div class="form-group">
                <label>Unit</label>
                <select name="unit_id" required>
                    <?php foreach ($units as $unit): ?>
                        <option value="<?= $unit['id'] ?>" <?= $schedule['unit_id'] == $unit['id'] ? 'selected' : '' ?>>
                            <?= e($unit['unit']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>Hari</label>
                <select name="day_code" required>
                    <?php 
                    $hari = [1=>'Senin', 2=>'Selasa', 3=>'Rabu', 4=>'Kamis', 5=>'Jumat', 6=>'Sabtu', 7=>'Minggu'];
                    foreach($hari as $code => $name): 
                    ?>
                        <option value="<?= $code ?>" <?= $schedule['day_code'] == $code ? 'selected' : '' ?>><?= $name ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="form-group"><label>Siswa: Jam Masuk</label><input type="time" name="student_in" value="<?= e($schedule['student_in']) ?>" required></div>
            <div class="form-group"><label>Siswa: Batas Telat</label><input type="time" name="student_late" value="<?= e($schedule['student_late']) ?>" required></div>
            <div class="form-group"><label>Siswa: Jam Pulang</label><input type="time" name="student_out" value="<?= e($schedule['student_out']) ?>" required></div>

            <div class="form-group"><label>Staff: Jam Masuk</label><input type="time" name="staff_in" value="<?= e($schedule['staff_in']) ?>" required></div>
            <div class="form-group"><label>Staff: Batas Telat</label><input type="time" name="staff_late" value="<?= e($schedule['staff_late']) ?>" required></div>
            <div class="form-group"><label>Staff: Jam Pulang</label><input type="time" name="staff_out" value="<?= e($schedule['staff_out']) ?>" required></div>

            <div class="form-group">
                <label>Status</label>
                <select name="is_active">
                    <option value="active" <?= $schedule['is_active'] === 'active' ? 'selected' : '' ?>>Aktif</option>
                    <option value="inactive" <?= $schedule['is_active'] === 'inactive' ? 'selected' : '' ?>>Nonaktif</option>
                </select>
            </div>
        </div>
        <div style="margin-top:20px">
            <button class="btn btn-primary">Update</button>
            <a href="index.php" class="btn btn-success">Batal</a>
        </div>
    </form>
</div>
<?php require '../../includes/footer.php'; ?>