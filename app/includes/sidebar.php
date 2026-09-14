<?php
$role = $_SESSION['role'] ?? '';
?>

<div class="sidebar-backdrop" id="sidebarBackdrop"></div>

<aside class="sidebar" id="sidebar">

    <div class="brand">
        <div class="brand-left">
            <div class="brand-logo-container">
                <img src="<?= BASE_URL ?>/logo.jpg" alt="Logo" class="brand-logo">
            </div>
            <div class="brand-details">
                <strong class="brand-name">AIS Absensi</strong>
                <small class="brand-org">An-Nahl Islamic School</small>
            </div>
        </div>
        <button type="button" class="sidebar-toggle-btn" id="sidebarToggleBtn" aria-label="Tutup / Sembunyikan Sidebar" title="Tutup / Sembunyikan Sidebar">
            <i class="fa-solid fa-xmark" style="font-size: 16px;"></i>
        </button>
    </div>

    <nav>
        <span class="menu-title">Utama</span>
        <a href="<?= BASE_URL ?>/dashboard.php"><i class="fa-solid fa-gauge-high fa-fw"></i> <span>Dashboard</span></a>
        <a href="<?= BASE_URL ?>/scanner.php"><i class="fa-solid fa-qrcode fa-fw"></i> <span>Scanner</span></a>

        <span class="menu-title">Kehadiran</span>
        <a href="<?= BASE_URL ?>/attendance/mobile_staff.php"><i class="fa-solid fa-location-dot fa-fw"></i> <span>Absen Mandiri GPS</span></a>
        <?php if (in_array($role, ['super_admin', 'admin'])): ?>
        <a href="<?= BASE_URL ?>/attendance/students.php"><i class="fa-solid fa-clipboard-user fa-fw"></i> <span>Absensi Siswa</span></a>
        <?php endif; ?>

        <?php if ($role === 'super_admin'): ?>
        <a href="<?= BASE_URL ?>/attendance/staff.php"><i class="fa-solid fa-clipboard-check fa-fw"></i> <span>Absensi Staff</span></a>

        <span class="menu-title">Master Data</span>
        <a href="<?= BASE_URL ?>/students/index.php"><i class="fa-solid fa-user-graduate fa-fw"></i> <span>Siswa</span></a>
        <a href="<?= BASE_URL ?>/staff/index.php"><i class="fa-solid fa-chalkboard-user fa-fw"></i> <span>Staff / Guru</span></a>
        <a href="<?= BASE_URL ?>/admins/index.php"><i class="fa-solid fa-user-shield fa-fw"></i> <span>Admin</span></a>
        <a href="<?= BASE_URL ?>/units/index.php"><i class="fa-solid fa-school fa-fw"></i> <span>Unit</span></a>
        <a href="<?= BASE_URL ?>/grades/index.php"><i class="fa-solid fa-layer-group fa-fw"></i> <span>Grade</span></a>
        <a href="<?= BASE_URL ?>/classes/index.php"><i class="fa-solid fa-shapes fa-fw"></i> <span>Subkelas</span></a>
        <a href="<?= BASE_URL ?>/activities/index.php"><i class="fa-solid fa-list-check fa-fw"></i> <span>Activities</span></a>
        <a href="<?= BASE_URL ?>/enrollments/index.php"><i class="fa-solid fa-graduation-cap fa-fw"></i> <span>Kenaikan Kelas</span></a>
        <a href="<?= BASE_URL ?>/weekly_schedules/index.php"><i class="fa-solid fa-calendar-week fa-fw"></i> <span>Jadwal Pekanan</span></a>
        <a href="<?= BASE_URL ?>/academic_years/index.php"><i class="fa-solid fa-calendar-days fa-fw"></i> <span>Tahun Ajaran</span></a>
        <a href="<?= BASE_URL ?>/audit_logs.php"><i class="fa-solid fa-clock-rotate-left fa-fw"></i> <span>Audit Logs</span></a>
        <?php endif; ?>

        <?php if (in_array($role, ['super_admin', 'admin'])): ?>
        <span class="menu-title">Laporan</span>
        <a href="<?= BASE_URL ?>/reports/index.php"><i class="fa-solid fa-file-invoice fa-fw"></i> <span>Laporan Absen</span></a>
        <a href="<?= BASE_URL ?>/overtimes/index.php"><i class="fa-solid fa-business-time fa-fw"></i> <span>Lembur Staff</span></a>
        <?php endif; ?>

        <span class="menu-title">Akun</span>
        <a href="<?= BASE_URL ?>/logout.php" class="logout-link"><i class="fa-solid fa-right-from-bracket fa-fw"></i> <span>Keluar</span></a>
    </nav>
</aside>