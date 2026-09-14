<?php
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../config/auth.php';
require_once '../../config/security.php';
require_once '../../includes/functions.php';

requireSuperAdmin();
$pageTitle = 'Master Jadwal Pekanan';

$schedules = $pdo->query("
    SELECT ws.*, u.unit AS unit_name
    FROM weekly_schedules ws
    INNER JOIN units u ON u.id = ws.unit_id
    ORDER BY u.id ASC, ws.day_code ASC
")->fetchAll();

require '../../includes/header.php';
?>

<div class="card">
    <div class="card-header">
        <div>
            <h3>Master Jadwal Pekanan</h3>
            <small>Aturan Jam Masuk & Pulang Harian per Unit</small>
        </div>
        <a href="create.php" class="btn btn-primary">+ Tambah Jadwal</a>
    </div>

    <div class="table-wrapper">
        <table>
            <thead>
                <tr>
                    <th>Unit</th>
                    <th>Hari</th>
                    <th>Jam Siswa (Masuk - Telat - Pulang)</th>
                    <th>Jam Staff (Masuk - Telat - Pulang)</th>
                    <th>Status</th>
                    <th>Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$schedules): ?>
                    <tr><td colspan="6" style="text-align:center;">Belum ada data jadwal pekanan.</td></tr>
                <?php endif; ?>

                <?php foreach ($schedules as $schedule): ?>
                    <tr>
                        <td>
                            <span class="badge badge-primary" style="background:#0284c7; color:white;">
                                <?= e($schedule['unit_name']) ?>
                            </span>
                        </td>
                        <td><strong><?= e($schedule['day_name']) ?></strong></td>
                        <td>
                            <small>
                                🟢 <?= e(substr($schedule['student_in'], 0, 5)) ?> &nbsp;|&nbsp; 
                                🔴 <?= e(substr($schedule['student_late'], 0, 5)) ?> &nbsp;|&nbsp; 
                                🏁 <?= e(substr($schedule['student_out'], 0, 5)) ?>
                            </small>
                        </td>
                        <td>
                            <small>
                                🟢 <?= e(substr($schedule['staff_in'], 0, 5)) ?> &nbsp;|&nbsp; 
                                🔴 <?= e(substr($schedule['staff_late'], 0, 5)) ?> &nbsp;|&nbsp; 
                                🏁 <?= e(substr($schedule['staff_out'], 0, 5)) ?>
                            </small>
                        </td>
                        <td>
                            <?php if ($schedule['is_active'] === 'active'): ?>
                                <span class="badge badge-success">Aktif</span>
                            <?php else: ?>
                                <span class="badge badge-danger">Nonaktif</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <a href="edit.php?id=<?= $schedule['id'] ?>" class="btn btn-success" style="padding: 4px 8px; font-size: 12px; margin-right: 4px;">Edit</a>
                            
                            <form method="POST" action="delete.php" style="display:inline;">
                                <?= csrfField() ?>
                                <input type="hidden" name="id" value="<?= $schedule['id'] ?>">
                                <button type="submit" class="btn btn-danger" style="padding: 4px 8px; font-size: 12px;" onclick="return confirm('Yakin ingin menghapus jadwal ini? Data absensi yang sudah berlalu TIDAK akan hilang atau rusak.');">Hapus</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php require '../../includes/footer.php'; ?>