<?php
session_start();
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../config/security.php';
require_once '../config/auth.php';
require_once '../includes/functions.php';

requireSuperAdmin();

$actionFilter = trim($_GET['action'] ?? '');
$dateFilter = trim($_GET['date'] ?? '');
$userFilter = trim($_GET['user'] ?? '');

$where = ["1=1"];
$params = [];

if ($actionFilter !== '') {
    $where[] = "al.action = ?";
    $params[] = $actionFilter;
}
if ($dateFilter !== '') {
    $where[] = "DATE(al.created_at) = ?";
    $params[] = $dateFilter;
}
if ($userFilter !== '') {
    $where[] = "(u.name LIKE ? OR u.username LIKE ?)";
    $params[] = "%{$userFilter}%";
    $params[] = "%{$userFilter}%";
}

$whereSql = implode(' AND ', $where);

// Hitung total data untuk paginasi
$countStmt = $pdo->prepare("
    SELECT COUNT(*) 
    FROM audit_logs al
    LEFT JOIN users u ON u.id = al.user_id
    WHERE {$whereSql}
");
$countStmt->execute($params);
$totalRecords = (int)$countStmt->fetchColumn();

$perPage = 50;
$page = max(1, (int)($_GET['page'] ?? 1));
$totalPages = max(1, (int)ceil($totalRecords / $perPage));
$offset = ($page - 1) * $perPage;

$stmt = $pdo->prepare("
    SELECT al.*, u.name AS user_name, u.username AS user_username, u.role AS user_role
    FROM audit_logs al
    LEFT JOIN users u ON u.id = al.user_id
    WHERE {$whereSql}
    ORDER BY al.created_at DESC
    LIMIT {$perPage} OFFSET {$offset}
");
$stmt->execute($params);
$logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Daftar tipe action unik untuk filter dropdown
$distinctActions = $pdo->query("SELECT DISTINCT action FROM audit_logs ORDER BY action ASC")->fetchAll(PDO::FETCH_COLUMN);

$pageTitle = 'Audit Trail & Log Aktivitas';
require_once '../includes/header.php';
?>

<div class="card" style="margin-bottom: 20px;">
    <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
        <div>
            <h2 style="margin: 0; font-size: 20px; color: #1e293b;">📋 Audit Logs & Riwayat Aktivitas</h2>
            <p style="margin: 4px 0 0 0; color: #64748b; font-size: 13.5px;">
                Memantau seluruh aksi penting: penghapusan data, pembuatan akun, dan perubahan sistem.
            </p>
        </div>
        <span class="badge badge-info" style="font-size: 13px; padding: 6px 14px;">
            Total: <?= number_format($totalRecords, 0, ',', '.') ?> Rekaman
        </span>
    </div>
</div>

<!-- Filter Pencarian -->
<div class="card" style="margin-bottom: 20px; padding: 18px;">
    <form method="GET" action="" style="display: flex; gap: 12px; flex-wrap: wrap; align-items: flex-end;">
        <div style="flex: 1; min-width: 160px;">
            <label style="font-size: 12.5px; font-weight: 600; color: #475569; display: block; margin-bottom: 4px;">Aksi</label>
            <select name="action" class="form-control" style="width: 100%; padding: 8px 12px; border-radius: 8px; border: 1px solid #cbd5e1;">
                <option value="">-- Semua Aksi --</option>
                <?php foreach ($distinctActions as $act): ?>
                    <option value="<?= e($act) ?>" <?= $actionFilter === $act ? 'selected' : '' ?>><?= strtoupper(e($act)) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div style="flex: 1; min-width: 160px;">
            <label style="font-size: 12.5px; font-weight: 600; color: #475569; display: block; margin-bottom: 4px;">Tanggal</label>
            <input type="date" name="date" value="<?= e($dateFilter) ?>" style="width: 100%; padding: 8px 12px; border-radius: 8px; border: 1px solid #cbd5e1;">
        </div>
        <div style="flex: 1.5; min-width: 200px;">
            <label style="font-size: 12.5px; font-weight: 600; color: #475569; display: block; margin-bottom: 4px;">Pengguna (User / Admin)</label>
            <input type="text" name="user" value="<?= e($userFilter) ?>" placeholder="Ketik nama atau username..." style="width: 100%; padding: 8px 12px; border-radius: 8px; border: 1px solid #cbd5e1;">
        </div>
        <div style="display: flex; gap: 8px;">
            <button type="submit" class="btn btn-primary" style="padding: 9px 18px;">🔍 Filter</button>
            <?php if ($actionFilter || $dateFilter || $userFilter): ?>
                <a href="audit_logs.php" class="btn btn-secondary" style="padding: 9px 14px;">Reset</a>
            <?php endif; ?>
        </div>
    </form>
</div>

<!-- Tabel Log Aktivitas -->
<div class="card" style="padding: 0; overflow: hidden;">
    <div class="table-responsive">
        <table class="table" style="margin: 0; width: 100%; border-collapse: collapse;">
            <thead>
                <tr style="background: #f8fafc; border-bottom: 1px solid #e2e8f0; text-align: left;">
                    <th style="padding: 12px 16px; font-size: 12px; color: #64748b;">Waktu</th>
                    <th style="padding: 12px 16px; font-size: 12px; color: #64748b;">Pengguna</th>
                    <th style="padding: 12px 16px; font-size: 12px; color: #64748b;">Aksi</th>
                    <th style="padding: 12px 16px; font-size: 12px; color: #64748b;">Tabel / Target</th>
                    <th style="padding: 12px 16px; font-size: 12px; color: #64748b;">Rincian Data</th>
                    <th style="padding: 12px 16px; font-size: 12px; color: #64748b;">IP Address</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($logs)): ?>
                    <tr>
                        <td colspan="6" style="padding: 40px; text-align: center; color: #94a3b8;">
                            Belum ada log aktivitas yang tercatat.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($logs as $log): ?>
                        <tr style="border-bottom: 1px solid #f1f5f9; font-size: 13px;">
                            <td style="padding: 12px 16px; white-space: nowrap; color: #334155;">
                                <strong><?= date('d/m/Y', strtotime($log['created_at'])) ?></strong><br>
                                <span style="font-size: 11px; color: #64748b;"><?= date('H:i:s', strtotime($log['created_at'])) ?> WIB</span>
                            </td>
                            <td style="padding: 12px 16px; white-space: nowrap;">
                                <?php if ($log['user_name']): ?>
                                    <strong><?= e($log['user_name']) ?></strong><br>
                                    <span style="font-size: 11px; color: #64748b;">@<?= e($log['user_username']) ?> (<?= e($log['user_role']) ?>)</span>
                                <?php else: ?>
                                    <span style="color: #94a3b8;">Sistem Otomatis / Tamu</span>
                                <?php endif; ?>
                            </td>
                            <td style="padding: 12px 16px; white-space: nowrap;">
                                <?php
                                $badgeStyle = 'background: #e2e8f0; color: #334155;';
                                $act = strtolower($log['action']);
                                if (strpos($act, 'delete') !== false) {
                                    $badgeStyle = 'background: #fee2e2; color: #b91c1c;';
                                } elseif (strpos($act, 'create') !== false || strpos($act, 'insert') !== false) {
                                    $badgeStyle = 'background: #dcfce7; color: #15803d;';
                                } elseif (strpos($act, 'update') !== false) {
                                    $badgeStyle = 'background: #fef3c7; color: #b45309;';
                                } elseif (strpos($act, 'gps') !== false) {
                                    $badgeStyle = 'background: #e0e7ff; color: #4338ca;';
                                }
                                ?>
                                <span style="display: inline-block; padding: 4px 8px; border-radius: 6px; font-weight: 600; font-size: 11.5px; <?= $badgeStyle ?>">
                                    <?= strtoupper(e($log['action'])) ?>
                                </span>
                            </td>
                            <td style="padding: 12px 16px; white-space: nowrap; color: #475569;">
                                <code><?= e($log['entity_type'] ?? '-') ?></code>
                                <?php if ($log['entity_id']): ?>
                                    <span style="color: #94a3b8; font-size: 11px;">#<?= e($log['entity_id']) ?></span>
                                <?php endif; ?>
                            </td>
                            <td style="padding: 12px 16px; font-family: monospace; font-size: 11px; max-width: 320px; word-break: break-all; color: #334155;">
                                <?php if (!empty($log['details'])): ?>
                                    <div style="background: #f8fafc; padding: 6px; border-radius: 6px; border: 1px solid #e2e8f0;">
                                        <?= e(mb_strimwidth($log['details'], 0, 160, '...')) ?>
                                    </div>
                                <?php else: ?>
                                    <span style="color: #94a3b8;">-</span>
                                <?php endif; ?>
                            </td>
                            <td style="padding: 12px 16px; white-space: nowrap; color: #64748b; font-size: 12px;">
                                <?= e($log['ip_address'] ?? '-') ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div style="margin-top: 20px;">
    <?= renderPagination($page, $totalPages) ?>
</div>

<?php require_once '../includes/footer.php'; ?>
