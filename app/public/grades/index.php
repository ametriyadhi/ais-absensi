<?php
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../config/auth.php';
require_once '../../config/security.php';
require_once '../../includes/functions.php';

requireSuperAdmin();
$pageTitle = 'Grade';

$grades = $pdo->query("
    SELECT 
        g.*, 
        un.unit AS unit_name,
        COUNT(cg.id) AS total_classes
    FROM grades g
    LEFT JOIN units un ON un.id = g.unit_id
    LEFT JOIN class_groups cg ON cg.grade_id = g.id
    GROUP BY g.id
    ORDER BY un.id ASC, g.sort_order ASC, g.grade ASC
")->fetchAll();

require '../../includes/header.php';
?>

<div class="card">
    <div class="card-header">
        <h3 style="display: flex; align-items: center; gap: 8px;">
            <i class="fa-solid fa-layer-group" style="color: var(--primary);"></i>
            Data Grade
        </h3>
        <div style="display: flex; gap: 10px; flex-wrap: wrap;">
            <a href="import.php" class="btn btn-success" style="background-color: #10b981; color: white;">
                <i class="fa-solid fa-file-excel"></i> Import Grade
            </a>
            <a href="create.php" class="btn btn-primary">
                <i class="fa-solid fa-plus"></i> Tambah Grade
            </a>
        </div>
    </div>

    <div class="table-wrapper">
        <table>
            <thead>
                <tr>
                    <th>No</th>
                    <th>Unit</th>
                    <th>Grade</th>
                    <th>Urutan</th>
                    <th>Subkelas</th>
                    <th>Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($grades as $index => $grade): ?>
                <tr>
                    <td><?= $index + 1 ?></td>
                    <td>
                        <span class="badge badge-primary" style="background:#0284c7; color:white;">
                            <?= e($grade['unit_name'] ?? '-') ?>
                        </span>
                    </td>
                    <td><?= e($grade['grade']) ?></td>
                    <td><?= e($grade['sort_order']) ?></td>
                    <td><?= number_format($grade['total_classes']) ?></td>
                    <td>
                        <a href="edit.php?id=<?= $grade['id'] ?>" class="btn btn-success" style="padding: 4px 8px; font-size:12px;">Edit</a>
                        <form method="POST" action="delete.php" style="display:inline" onsubmit="return confirm('Hapus grade ini?')">
                            <?= csrfField() ?>
                            <input type="hidden" name="id" value="<?= $grade['id'] ?>">
                            <button type="submit" class="btn btn-danger" style="padding: 4px 8px; font-size:12px;">Hapus</button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require '../../includes/footer.php'; ?>