<?php
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../config/auth.php';
require_once '../../config/security.php';
require_once '../../includes/functions.php';

requireSuperAdmin();
$pageTitle = 'Admin / Guru Pemantau';

$stmt = $pdo->query("
    SELECT id, name, username, photo, created_at
    FROM users
    WHERE role = 'admin' AND deleted_at IS NULL
    ORDER BY name
");
$admins = $stmt->fetchAll();

require '../../includes/header.php';
?>

<div class="card">
    <div class="card-header">
        <div>
            <h3>Admin / Pemantau</h3>
            <small>Akses murid diatur oleh Super Admin</small>
        </div>
        <a href="create.php" class="btn btn-primary">+ Tambah Admin</a>
    </div>

    <div class="table-wrapper">
    <table>
        <thead>
            <tr>
                <th>No</th>
                <th>Foto</th>
                <th>Nama</th>
                <th>Username</th>
                <th>Akses</th>
                <th>Aksi</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($admins as $index => $admin): ?>
            <?php
            $stmt = $pdo->prepare("
                SELECT g.grade FROM admin_grade_permissions agp
                INNER JOIN grades g ON g.id = agp.grade_id
                WHERE agp.user_id = ? ORDER BY g.sort_order
            ");
            $stmt->execute([$admin['id']]);
            $gradePermissions = $stmt->fetchAll();

            $stmt = $pdo->prepare("
                SELECT g.grade, cg.name FROM admin_class_permissions acp
                INNER JOIN class_groups cg ON cg.id = acp.class_group_id
                INNER JOIN grades g ON g.id = cg.grade_id
                WHERE acp.user_id = ? ORDER BY g.sort_order, cg.name
            ");
            $stmt->execute([$admin['id']]);
            $classPermissions = $stmt->fetchAll();
            ?>
            <tr>
                <td><?= $index + 1 ?></td>
                <td>
                    <?php if (!empty($admin['photo'])): ?>
                        <img src="../../uploads/admins/<?= e($admin['photo']) ?>" width="45" height="45" style="object-fit:cover; border-radius:10px;">
                    <?php else: ?>
                        <div class="avatar"><?= strtoupper(substr($admin['name'], 0, 1)) ?></div>
                    <?php endif; ?>
                </td>
                <td><?= e($admin['name']) ?></td>
                <td><?= e($admin['username']) ?></td>
                <td>
                    <?php foreach ($gradePermissions as $permission): ?>
                        <span class="badge badge-success"><?= e($permission['grade']) ?></span>
                    <?php endforeach; ?>
                    <?php foreach ($classPermissions as $permission): ?>
                        <span class="badge badge-success"><?= e($permission['grade']) ?> - <?= e($permission['name']) ?></span>
                    <?php endforeach; ?>
                    <?php if (!$gradePermissions && !$classPermissions): ?>
                        <span class="badge badge-danger">Belum ada akses</span>
                    <?php endif; ?>
                </td>
                <td>
                    <a href="edit.php?id=<?= $admin['id'] ?>" class="btn btn-success" style="padding: 4px 8px; font-size: 12px;">Edit</a>
                    <form method="POST" action="delete.php" style="display:inline" onsubmit="return confirm('Hapus admin ini?')">
                        <?= csrfField() ?>
                        <input type="hidden" name="id" value="<?= $admin['id'] ?>">
                        <button class="btn btn-danger" type="submit" style="padding: 4px 8px; font-size: 12px;">Hapus</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>

<?php require '../../includes/footer.php'; ?>