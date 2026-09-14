<?php
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../config/auth.php';
require_once '../../config/security.php';
require_once '../../includes/functions.php';

requireSuperAdmin();
$pageTitle = 'Staff / Guru';

$name = trim($_GET['name'] ?? '');
$nik = trim($_GET['nik'] ?? '');
$unitId = (int)($_GET['unit_id'] ?? 0);

$units = $pdo->query("SELECT * FROM units ORDER BY id ASC")->fetchAll();

// MENGIZINKAN ROLE 'staff' MAUPUN 'admin' UNTUK MUNCUL
$where = [
    "s.deleted_at IS NULL",
    "(u.deleted_at IS NULL OR u.id IS NULL)",
    "(u.role IN ('staff', 'admin') OR u.role IS NULL)"
];
$params = [];

if ($name !== '') { $where[] = "s.name LIKE ?"; $params[] = "%{$name}%"; }
if ($nik !== '') { $where[] = "s.nik LIKE ?"; $params[] = "%{$nik}%"; }
if ($unitId > 0) { $where[] = "s.unit_id = ?"; $params[] = $unitId; }

$whereSql = implode(' AND ', $where);

// MENGAMBIL u.role UNTUK KEPERLUAN PENANDAAN
$stmt = $pdo->prepare("
    SELECT s.id, s.name, s.nik, s.photo, u.username, u.role, un.unit AS unit_name
    FROM staff s
    LEFT JOIN users u ON u.id = s.user_id
    LEFT JOIN units un ON un.id = s.unit_id
    WHERE {$whereSql}
    ORDER BY s.name
");
$stmt->execute($params);
$staff = $stmt->fetchAll();

require '../../includes/header.php';
?>

<?php
$exportQuery = http_build_query($_GET);
?>

<div class="card">
    <div class="card-header">
        <div>
            <h3>Staff / Guru</h3>
            <small>Staff dipantau oleh Super Admin</small>
        </div>
        <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
            <a href="excel.php?<?= e($exportQuery) ?>" class="btn btn-success" style="display: inline-flex; align-items: center; gap: 6px;">📊 Export Excel</a>
            <a href="import.php" class="btn" style="background-color: #10b981; color: white;">📄 Import Excel (XLSX)</a>
            <a href="create.php" class="btn btn-primary">+ Tambah Staff</a>
        </div>
    </div>

    <form method="GET">
        <div class="filter-grid">
            <div class="form-group">
                <label>Nama</label>
                <input type="text" name="name" value="<?= e($name) ?>" placeholder="Cari nama...">
            </div>
            <div class="form-group">
                <label>NIK / Barcode</label>
                <input type="text" name="nik" value="<?= e($nik) ?>">
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
            <button type="submit" class="btn btn-primary" style="margin-top: 22px;">🔎 Cari</button>
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
                    <th>NIK / Barcode</th>
                    <th>Unit</th>
                    <th>Username</th>
                    <th>Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($staff as $index => $person): ?>
                <tr>
                    <td><?= $index + 1 ?></td>
                    <td>
                        <?php if (!empty($person['photo'])): ?>
                            <img src="../../uploads/staff/<?= e($person['photo']) ?>" width="45" height="45" style="object-fit:cover; border-radius:10px;">
                        <?php else: ?>
                            <div class="avatar"><?= strtoupper(substr($person['name'], 0, 1)) ?></div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?= e($person['name']) ?>
                        <!-- PENANDA JIKA STAFF JUGA SEBAGAI ADMIN -->
                        <?php if ($person['role'] === 'admin'): ?>
                            <span class="badge badge-success" style="font-size: 10px; margin-left: 5px; vertical-align: middle;">Admin</span>
                        <?php endif; ?>
                    </td>
                    <td><?= e($person['nik']) ?></td>
                    <td>
                        <span class="badge badge-primary" style="background:#0284c7; color:white;">
                            <?= e($person['unit_name'] ?? '-') ?>
                        </span>
                    </td>
                    <td><?= e($person['username']) ?></td>
                    <td>
                        <a href="edit.php?id=<?= $person['id'] ?>" class="btn btn-success" style="padding: 4px 8px; font-size:12px;">Edit</a>
                        
                        <!-- TOMBOL GANTI ROLE ADMIN / STAFF -->
                        <?php if (!empty($person['username'])): ?>
                            <form method="POST" action="toggle_role.php" style="display:inline" onsubmit="return confirm('Ubah status akses pengguna ini?');">
                                <?= csrfField() ?>
                                <input type="hidden" name="id" value="<?= $person['id'] ?>">
                                <?php if ($person['role'] === 'admin'): ?>
                                    <button class="btn btn-warning" type="submit" style="padding: 4px 8px; font-size:12px; background-color: #f59e0b; color: white;">Jadikan Staff</button>
                                <?php else: ?>
                                    <button class="btn btn-info" type="submit" style="padding: 4px 8px; font-size:12px; background-color: #3b82f6; color: white;">Jadikan Admin</button>
                                <?php endif; ?>
                            </form>
                        <?php endif; ?>

                        <form method="POST" action="delete.php" style="display:inline" onsubmit="return confirm('Hapus staff ini?')">
                            <?= csrfField() ?>
                            <input type="hidden" name="id" value="<?= $person['id'] ?>">
                            <button class="btn btn-danger" type="submit" style="padding: 4px 8px; font-size:12px;">Hapus</button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require '../../includes/footer.php'; ?>