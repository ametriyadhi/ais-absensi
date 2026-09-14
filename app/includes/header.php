<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

$pageTitle = $pageTitle ?? 'Absensi An-Nahl';
$flash = getFlash();

// Ambil foto user saat ini (agar real-time walau baru diubah)
$currentUserId = $_SESSION['user_id'] ?? 0;
$userPhoto = null;
if ($currentUserId > 0 && isset($pdo)) {
    $stmtPhoto = $pdo->prepare("SELECT photo FROM users WHERE id = ?");
    $stmtPhoto->execute([$currentUserId]);
    $userPhoto = $stmtPhoto->fetchColumn();
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e($pageTitle) ?> | Absensi An-Nahl Islamic School</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
<link rel="stylesheet" href="<?= ASSET_URL ?>/css/style.css?v=<?= time() ?>">
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<style>
    /* Styling Dropdown & Modal Header */
    .profile-container { position: relative; display: flex; align-items: center; gap: 12px; cursor: pointer; padding: 5px; border-radius: 8px; transition: 0.2s; }
    .profile-container:hover { background: #f1f5f9; }
    .dropdown-menu { display: none; position: absolute; top: 110%; right: 0; background: #fff; box-shadow: 0 4px 6px rgba(0,0,0,0.1); border-radius: 8px; border: 1px solid #e5e7eb; min-width: 160px; z-index: 1000; overflow: hidden; }
    .dropdown-menu a { display: block; padding: 12px 15px; color: #374151; text-decoration: none; font-size: 14px; transition: 0.2s; }
    .dropdown-menu a:hover { background: #f3f4f6; color: #0284c7; }
    .modal-overlay { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 2000; justify-content: center; align-items: center; }
    .modal-box { background: #fff; padding: 25px; border-radius: 12px; width: 100%; max-width: 400px; box-shadow: 0 10px 15px rgba(0,0,0,0.1); }

    /* Topbar & Button Styling */
    .topbar-left { display: flex; align-items: center; gap: 14px; }
    .menu-button {
        display: inline-flex !important;
        align-items: center;
        justify-content: center;
        width: 38px;
        height: 38px;
        border-radius: 8px;
        border: 1px solid #e2e8f0;
        background: #ffffff;
        color: #334155;
        cursor: pointer;
        transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
        padding: 0;
        flex-shrink: 0;
        box-shadow: 0 1px 2px rgba(0, 0, 0, 0.04);
    }
    .menu-button:hover {
        background: #f8fafc;
        color: #0f172a;
        border-color: #cbd5e1;
        box-shadow: 0 2px 4px rgba(0, 0, 0, 0.07);
    }
    .menu-button:active {
        transform: scale(0.95);
    }

    /* Anti-flicker & Layout Control */
    html.sidebar-collapsed .sidebar,
    body.sidebar-collapsed .sidebar {
        transform: translateX(-100%) !important;
    }
    html.sidebar-collapsed .main,
    body.sidebar-collapsed .main {
        margin-left: 0 !important;
        width: 100% !important;
    }
</style>
<script>
    (function() {
        if (localStorage.getItem('sidebar_collapsed') === 'true' && window.innerWidth > 768) {
            document.documentElement.classList.add('sidebar-collapsed');
        }
    })();
</script>
</head>
<body>

<div class="app">
<?php require __DIR__ . '/sidebar.php'; ?>
<main class="main">

<header class="topbar">
    <div class="topbar-left">
        <button class="menu-button" id="menuButton" title="Tampilkan / Sembunyikan Sidebar" aria-label="Toggle Sidebar">
            <i class="fa-solid fa-bars" style="font-size: 16px;"></i>
        </button>
        <div>
            <h2><?= e($pageTitle) ?></h2>
            <span class="date-text"><?= date('d F Y') ?></span>
        </div>
    </div>


    <div class="profile-container" onclick="toggleDropdown(event)">
        <div class="avatar" style="overflow: hidden; display: flex; justify-content: center; align-items: center; padding: 0;">
            <?php if (!empty($userPhoto)): ?>
                <img src="<?= BASE_URL ?>/../uploads/admins/<?= e($userPhoto) ?>" alt="Profil" style="width: 100%; height: 100%; object-fit: cover;">
            <?php else: ?>
                <?= strtoupper(substr($_SESSION['name'] ?? 'U', 0, 1)) ?>
            <?php endif; ?>
        </div>
        <div class="profile-text">
            <strong><?= e($_SESSION['name'] ?? '') ?></strong>
            <small><?= e($_SESSION['role'] ?? '') ?></small>
        </div>
        <!-- Dropdown Menu -->
        <div class="dropdown-menu" id="profileMenu">
            <a href="#" onclick="openModal(); return false;"><i class="fa-solid fa-camera fa-fw" style="margin-right: 6px;"></i> Ubah Foto Profil</a>
        </div>
    </div>
</header>

<!-- Modal Upload Foto -->
<div class="modal-overlay" id="photoModal" onclick="closeModal()">
    <div class="modal-box" onclick="event.stopPropagation()">
        <h3 style="margin-top:0; border-bottom:1px solid #e5e7eb; padding-bottom:15px; margin-bottom:15px;">Ubah Foto Profil</h3>
        <form action="<?= BASE_URL ?>/update_photo.php" method="POST" enctype="multipart/form-data">
            <?= csrfField() ?>
            <div style="margin-bottom: 20px;">
                <input type="file" name="photo" accept="image/jpeg,image/png,image/webp" required style="width:100%; padding:10px; border:1px dashed #cbd5e1; border-radius:8px;">
                <small style="color:#6b7280; display:block; margin-top:5px;">Format didukung: JPG, PNG, WEBP</small>
            </div>
            <div style="display: flex; justify-content: flex-end; gap: 10px;">
                <button type="button" class="btn btn-danger" onclick="closeModal()">Batal</button>
                <button type="submit" class="btn btn-primary">Simpan Foto</button>
            </div>
        </form>
    </div>
</div>

<script>
function toggleDropdown(e) {
    let menu = document.getElementById('profileMenu');
    menu.style.display = (menu.style.display === 'block') ? 'none' : 'block';
    e.stopPropagation();
}
document.addEventListener('click', function() {
    document.getElementById('profileMenu').style.display = 'none';
});
function openModal() {
    document.getElementById('photoModal').style.display = 'flex';
    document.getElementById('profileMenu').style.display = 'none';
}
function closeModal() {
    document.getElementById('photoModal').style.display = 'none';
}
</script>

<div class="content">
<?php if ($flash): ?>
    <div class="alert alert-<?= e($flash['type']) ?>">
        <?= e($flash['message']) ?>
    </div>
<?php endif; ?>