<?php
session_start();
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../config/security.php';

$result = $_SESSION['scan_result'] ?? null;
unset($_SESSION['scan_result']);

$error = $_GET['error'] ?? null;
$errorText = '';
if ($error === 'not_found') {
    $errorText = 'Data kartu atau NIS / NIK tidak ditemukan dalam sistem!';
} elseif ($error === 'empty') {
    $errorText = 'Kode barcode tidak boleh kosong!';
}

// Inisialisasi data statistik kehadiran hari ini & riwayat presensi terbaru
$todayStudents = 0;
$todayStaff = 0;
$todayOntime = 0;
$ontimePercent = 100;
$recentScans = [];

try {
    $todayStudents = (int)$pdo->query("SELECT COUNT(*) FROM student_attendances WHERE attendance_date = CURDATE()")->fetchColumn();
    $todayStaff = (int)$pdo->query("SELECT COUNT(*) FROM staff_attendances WHERE attendance_date = CURDATE()")->fetchColumn();
    
    $studentOntime = (int)$pdo->query("SELECT COUNT(*) FROM student_attendances WHERE attendance_date = CURDATE() AND status = 'tepat_waktu'")->fetchColumn();
    $staffOntime = (int)$pdo->query("SELECT COUNT(*) FROM staff_attendances WHERE attendance_date = CURDATE() AND status = 'tepat_waktu'")->fetchColumn();
    
    $totalHadir = $todayStudents + $todayStaff;
    $totalOntime = $studentOntime + $staffOntime;
    $ontimePercent = $totalHadir > 0 ? round(($totalOntime / $totalHadir) * 100) : 100;

    // Ambil 4 riwayat scan terakhir hari ini
    $recentStmt = $pdo->query("
        (SELECT s.name, cg.name AS sub_info, COALESCE(sa.time_in, sa.time_out) as scan_time, sa.status, 'student' AS type, s.photo
         FROM student_attendances sa
         JOIN students s ON s.id = sa.student_id
         JOIN student_enrollments se ON se.id = sa.enrollment_id
         JOIN class_groups cg ON cg.id = se.class_group_id
         WHERE sa.attendance_date = CURDATE()
         ORDER BY sa.id DESC LIMIT 4)
        UNION ALL
        (SELECT st.name, 'Staff / Guru' AS sub_info, COALESCE(sta.time_in, sta.time_out) as scan_time, sta.status, 'staff' AS type, st.photo
         FROM staff_attendances sta
         JOIN staff st ON st.id = sta.staff_id
         WHERE sta.attendance_date = CURDATE()
         ORDER BY sta.id DESC LIMIT 4)
        ORDER BY scan_time DESC
        LIMIT 4
    ");
    $recentScans = $recentStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (\Throwable $e) {
    // Fallback jika query database awal terdapat kendala
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Smart Gate Scanner | An Nahl Islamic School</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=JetBrains+Mono:wght@500;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
<link rel="stylesheet" href="<?= ASSET_URL ?>/css/style.css?v=<?= time() ?>">
<script src="<?= ASSET_URL ?>/js/html5-qrcode.min.js"></script>
<style>
    /* ==========================================================================
       KIOSK DISPLAY DESIGN SYSTEM - AN NAHL ISLAMIC SCHOOL
       Modern Deep Slate & Indigo Theme (Matching AIS Application Identity)
       ========================================================================== */
    :root {
        --kiosk-bg-start: #1e1b4b;       /* Indigo 950 / Deep Midnight Tint */
        --kiosk-bg-mid: #0f172a;         /* Slate 900 - Senada dengan Sidebar Aplikasi */
        --kiosk-bg-end: #020617;         /* Slate 950 - Deep Void */
        --kiosk-primary: #4f46e5;        /* Primary Indigo Aplikasi (#4f46e5) */
        --kiosk-primary-hover: #4338ca;  /* Indigo 700 */
        --kiosk-primary-light: #818cf8;  /* Indigo 400 */
        --kiosk-primary-glow: rgba(79, 70, 229, 0.45);
        --kiosk-emerald: #10b981;        /* Status: Tepat Waktu / Sukses */
        --kiosk-emerald-light: #34d399;
        --kiosk-amber: #f59e0b;          /* Status: Terlambat / Peringatan */
        --kiosk-amber-light: #fbbf24;
        --kiosk-cyan: #38bdf8;           /* Aksen Cyber Clock & Laser */
        --kiosk-indigo: #6366f1;         /* Aksen Indigo Violet */
        --kiosk-card-bg: rgba(15, 23, 42, 0.65); /* Slate Glassmorphic Card */
        --kiosk-card-border: rgba(255, 255, 255, 0.09);
        --kiosk-card-border-glow: rgba(99, 102, 241, 0.28);
    }

    * {
        box-sizing: border-box;
    }

    body.scanner-page {
        margin: 0;
        padding: 0;
        min-height: 100vh;
        background: radial-gradient(circle at 50% -10%, var(--kiosk-bg-start) 0%, var(--kiosk-bg-mid) 48%, var(--kiosk-bg-end) 95%);
        background-attachment: fixed;
        color: #f8fafc;
        font-family: 'Plus Jakarta Sans', system-ui, -apple-system, sans-serif;
        overflow-x: hidden;
    }

    .kiosk-wrapper {
        max-width: 1560px;
        margin: 0 auto;
        padding: 20px 24px 30px;
        min-height: 100vh;
        display: flex;
        flex-direction: column;
    }

    /* 1. TOPBAR & BRANDING HEADER */
    .kiosk-topbar {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 12px 22px;
        background: rgba(15, 23, 42, 0.75);
        border: 1px solid var(--kiosk-card-border);
        border-radius: 20px;
        backdrop-filter: blur(16px);
        margin-bottom: 22px;
        box-shadow: 0 10px 30px rgba(0, 0, 0, 0.4);
    }

    .kiosk-brand {
        display: flex;
        align-items: center;
        gap: 16px;
    }

    .kiosk-logo-box {
        width: 52px;
        height: 52px;
        border-radius: 14px;
        background: #ffffff;
        display: flex;
        align-items: center;
        justify-content: center;
        overflow: hidden;
        box-shadow: 0 4px 20px rgba(79, 70, 229, 0.35);
        border: 2px solid rgba(255, 255, 255, 0.85);
        flex-shrink: 0;
    }

    .kiosk-logo-img {
        width: 100%;
        height: 100%;
        object-fit: cover;
    }

    .kiosk-brand-text {
        display: flex;
        flex-direction: column;
    }

    .kiosk-brand-subtitle {
        font-size: 11px;
        font-weight: 800;
        letter-spacing: 2px;
        color: var(--kiosk-primary-light);
        text-transform: uppercase;
        margin-bottom: 2px;
    }

    .kiosk-brand-title {
        font-size: 22px;
        font-weight: 800;
        color: #ffffff;
        margin: 0;
        letter-spacing: -0.02em;
        line-height: 1.2;
    }

    .kiosk-actions {
        display: flex;
        align-items: center;
        gap: 10px;
    }

    .kiosk-network-pill {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        padding: 7px 14px;
        border-radius: 20px;
        font-size: 12px;
        font-weight: 700;
        background: rgba(255, 255, 255, 0.08);
        border: 1px solid rgba(255, 255, 255, 0.12);
        color: #cbd5e1;
    }

    .kiosk-network-pill.online .status-dot {
        width: 8px;
        height: 8px;
        background: #22c55e;
        border-radius: 50%;
        box-shadow: 0 0 10px #22c55e;
    }

    .kiosk-network-pill.offline {
        background: rgba(239, 68, 68, 0.2);
        color: #fca5a5;
        border-color: rgba(239, 68, 68, 0.4);
    }

    .kiosk-network-pill.offline .status-dot {
        width: 8px;
        height: 8px;
        background: #ef4444;
        border-radius: 50%;
        box-shadow: 0 0 10px #ef4444;
    }

    .kiosk-btn-tool {
        width: 40px;
        height: 40px;
        border-radius: 12px;
        background: rgba(255, 255, 255, 0.08);
        border: 1px solid rgba(255, 255, 255, 0.15);
        color: #cbd5e1;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
        font-size: 15px;
        transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
    }

    .kiosk-btn-tool:hover {
        background: rgba(255, 255, 255, 0.18);
        color: #ffffff;
        transform: translateY(-1px);
    }

    .kiosk-btn-tool.muted {
        background: rgba(239, 68, 68, 0.2);
        color: #fca5a5;
        border-color: rgba(239, 68, 68, 0.4);
    }

    .kiosk-btn-back {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        padding: 9px 18px;
        background: rgba(255, 255, 255, 0.08);
        color: #cbd5e1;
        text-decoration: none;
        border-radius: 12px;
        font-weight: 700;
        font-size: 13.5px;
        border: 1px solid rgba(255, 255, 255, 0.15);
        transition: all 0.2s ease;
    }

    .kiosk-btn-back:hover {
        background: var(--kiosk-primary);
        color: #ffffff;
        border-color: var(--kiosk-primary-light);
        transform: translateY(-1px);
        box-shadow: 0 4px 14px rgba(79, 70, 229, 0.35);
    }

    /* 2. DUAL-COLUMN KIOSK GRID LAYOUT */
    .kiosk-main-grid {
        display: grid;
        grid-template-columns: minmax(0, 1.15fr) minmax(0, 0.85fr);
        gap: 24px;
        align-items: start;
        flex: 1;
    }

    /* KOLOM KIRI: SCANNER STAGE */
    .kiosk-scanner-col {
        display: flex;
        flex-direction: column;
        gap: 16px;
    }

    .scanner-mode-switch {
        display: flex;
        gap: 8px;
        background: rgba(255, 255, 255, 0.06);
        border: 1px solid var(--kiosk-card-border);
        padding: 6px;
        border-radius: 16px;
        width: 100%;
        backdrop-filter: blur(8px);
    }

    .mode-btn {
        flex: 1;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
        padding: 11px 16px;
        border-radius: 11px;
        border: none;
        background: transparent;
        color: #94a3b8;
        font-size: 13.5px;
        font-weight: 700;
        cursor: pointer;
        transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
        white-space: nowrap;
    }

    .mode-btn:hover {
        color: #ffffff;
        background: rgba(255, 255, 255, 0.06);
    }

    .mode-btn.active {
        background: linear-gradient(135deg, var(--kiosk-primary), #6366f1);
        color: #ffffff;
        box-shadow: 0 4px 18px rgba(79, 70, 229, 0.45);
    }

    .camera-card {
        background: var(--kiosk-card-bg);
        border: 1px solid var(--kiosk-card-border);
        border-radius: 26px;
        padding: 22px;
        box-shadow: 0 20px 40px rgba(0, 0, 0, 0.4);
        position: relative;
    }

    .viewfinder-wrapper {
        position: relative;
        width: 100%;
        min-height: 400px;
        border-radius: 20px;
        overflow: hidden;
        background: #000000;
        display: flex;
        align-items: center;
        justify-content: center;
        box-shadow: inset 0 0 40px rgba(0, 0, 0, 0.8);
    }

    #reader {
        width: 100% !important;
        height: 100% !important;
        min-height: 400px;
        border: none !important;
        position: relative;
    }

    #reader video {
        width: 100% !important;
        min-height: 400px;
        max-height: 520px;
        border-radius: 20px !important;
        object-fit: cover !important;
        display: block !important;
    }

    #reader.mirror-video video {
        transform: scaleX(-1);
    }

    #qr-shaded-region {
        display: none !important;
    }

    /* Reticle Laser Modern */
    .scan-reticle {
        pointer-events: none;
        position: absolute;
        inset: 14% 16%;
        z-index: 10;
        border-radius: 18px;
        box-shadow: 0 0 0 9999px rgba(2, 6, 23, 0.55);
        border: 1px solid rgba(99, 102, 241, 0.3);
    }

    .reticle-corner {
        position: absolute;
        width: 26px;
        height: 26px;
        border-color: var(--kiosk-primary-light);
        border-style: solid;
    }

    .reticle-corner.top-left { top: -2px; left: -2px; border-width: 4px 0 0 4px; border-top-left-radius: 12px; }
    .reticle-corner.top-right { top: -2px; right: -2px; border-width: 4px 4px 0 0; border-top-right-radius: 12px; }
    .reticle-corner.bottom-left { bottom: -2px; left: -2px; border-width: 0 0 4px 4px; border-bottom-left-radius: 12px; }
    .reticle-corner.bottom-right { bottom: -2px; right: -2px; border-width: 0 4px 4px 0; border-bottom-right-radius: 12px; }

    .scan-laser {
        position: absolute;
        width: 100%;
        height: 3px;
        background: linear-gradient(90deg, transparent, #818cf8, #38bdf8, transparent);
        box-shadow: 0 0 16px #818cf8;
        animation: laserScan 2.4s ease-in-out infinite alternate;
    }

    @keyframes laserScan {
        0% { top: 6%; opacity: 0.8; }
        100% { top: 92%; opacity: 1; }
    }

    .camera-loading {
        position: absolute;
        inset: 0;
        background: rgba(3, 7, 18, 0.94);
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        gap: 16px;
        z-index: 20;
    }

    .camera-spinner {
        width: 46px;
        height: 46px;
        border: 4px solid rgba(255, 255, 255, 0.15);
        border-top-color: var(--kiosk-primary-light);
        border-radius: 50%;
        animation: spin 0.9s linear infinite;
    }

    @keyframes spin { to { transform: rotate(360deg); } }

    .camera-error-view {
        position: absolute;
        inset: 0;
        background: rgba(3, 7, 18, 0.96);
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        padding: 24px;
        text-align: center;
        z-index: 25;
    }

    .camera-error-view .error-icon {
        font-size: 48px;
        color: #f87171;
        margin-bottom: 12px;
    }

    .camera-error-view h3 {
        margin: 0 0 8px 0;
        font-size: 19px;
        color: #f87171;
    }

    .camera-error-view p {
        margin: 0 0 18px 0;
        font-size: 13.5px;
        color: #cbd5e1;
        max-width: 380px;
        line-height: 1.5;
    }

    .camera-error-actions {
        display: flex;
        gap: 10px;
        flex-wrap: wrap;
        justify-content: center;
    }

    .btn-retry-camera, .btn-fallback-mesin {
        padding: 9px 18px;
        border-radius: 12px;
        font-size: 13.5px;
        font-weight: 700;
        cursor: pointer;
        display: inline-flex;
        align-items: center;
        gap: 8px;
        border: none;
    }

    .btn-retry-camera { background: var(--kiosk-primary); color: white; box-shadow: 0 4px 12px rgba(79, 70, 229, 0.35); }
    .btn-fallback-mesin { background: rgba(255, 255, 255, 0.12); color: white; border: 1px solid rgba(255,255,255,0.2); }

    .scan-success-overlay {
        position: absolute;
        inset: 0;
        background: rgba(15, 23, 42, 0.94);
        display: none;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        z-index: 30;
        animation: modalFadeIn 0.15s ease;
    }

    .scan-success-overlay .success-icon {
        width: 72px;
        height: 72px;
        border-radius: 50%;
        background: linear-gradient(135deg, #10b981, #059669);
        color: #ffffff;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 38px;
        margin-bottom: 14px;
        box-shadow: 0 0 32px rgba(16, 185, 129, 0.7);
    }

    .scan-tip {
        text-align: center;
        color: #94a3b8;
        font-size: 13px;
        margin: 12px 0 0 0;
    }

    /* MESIN SCANNER / RFID SECTION */
    .mesin-card-box {
        background: var(--kiosk-card-bg);
        border: 1px solid var(--kiosk-card-border);
        border-radius: 26px;
        padding: 48px 32px;
        text-align: center;
        box-shadow: 0 20px 40px rgba(0, 0, 0, 0.4);
    }

    .rfid-pulse-radar {
        position: relative;
        width: 110px;
        height: 110px;
        margin: 0 auto 24px auto;
        display: flex;
        align-items: center;
        justify-content: center;
    }

    .rfid-icon {
        width: 80px;
        height: 80px;
        border-radius: 22px;
        background: linear-gradient(135deg, var(--kiosk-primary), #6366f1);
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 38px;
        color: #ffffff;
        box-shadow: 0 10px 25px rgba(79, 70, 229, 0.45);
        z-index: 2;
    }

    .rfid-ring {
        position: absolute;
        inset: 0;
        border-radius: 50%;
        border: 2px solid rgba(99, 102, 241, 0.5);
        animation: rfidPing 2.4s cubic-bezier(0, 0, 0.2, 1) infinite;
    }

    .rfid-ring.ring-2 { animation-delay: 1.2s; }

    @keyframes rfidPing {
        0% { transform: scale(0.8); opacity: 0.9; }
        100% { transform: scale(1.65); opacity: 0; }
    }

    .mesin-card-box h2 {
        font-size: 30px;
        font-weight: 800;
        margin: 0 0 8px 0;
        color: #ffffff;
    }

    .mesin-desc {
        color: #94a3b8;
        font-size: 14.5px;
        margin: 0 auto 28px auto;
        max-width: 440px;
        line-height: 1.5;
    }

    .manual-input-box {
        max-width: 420px;
        margin: 0 auto;
        background: rgba(255, 255, 255, 0.05);
        border: 1px solid rgba(255, 255, 255, 0.12);
        border-radius: 18px;
        padding: 16px 20px;
    }

    .manual-label {
        display: block;
        font-size: 12px;
        font-weight: 700;
        color: #cbd5e1;
        margin-bottom: 8px;
        text-align: left;
    }

    .manual-input-group {
        display: flex;
        gap: 8px;
    }

    .manual-input-group input {
        flex: 1;
        background: rgba(0, 0, 0, 0.35);
        border: 1px solid rgba(255, 255, 255, 0.15);
        border-radius: 10px;
        padding: 10px 14px;
        color: #ffffff;
        font-size: 14px;
        outline: none;
    }

    .manual-input-group input:focus {
        border-color: var(--kiosk-primary-light);
        box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.25);
    }

    .btn-manual-submit {
        background: linear-gradient(135deg, var(--kiosk-primary), #6366f1);
        color: white;
        border: none;
        padding: 10px 18px;
        border-radius: 10px;
        font-weight: 700;
        font-size: 13.5px;
        cursor: pointer;
        display: inline-flex;
        align-items: center;
        gap: 6px;
        box-shadow: 0 4px 14px rgba(79, 70, 229, 0.35);
        transition: all 0.2s ease;
    }
    .btn-manual-submit:hover {
        transform: translateY(-1px);
        box-shadow: 0 6px 18px rgba(79, 70, 229, 0.5);
    }

    /* KOLOM KANAN: LIVE INFO & ISLAMIC BRANDING HUB */
    .kiosk-info-col {
        display: flex;
        flex-direction: column;
        gap: 16px;
    }

    .kiosk-widget-card {
        background: var(--kiosk-card-bg);
        border: 1px solid var(--kiosk-card-border);
        border-radius: 24px;
        box-shadow: 0 14px 32px rgba(0, 0, 0, 0.35);
        backdrop-filter: blur(12px);
    }

    /* WIDGET JAM DIGITAL & KALENDER GANDA */
    .kiosk-widget-card.clock-card {
        background: linear-gradient(145deg, rgba(79, 70, 229, 0.16), rgba(15, 23, 42, 0.8));
        border: 1px solid rgba(99, 102, 241, 0.28);
        padding: 24px 26px;
        text-align: center;
    }

    .clock-display {
        font-family: 'JetBrains Mono', monospace;
        font-size: 56px;
        font-weight: 800;
        color: var(--kiosk-cyan);
        letter-spacing: 2px;
        line-height: 1.1;
        text-shadow: 0 0 25px rgba(56, 189, 248, 0.5);
        margin-bottom: 12px;
    }

    .clock-seconds {
        color: #a5f3fc;
        font-size: 40px;
        opacity: 0.9;
    }

    .clock-meta-row {
        display: flex;
        justify-content: center;
        gap: 12px;
        flex-wrap: wrap;
        margin-bottom: 16px;
    }

    .date-masehi, .date-hijri {
        font-size: 13px;
        color: #e2e8f0;
        display: inline-flex;
        align-items: center;
        gap: 7px;
        background: rgba(255, 255, 255, 0.08);
        padding: 6px 14px;
        border-radius: 20px;
        border: 1px solid rgba(255, 255, 255, 0.1);
        font-weight: 600;
    }

    .date-hijri {
        color: var(--kiosk-amber-light);
        border-color: rgba(251, 191, 36, 0.25);
    }

    .kiosk-session-pill {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        font-size: 13px;
        font-weight: 800;
        padding: 7px 20px;
        border-radius: 30px;
        letter-spacing: 0.3px;
    }

    .kiosk-session-pill.ontime {
        background: rgba(16, 185, 129, 0.18);
        color: #34d399;
        border: 1px solid rgba(52, 211, 153, 0.4);
    }

    .kiosk-session-pill.late {
        background: rgba(245, 158, 11, 0.18);
        color: #fbbf24;
        border: 1px solid rgba(251, 191, 36, 0.4);
    }

    .kiosk-session-pill.pulang {
        background: rgba(56, 189, 248, 0.18);
        color: #38bdf8;
        border: 1px solid rgba(56, 189, 248, 0.4);
    }

    .kiosk-session-pill.standby {
        background: rgba(148, 163, 184, 0.18);
        color: #cbd5e1;
        border: 1px solid rgba(203, 213, 225, 0.3);
    }

    .pulse-indicator {
        width: 8px;
        height: 8px;
        border-radius: 50%;
        background: currentColor;
        box-shadow: 0 0 10px currentColor;
        animation: pulseLive 2s infinite;
    }

    @keyframes pulseLive {
        0% { transform: scale(0.9); opacity: 1; }
        50% { transform: scale(1.3); opacity: 0.5; }
        100% { transform: scale(0.9); opacity: 1; }
    }

    /* WIDGET STATISTIK KEHADIRAN HARI INI */
    .kiosk-widget-card.stats-card {
        padding: 18px 22px;
    }

    .stats-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 14px;
    }

    .stats-title {
        font-size: 12.5px;
        font-weight: 800;
        color: #94a3b8;
        text-transform: uppercase;
        letter-spacing: 1.5px;
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .stats-badge-live {
        background: rgba(239, 68, 68, 0.2);
        color: #f87171;
        border: 1px solid rgba(248, 113, 113, 0.4);
        font-size: 10px;
        font-weight: 800;
        padding: 2px 8px;
        border-radius: 12px;
        text-transform: uppercase;
        letter-spacing: 1px;
    }

    .stats-metric-grid {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 12px;
    }

    .stat-item {
        background: rgba(255, 255, 255, 0.03);
        border: 1px solid rgba(255, 255, 255, 0.07);
        border-radius: 16px;
        padding: 12px 14px;
        display: flex;
        align-items: center;
        gap: 10px;
    }

    .stat-icon-bubble {
        width: 36px;
        height: 36px;
        border-radius: 10px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 15px;
        flex-shrink: 0;
    }

    .stat-icon-bubble.student { background: rgba(99, 102, 241, 0.2); color: #a5b4fc; }
    .stat-icon-bubble.staff { background: rgba(56, 189, 248, 0.2); color: #38bdf8; }
    .stat-icon-bubble.ontime { background: rgba(16, 185, 129, 0.2); color: #34d399; }

    .stat-meta { display: flex; flex-direction: column; min-width: 0; }
    .stat-number { font-size: 20px; font-weight: 800; color: #f8fafc; line-height: 1.1; }
    .stat-label { font-size: 11px; color: #94a3b8; font-weight: 600; margin-top: 2px; white-space: nowrap; }

    /* WIDGET AKTIVITAS SCAN TERAKHIR */
    .kiosk-widget-card.recent-card {
        padding: 18px 22px;
    }

    .recent-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 12px;
    }

    .recent-title {
        font-size: 12.5px;
        font-weight: 800;
        color: #94a3b8;
        text-transform: uppercase;
        letter-spacing: 1.5px;
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .recent-indicator {
        font-size: 11.5px;
        color: #34d399;
        display: flex;
        align-items: center;
        gap: 6px;
        font-weight: 700;
    }

    .recent-list {
        display: flex;
        flex-direction: column;
        gap: 8px;
    }

    .recent-item {
        display: flex;
        align-items: center;
        gap: 12px;
        padding: 9px 12px;
        border-radius: 12px;
        background: rgba(255, 255, 255, 0.03);
        border: 1px solid rgba(255, 255, 255, 0.06);
        transition: all 0.25s ease;
    }

    .recent-item.just-scanned {
        animation: slideDownIn 0.35s cubic-bezier(0.16, 1, 0.3, 1), highlightScan 2.5s ease;
    }

    @keyframes slideDownIn {
        from { opacity: 0; transform: translateY(-12px); }
        to { opacity: 1; transform: translateY(0); }
    }

    @keyframes highlightScan {
        0% { background: rgba(79, 70, 229, 0.25); border-color: rgba(99, 102, 241, 0.6); }
        100% { background: rgba(255, 255, 255, 0.03); border-color: rgba(255, 255, 255, 0.06); }
    }

    .recent-avatar {
        width: 38px;
        height: 38px;
        border-radius: 10px;
        overflow: hidden;
        flex-shrink: 0;
        background: rgba(255, 255, 255, 0.08);
        display: flex;
        align-items: center;
        justify-content: center;
    }

    .recent-avatar img {
        width: 100%;
        height: 100%;
        object-fit: cover;
    }

    .avatar-initial {
        font-size: 15px;
        font-weight: 800;
        color: #38bdf8;
    }

    .recent-info {
        flex: 1;
        min-width: 0;
    }

    .recent-name {
        display: block;
        font-size: 13.5px;
        font-weight: 700;
        color: #f8fafc;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .recent-role {
        display: block;
        font-size: 11px;
        color: #94a3b8;
    }

    .recent-badge-col {
        text-align: right;
        display: flex;
        flex-direction: column;
        align-items: flex-end;
        gap: 2px;
    }

    .recent-time {
        font-family: 'JetBrains Mono', monospace;
        font-size: 11.5px;
        color: #cbd5e1;
        font-weight: 600;
    }

    .recent-status {
        font-size: 10px;
        font-weight: 700;
        padding: 2px 8px;
        border-radius: 10px;
        letter-spacing: 0.3px;
    }

    .recent-status.ontime {
        background: rgba(16, 185, 129, 0.2);
        color: #34d399;
    }

    .recent-status.late {
        background: rgba(245, 158, 11, 0.2);
        color: #fbbf24;
    }

    .recent-empty {
        text-align: center;
        padding: 22px 14px;
        color: #64748b;
    }

    .recent-empty i {
        font-size: 28px;
        margin-bottom: 6px;
        opacity: 0.6;
    }

    .recent-empty p {
        font-size: 12px;
        margin: 0;
        line-height: 1.5;
    }

    /* WIDGET MUTIARA ISLAMI TICKER */
    .kiosk-widget-card.quote-card {
        background: linear-gradient(135deg, rgba(15, 23, 42, 0.8), rgba(30, 27, 75, 0.45));
        border: 1px solid rgba(99, 102, 241, 0.2);
        border-radius: 18px;
        padding: 14px 20px;
        text-align: center;
    }

    .quote-arabic {
        font-size: 15px;
        font-weight: 700;
        color: #fde047;
        margin-bottom: 4px;
        direction: rtl;
        transition: opacity 0.35s ease;
    }

    .quote-meaning {
        font-size: 11.5px;
        color: #94a3b8;
        font-style: italic;
        line-height: 1.4;
        transition: opacity 0.35s ease;
    }

    /* 3. MODAL HASIL PRESENSI REAL-TIME */
    .live-result-modal {
        position: fixed;
        inset: 0;
        z-index: 9999;
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 24px;
    }

    .live-result-backdrop {
        position: absolute;
        inset: 0;
        background: rgba(2, 6, 23, 0.88);
        backdrop-filter: blur(12px);
        animation: modalFadeIn 0.2s ease;
    }

    .live-result-container {
        position: relative;
        z-index: 10;
        width: min(1380px, 95vw);
        max-height: 92vh;
        overflow-y: auto;
        background: radial-gradient(circle at 35% 0%, #1e1b4b, #0f172a 75%, #020617 100%);
        border: 1px solid rgba(99, 102, 241, 0.35);
        border-radius: 28px;
        padding: 36px 44px;
        box-shadow: 0 30px 60px -12px rgba(0, 0, 0, 0.95);
        animation: modalScaleUp 0.25s cubic-bezier(0.16, 1, 0.3, 1);
        color: white;
    }

    @keyframes modalFadeIn { from { opacity: 0; } to { opacity: 1; } }
    @keyframes modalScaleUp {
        from { opacity: 0; transform: scale(0.92) translateY(12px); }
        to { opacity: 1; transform: scale(1) translateY(0); }
    }

    .live-result-progress-wrap {
        height: 6px;
        background: rgba(255, 255, 255, 0.12);
        border-radius: 6px;
        overflow: hidden;
        margin-top: 24px;
    }

    .live-result-progress-bar {
        height: 100%;
        background: linear-gradient(90deg, var(--kiosk-primary), #38bdf8);
        width: 100%;
        transform-origin: left;
    }

    .btn-dismiss-result {
        background: rgba(255, 255, 255, 0.12);
        color: #e2e8f0;
        border: 1px solid rgba(255, 255, 255, 0.2);
        padding: 10px 24px;
        border-radius: 12px;
        font-size: 14px;
        font-weight: 700;
        cursor: pointer;
        transition: all 0.2s ease;
    }

    .btn-dismiss-result:hover {
        background: rgba(255, 255, 255, 0.22);
        color: #ffffff;
    }

    /* KARTU HASIL PRESENSI */
    .attendance-result-card {
        display: grid;
        grid-template-columns: 350px 1fr;
        gap: 44px;
        align-items: center;
        width: 100%;
    }

    .attendance-result-static {
        background: rgba(255, 255, 255, 0.06);
        border: 1px solid rgba(255, 255, 255, 0.12);
        border-radius: 28px;
        padding: 40px 48px;
        backdrop-filter: blur(20px);
        box-shadow: 0 20px 50px rgba(0, 0, 0, 0.4);
        margin-bottom: 24px;
    }

    .attendance-photo-col {
        display: flex;
        justify-content: center;
        align-items: center;
        width: 100%;
    }

    .student-photo-large {
        width: 340px;
        height: 340px;
        object-fit: cover;
        border-radius: 24px;
        display: block;
        margin: 0 auto;
        border: 4px solid rgba(99, 102, 241, 0.4);
        box-shadow: 0 16px 36px rgba(0, 0, 0, 0.5);
        background: rgba(255, 255, 255, 0.05);
    }

    .photo-placeholder-large {
        width: 340px;
        height: 340px;
        margin: 0 auto;
        border-radius: 24px;
        display: flex;
        align-items: center;
        justify-content: center;
        background: linear-gradient(135deg, #312e81, #1e1b4b);
        border: 4px solid rgba(99, 102, 241, 0.4);
        box-shadow: 0 16px 36px rgba(0, 0, 0, 0.5);
        font-size: 110px;
        font-weight: 800;
        color: #c7d2fe;
        text-shadow: 0 4px 16px rgba(0, 0, 0, 0.4);
    }

    .attendance-info-col {
        display: flex;
        flex-direction: column;
        justify-content: center;
        text-align: left;
        gap: 12px;
        width: 100%;
    }

    .role-badge-large {
        display: inline-block;
        background: rgba(99, 102, 241, 0.22);
        border: 1px solid rgba(129, 140, 248, 0.4);
        color: #c7d2fe;
        font-size: 13.5px;
        font-weight: 800;
        letter-spacing: 2px;
        padding: 6px 16px;
        border-radius: 20px;
        text-transform: uppercase;
        width: fit-content;
    }

    .attendance-name-large {
        font-size: 40px;
        font-weight: 800;
        line-height: 1.2;
        color: #ffffff;
        margin: 0;
        word-break: break-word;
        text-shadow: 0 2px 10px rgba(0, 0, 0, 0.4);
    }

    .attendance-meta-large {
        font-size: 20px;
        color: #cbd5e1;
        line-height: 1.5;
        margin: 0;
    }

    .attendance-meta-large strong { color: #f8fafc; font-weight: 700; }

    .attendance-time-status-row {
        display: flex;
        align-items: center;
        gap: 20px;
        flex-wrap: wrap;
        margin: 4px 0;
    }

    .attendance-time-large {
        font-size: 44px;
        font-weight: 800;
        font-family: 'JetBrains Mono', monospace;
        color: var(--kiosk-cyan);
        letter-spacing: 1.5px;
        text-shadow: 0 0 20px rgba(56, 189, 248, 0.35);
    }

    .attendance-status-large {
        display: inline-flex;
        align-items: center;
        padding: 10px 24px;
        border-radius: 30px;
        font-size: 17px;
        font-weight: 800;
        letter-spacing: 0.5px;
    }

    .greeting-card-large {
        background: rgba(255, 255, 255, 0.06);
        border: 1px solid rgba(255, 255, 255, 0.12);
        border-radius: 16px;
        padding: 16px 22px;
        margin-top: 4px;
        text-align: left;
    }

    .greeting-card-large h3, .greeting-card-large h4 {
        margin: 0 0 5px 0;
        color: #f8fafc;
        font-size: 18px;
        font-weight: 700;
    }

    .greeting-card-large p {
        margin: 0;
        color: #94a3b8;
        font-size: 14.5px;
        line-height: 1.4;
    }

    .warning-banner-large {
        background: rgba(239, 68, 68, 0.18);
        color: #fca5a5;
        padding: 12px 18px;
        border-radius: 12px;
        margin-bottom: 8px;
        font-weight: 700;
        font-size: 14.5px;
        text-align: left;
        border: 1px solid rgba(239, 68, 68, 0.35);
    }

    .lembur-banner-large {
        margin-top: 8px;
        padding: 10px 16px;
        background: rgba(56, 189, 248, 0.18);
        color: #7dd3fc;
        border-radius: 12px;
        font-weight: 700;
        font-size: 14px;
        text-align: left;
        border: 1px solid rgba(56, 189, 248, 0.35);
    }

    /* RESPONSIVE LAYOUT UNTUK TABLET & SMARTPHONE */
    @media (max-width: 1080px) {
        .kiosk-main-grid {
            grid-template-columns: 1fr;
        }

        .attendance-result-card {
            grid-template-columns: 1fr;
            gap: 24px;
            text-align: center;
        }

        .attendance-info-col {
            text-align: center;
            align-items: center;
        }

        .role-badge-large { margin: 0 auto; }
        .attendance-time-status-row { justify-content: center; }
        .greeting-card-large { text-align: center; }

        .student-photo-large, .photo-placeholder-large {
            width: 220px;
            height: 220px;
        }

        .attendance-name-large { font-size: 30px; }
        .clock-display { font-size: 46px; }
    }

    @media (max-width: 640px) {
        .kiosk-wrapper { padding: 14px 16px; }
        .kiosk-topbar { flex-direction: column; gap: 14px; align-items: flex-start; }
        .kiosk-actions { width: 100%; justify-content: space-between; }
        .stats-metric-grid { grid-template-columns: 1fr; }
        .student-photo-large, .photo-placeholder-large { width: 170px; height: 170px; }
        .clock-display { font-size: 38px; }
    }
</style>
</head>
<body class="scanner-page">
<div class="kiosk-wrapper">

    <!-- 1. TOPBAR & SCHOOL BRANDING -->
    <header class="kiosk-topbar">
        <div class="kiosk-brand">
            <div class="kiosk-logo-box">
                <img src="<?= BASE_URL ?>/logo.jpg" alt="Logo An Nahl" class="kiosk-logo-img">
            </div>
            <div class="kiosk-brand-text">
                <span class="kiosk-brand-subtitle">GERBANG UTAMA &bull; SMART PRESENSI</span>
                <h1 class="kiosk-brand-title">AN NAHL ISLAMIC SCHOOL</h1>
            </div>
        </div>

        <div class="kiosk-actions">
            <div class="kiosk-network-pill online" id="networkStatus" title="Status Jaringan">
                <span class="status-dot"></span>
                <span id="networkText">Online</span>
            </div>

            <button type="button" class="kiosk-btn-tool" id="btnAudioToggle" title="Nyalakan/Matikan Suara" aria-label="Toggle Sound">
                <i class="fa-solid fa-volume-high" id="audioIcon"></i>
            </button>

            <button type="button" class="kiosk-btn-tool" id="btnFullscreen" title="Layar Penuh (Kiosk Mode)" aria-label="Toggle Fullscreen">
                <i class="fa-solid fa-expand" id="fullscreenIcon"></i>
            </button>

            <a href="<?= BASE_URL ?>/dashboard.php" class="kiosk-btn-back" title="Kembali ke Dashboard">
                <i class="fa-solid fa-arrow-left"></i>
                <span>Kembali</span>
            </a>
        </div>
    </header>

    <?php if ($errorText): ?>
        <div class="scanner-alert-danger" style="margin-bottom: 20px; background: rgba(239, 68, 68, 0.2); border: 1px solid #ef4444; padding: 14px 20px; border-radius: 14px; color: #fca5a5; font-weight: 700;">
            ⛔ <?= e($errorText) ?>
        </div>
    <?php endif; ?>

    <?php if ($result): ?>
        <!-- HASIL SCAN STATIS (FALLBACK DIRECT POST) -->
        <?php if (isset($result['already']) && $result['already'] === true): ?>
        <div class="warning-banner-large" style="text-align: center; margin-bottom: 20px; font-size: 15px;">
            ⛔ <?= e($result['message'] ?? 'Peringatan Sistem') ?>
        </div>
        <?php endif; ?>

        <div class="attendance-result-card attendance-result-static">
            <div class="attendance-photo-col">
                <?php if (!empty($result['photo'])): ?>
                    <?php $folder = ($result['type'] ?? '') === 'staff' ? 'staff' : 'students'; ?>
                    <img src="../uploads/<?= $folder ?>/<?= e($result['photo']) ?>" class="student-photo-large" alt="Foto">
                <?php else: ?>
                    <div class="photo-placeholder-large">
                        <?= strtoupper(substr($result['name'] ?? 'U', 0, 1)) ?>
                    </div>
                <?php endif; ?>
            </div>

            <div class="attendance-info-col">
                <span class="role-badge-large">
                    <?= ($result['type'] ?? '') === 'student' ? 'SISWA' : 'STAFF / GURU' ?>
                </span>
                <h2 class="attendance-name-large"><?= e($result['name'] ?? 'Sistem') ?></h2>

                <div class="attendance-meta-large">
                    <?php if (($result['type'] ?? '') === 'student'): ?>
                        NIS: <strong><?= e($result['nis'] ?? '-') ?></strong> &bull; Kelas: <strong><?= e($result['class_name'] ?? '-') ?></strong>
                    <?php else: ?>
                        NIK: <strong><?= e($result['nik'] ?? '-') ?></strong>
                    <?php endif; ?>
                </div>

                <div class="attendance-time-status-row">
                    <div class="attendance-time-large"><?= e($result['time'] ?? date('H:i:s')) ?></div>

                    <div class="attendance-status attendance-status-large <?= e($result['badge_class'] ?? 'late') ?>">
                        <?= e($result['badge_text'] ?? '⚠ TERLAMBAT') ?>
                    </div>
                </div>

                <div class="greeting-card-large">
                    <?php if (($result['type'] ?? '') === 'student'): ?>
                        <?php if (isset($result['scan_type']) && $result['scan_type'] === 'pulang'): ?>
                            <h3>Fi Amanillah ✨</h3>
                            <p>Semoga pembelajaran hari ini diberkahi Allah dan bermanfaat.</p>
                        <?php else: ?>
                            <h3>Selamat bersekolah dengan bahagia 🌿</h3>
                            <p>Jangan lupa baca doa sebelum masuk kelas.</p>
                        <?php endif; ?>
                    <?php else: ?>
                        <?php if (isset($result['scan_type']) && $result['scan_type'] === 'pulang'): ?>
                            <h3>Fi Amanillah ✨</h3>
                            <p>Terima kasih atas dedikasinya, semoga selamat sampai tujuan.</p>
                        <?php else: ?>
                            <h3>Selamat Bekerja 🌿</h3>
                            <p>Semoga pekerjaan hari ini berkah dan dilancarkan.</p>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>

                <?php if (isset($result['is_lembur']) && $result['is_lembur'] === true && (!isset($result['already']) || $result['already'] !== true)): ?>
                    <div class="lembur-banner-large">
                        ⏱️ <?= e($result['lembur_msg'] ?? 'Sesi lembur tercatat.') ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <script>
        setTimeout(function () { window.location.href = 'scanner.php'; }, 5000);
        </script>

    <?php else: ?>

        <!-- 2. DUAL-COLUMN KIOSK MAIN GRID -->
        <div class="kiosk-main-grid">

            <!-- KOLOM KIRI: SCANNER STAGE -->
            <div class="kiosk-scanner-col">
                <!-- PILIHAN METODE SCANNER -->
                <div class="scanner-mode-switch">
                    <button type="button" class="mode-btn" data-mode="hp" id="modeHpBtn" title="Kamera Belakang Smartphone">
                        <i class="fa-solid fa-mobile-screen-button"></i>
                        <span>Kamera HP</span>
                    </button>
                    <button type="button" class="mode-btn" data-mode="laptop" id="modeLaptopBtn" title="Webcam / Kamera Depan Laptop">
                        <i class="fa-solid fa-laptop"></i>
                        <span>Kamera Depan</span>
                    </button>
                    <button type="button" class="mode-btn" data-mode="mesin" id="modeMesinBtn" title="Barcode Scanner Hardware / RFID Reader">
                        <i class="fa-solid fa-barcode"></i>
                        <span>Mesin / RFID</span>
                    </button>
                </div>

                <!-- SECTION SCANNER KAMERA (HP / LAPTOP) -->
                <div id="cameraSection" class="camera-section">
                    <div class="camera-card">
                        <div class="viewfinder-wrapper" id="viewfinderWrapper">
                            <div id="reader"></div>

                            <!-- Laser & Reticle Target Box -->
                            <div class="scan-reticle" id="scanReticle">
                                <div class="reticle-corner top-left"></div>
                                <div class="reticle-corner top-right"></div>
                                <div class="reticle-corner bottom-left"></div>
                                <div class="reticle-corner bottom-right"></div>
                                <div class="scan-laser"></div>
                            </div>

                            <!-- Loading State -->
                            <div class="camera-loading" id="cameraLoading">
                                <div class="camera-spinner"></div>
                                <p id="cameraLoadingText" style="color: #cbd5e1; font-weight: 600; font-size: 14px;">Mengaktifkan sensor kamera...</p>
                            </div>

                            <!-- In-place Camera Error / Permission Request -->
                            <div class="camera-error-view" id="cameraErrorView" style="display: none;">
                                <div class="error-icon"><i class="fa-solid fa-camera-slash"></i></div>
                                <h3 id="cameraErrorTitle">Akses Kamera Diperlukan</h3>
                                <p id="cameraErrorDesc">Mohon izinkan akses kamera di browser Anda untuk menggunakan fitur pemindai ini.</p>
                                <div id="cameraErrorDebug" style="display: none; margin-bottom: 14px; font-size: 11px; color: #fca5a5; background: rgba(0,0,0,0.35); padding: 6px 10px; border-radius: 6px; word-break: break-all; max-width: 320px;"></div>
                                
                                <div class="ios-safari-tip" id="iosSafariTip" style="display: none; margin-bottom: 15px; padding: 10px 14px; background: rgba(255,255,255,0.08); border-radius: 10px; font-size: 11.5px; color: #cbd5e1; text-align: left; line-height: 1.5; max-width: 320px;">
                                    <strong>💡 Panduan iPhone (Safari):</strong><br>
                                    1. Ketuk tombol <b>aA</b> di sebelah kiri kolom URL.<br>
                                    2. Pilih <b>Pengaturan Situs Web (Website Settings)</b>.<br>
                                    3. Pada bagian <b>Kamera</b>, pilih <b>Izinkan (Allow)</b>.<br>
                                    4. Tekan tombol <b>"Coba Lagi / Izinkan"</b> di bawah.
                                </div>

                                <div class="camera-error-actions">
                                    <button type="button" class="btn-retry-camera" id="btnRetryCamera"><i class="fa-solid fa-rotate-right"></i> Coba Lagi</button>
                                    <button type="button" class="btn-fallback-mesin" id="btnFallbackMesin"><i class="fa-solid fa-barcode"></i> Gunakan Mesin Scanner</button>
                                </div>
                            </div>

                            <!-- Success Flash Overlay -->
                            <div class="scan-success-overlay" id="scanSuccessOverlay">
                                <div class="success-icon"><i class="fa-solid fa-check"></i></div>
                                <div class="success-code" id="successCodeText" style="font-size: 16px; font-weight: 700; letter-spacing: 1px; margin-bottom: 6px;"></div>
                                <p style="margin: 0; color: #a7f3d0; font-size: 13.5px;">Berhasil dipindai, memproses data...</p>
                            </div>
                        </div>

                        <div class="camera-controls" style="text-align: center; margin-top: 14px;">
                            <div class="camera-select-wrap" id="cameraSelectWrap" style="display: none; align-items: center; justify-content: center; gap: 8px; margin-bottom: 8px;">
                                <label for="cameraSelect" style="font-size: 13px; color: #cbd5e1;"><i class="fa-solid fa-video"></i> Lensa:</label>
                                <select id="cameraSelect" class="camera-select" style="background: rgba(255,255,255,0.1); border: 1px solid rgba(255,255,255,0.2); color: white; padding: 6px 12px; border-radius: 8px; font-size: 12.5px; outline: none;"></select>
                            </div>
                            <div>
                                <button type="button" id="btnFlipCamera" class="btn-flip-camera" style="display: none; background: rgba(255,255,255,0.08); border: 1px solid rgba(255,255,255,0.15); color: #cbd5e1; padding: 7px 16px; border-radius: 10px; font-size: 13px; font-weight: 600; cursor: pointer;">
                                    <i class="fa-solid fa-camera-rotate"></i> Balik Lensa Kamera
                                </button>
                            </div>
                            <p class="scan-tip" id="scanTip">Arahkan barcode atau QR kartu santri/staff ke dalam kotak kamera</p>
                        </div>
                    </div>
                </div>

                <!-- SECTION MESIN SCANNER (HARDWARE / RFID / INPUT MANUAL) -->
                <div id="mesinSection" class="mesin-section" style="display: none;">
                    <div class="mesin-card-box">
                        <div class="rfid-pulse-radar">
                            <div class="rfid-icon"><i class="fa-solid fa-id-card"></i></div>
                            <div class="rfid-ring ring-1"></div>
                            <div class="rfid-ring ring-2"></div>
                        </div>
                        <h2>Mesin Scanner & RFID Siap</h2>
                        <p class="mesin-desc">Tempelkan kartu RFID atau arahkan scanner barcode hardware gerbang</p>
                        
                        <div class="manual-input-box">
                            <span class="manual-label">Atau input manual NIS / NIK:</span>
                            <div class="manual-input-group">
                                <input type="text" id="manualCodeInput" placeholder="Ketik NIS atau NIK..." autocomplete="off">
                                <button type="button" id="manualSubmitBtn" class="btn-manual-submit">
                                    <i class="fa-solid fa-arrow-right-to-bracket"></i> Absen
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- KOLOM KANAN: LIVE INFO & ISLAMIC BRANDING HUB -->
            <div class="kiosk-info-col">

                <!-- 1. JAM DIGITAL REALTIME & KALENDER GANDA -->
                <div class="kiosk-widget-card clock-card">
                    <div class="clock-display" id="kioskClock">00:00:00</div>
                    <div class="clock-meta-row">
                        <div class="date-masehi" id="clockMasehi">
                            <i class="fa-regular fa-calendar-days"></i> <span>Memuat tanggal...</span>
                        </div>
                        <div class="date-hijri" id="clockHijri">
                            <i class="fa-solid fa-moon"></i> <span>Memuat Hijriyah...</span>
                        </div>
                    </div>
                    <div class="kiosk-session-pill ontime" id="kioskSessionPill">
                        <span class="pulse-indicator"></span>
                        <span id="sessionText">Sesi Masuk: Tepat Waktu</span>
                    </div>
                </div>

                <!-- 2. STATISTIK KEHADIRAN HARI INI -->
                <div class="kiosk-widget-card stats-card">
                    <div class="stats-header">
                        <span class="stats-title"><i class="fa-solid fa-chart-pie"></i> Kehadiran Hari Ini</span>
                        <span class="stats-badge-live">Live</span>
                    </div>
                    <div class="stats-metric-grid">
                        <div class="stat-item">
                            <div class="stat-icon-bubble student"><i class="fa-solid fa-user-graduate"></i></div>
                            <div class="stat-meta">
                                <span class="stat-number" id="statStudentCount"><?= $todayStudents ?></span>
                                <span class="stat-label">Siswa Hadir</span>
                            </div>
                        </div>
                        <div class="stat-item">
                            <div class="stat-icon-bubble staff"><i class="fa-solid fa-chalkboard-user"></i></div>
                            <div class="stat-meta">
                                <span class="stat-number" id="statStaffCount"><?= $todayStaff ?></span>
                                <span class="stat-label">Guru / Staff</span>
                            </div>
                        </div>
                        <div class="stat-item">
                            <div class="stat-icon-bubble ontime"><i class="fa-solid fa-circle-check"></i></div>
                            <div class="stat-meta">
                                <span class="stat-number" id="statOntimePercent"><?= $ontimePercent ?>%</span>
                                <span class="stat-label">Tepat Waktu</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- 3. AKTIVITAS PRESENSI TERBARU (LIVE STREAM) -->
                <div class="kiosk-widget-card recent-card">
                    <div class="recent-header">
                        <span class="recent-title"><i class="fa-solid fa-clock-rotate-left"></i> Presensi Terkini</span>
                        <span class="recent-indicator"><span class="pulse-indicator"></span> Aktif</span>
                    </div>
                    <div class="recent-list" id="recentList">
                        <?php if (empty($recentScans)): ?>
                            <div class="recent-empty" id="recentEmpty">
                                <i class="fa-regular fa-id-badge"></i>
                                <p>Belum ada presensi yang tercatat hari ini.<br>Silakan lakukan scan kartu pertama.</p>
                            </div>
                        <?php else: ?>
                            <?php foreach ($recentScans as $item): ?>
                                <div class="recent-item">
                                    <div class="recent-avatar">
                                        <?php if (!empty($item['photo'])): ?>
                                            <?php $folder = ($item['type'] === 'staff') ? 'staff' : 'students'; ?>
                                            <img src="../uploads/<?= $folder ?>/<?= e($item['photo']) ?>" alt="Foto">
                                        <?php else: ?>
                                            <div class="avatar-initial"><?= strtoupper(substr($item['name'], 0, 1)) ?></div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="recent-info">
                                        <span class="recent-name"><?= e($item['name']) ?></span>
                                        <span class="recent-role"><?= e($item['sub_info']) ?></span>
                                    </div>
                                    <div class="recent-badge-col">
                                        <span class="recent-time"><?= substr($item['scan_time'] ?? '', 0, 5) ?></span>
                                        <span class="recent-status <?= ($item['status'] === 'tepat_waktu') ? 'ontime' : 'late' ?>">
                                            <?= ($item['status'] === 'tepat_waktu') ? 'Tepat Waktu' : 'Terlambat' ?>
                                        </span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- 4. MUTIARA ISLAMI / QUOTE TICKER -->
                <div class="kiosk-widget-card quote-card">
                    <div class="quote-arabic" id="quoteArabic">طَلَبُ الْعِلْمِ فَرِيْضَةٌ عَلَى كُلِّ مُسْلِمٍ</div>
                    <div class="quote-meaning" id="quoteMeaning">"Menuntut ilmu adalah kewajiban bagi setiap muslim." (HR. Ibnu Majah)</div>
                </div>

            </div>

        </div>

        <!-- Form Tersembunyi untuk Fallback Submit -->
        <form action="scanner_process.php" method="POST" id="scannerForm" style="display: none;">
            <input type="text" name="code" id="barcodeInput" autocomplete="off">
        </form>

        <!-- Modal Real-time Hasil Scan Tanpa Reload -->
        <div id="liveResultModal" class="live-result-modal" style="display: none;">
            <div class="live-result-backdrop" id="liveResultBackdrop"></div>
            <div class="live-result-container">
                <div id="liveResultContent"></div>
                <div class="live-result-progress-wrap">
                    <div class="live-result-progress-bar" id="liveProgressBar"></div>
                </div>
                <div style="text-align: center; margin-top: 18px;">
                    <button type="button" id="btnDismissResult" class="btn-dismiss-result">
                        Lanjut Scan Berikutnya (Spasi / Klik) ➔
                    </button>
                </div>
            </div>
        </div>

        <script>
        (function () {
            'use strict';

            // Elemen Form & Mode
            const scannerForm = document.getElementById('scannerForm');
            const barcodeInput = document.getElementById('barcodeInput');
            const manualCodeInput = document.getElementById('manualCodeInput');
            const manualSubmitBtn = document.getElementById('manualSubmitBtn');

            const modeHpBtn = document.getElementById('modeHpBtn');
            const modeLaptopBtn = document.getElementById('modeLaptopBtn');
            const modeMesinBtn = document.getElementById('modeMesinBtn');
            const modeButtons = [modeHpBtn, modeLaptopBtn, modeMesinBtn];

            const cameraSection = document.getElementById('cameraSection');
            const mesinSection = document.getElementById('mesinSection');
            const cameraLoading = document.getElementById('cameraLoading');
            const cameraLoadingText = document.getElementById('cameraLoadingText');
            const cameraErrorView = document.getElementById('cameraErrorView');
            const cameraErrorTitle = document.getElementById('cameraErrorTitle');
            const cameraErrorDesc = document.getElementById('cameraErrorDesc');
            const btnRetryCamera = document.getElementById('btnRetryCamera');
            const btnFallbackMesin = document.getElementById('btnFallbackMesin');
            const btnFlipCamera = document.getElementById('btnFlipCamera');
            const scanSuccessOverlay = document.getElementById('scanSuccessOverlay');
            const successCodeText = document.getElementById('successCodeText');
            const readerElement = document.getElementById('reader');
            const cameraSelectWrap = document.getElementById('cameraSelectWrap');
            const cameraSelect = document.getElementById('cameraSelect');
            const scanTip = document.getElementById('scanTip');

            // Topbar Utilities
            const btnAudioToggle = document.getElementById('btnAudioToggle');
            const audioIcon = document.getElementById('audioIcon');
            const btnFullscreen = document.getElementById('btnFullscreen');
            const fullscreenIcon = document.getElementById('fullscreenIcon');
            const networkStatus = document.getElementById('networkStatus');
            const networkText = document.getElementById('networkText');

            // Statistik & Stream DOM
            const statStudentCount = document.getElementById('statStudentCount');
            const statStaffCount = document.getElementById('statStaffCount');
            const statOntimePercent = document.getElementById('statOntimePercent');
            const recentList = document.getElementById('recentList');
            const recentEmpty = document.getElementById('recentEmpty');

            // Modal Elements
            const liveResultModal = document.getElementById('liveResultModal');
            const liveResultBackdrop = document.getElementById('liveResultBackdrop');
            const liveResultContent = document.getElementById('liveResultContent');
            const liveProgressBar = document.getElementById('liveProgressBar');
            const btnDismissResult = document.getElementById('btnDismissResult');

            let html5QrCode = null;
            let isScanningActive = false;
            let isProcessing = false;
            let isCameraStarting = false;
            let isSwitchingMode = false;
            let currentMode = '';
            let availableCameras = [];
            let dismissTimer = null;
            let progressAnim = null;
            let isAudioMuted = (localStorage.getItem('ais_scanner_muted') === 'true');

            // Counter live di memori
            let currentStudentTotal = parseInt(statStudentCount ? statStudentCount.innerText : '0') || 0;
            let currentStaffTotal = parseInt(statStaffCount ? statStaffCount.innerText : '0') || 0;

            // ==================================================================
            // AUDIO STATE & WEB AUDIO API FEEDBACK
            // ==================================================================
            function updateAudioUI() {
                if (audioIcon && btnAudioToggle) {
                    if (isAudioMuted) {
                        audioIcon.className = 'fa-solid fa-volume-xmark';
                        btnAudioToggle.classList.add('muted');
                        btnAudioToggle.title = 'Nyalakan Suara (Saat ini: Senyap)';
                    } else {
                        audioIcon.className = 'fa-solid fa-volume-high';
                        btnAudioToggle.classList.remove('muted');
                        btnAudioToggle.title = 'Matikan Suara (Saat ini: Bersuara)';
                    }
                }
            }
            updateAudioUI();

            if (btnAudioToggle) {
                btnAudioToggle.addEventListener('click', function () {
                    isAudioMuted = !isAudioMuted;
                    localStorage.setItem('ais_scanner_muted', isAudioMuted ? 'true' : 'false');
                    updateAudioUI();
                });
            }

            function playAudioFeedback(type) {
                if (isAudioMuted) return;
                try {
                    const AudioCtx = window.AudioContext || window.webkitAudioContext;
                    if (!AudioCtx) return;
                    const ctx = new AudioCtx();

                    if (type === 'beep') {
                        const osc = ctx.createOscillator();
                        const gain = ctx.createGain();
                        osc.type = 'sine';
                        osc.frequency.setValueAtTime(1200, ctx.currentTime);
                        gain.gain.setValueAtTime(0.3, ctx.currentTime);
                        gain.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + 0.12);
                        osc.connect(gain);
                        gain.connect(ctx.destination);
                        osc.start();
                        osc.stop(ctx.currentTime + 0.12);
                    } else if (type === 'success') {
                        const osc = ctx.createOscillator();
                        const gain = ctx.createGain();
                        osc.type = 'triangle';
                        osc.frequency.setValueAtTime(523.25, ctx.currentTime);
                        osc.frequency.setValueAtTime(783.99, ctx.currentTime + 0.1);
                        gain.gain.setValueAtTime(0.35, ctx.currentTime);
                        gain.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + 0.35);
                        osc.connect(gain);
                        gain.connect(ctx.destination);
                        osc.start();
                        osc.stop(ctx.currentTime + 0.35);
                    } else if (type === 'warning') {
                        const osc = ctx.createOscillator();
                        const gain = ctx.createGain();
                        osc.type = 'sine';
                        osc.frequency.setValueAtTime(440, ctx.currentTime);
                        osc.frequency.setValueAtTime(370, ctx.currentTime + 0.12);
                        gain.gain.setValueAtTime(0.3, ctx.currentTime);
                        gain.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + 0.3);
                        osc.connect(gain);
                        gain.connect(ctx.destination);
                        osc.start();
                        osc.stop(ctx.currentTime + 0.3);
                    } else {
                        const osc = ctx.createOscillator();
                        const gain = ctx.createGain();
                        osc.type = 'sawtooth';
                        osc.frequency.setValueAtTime(220, ctx.currentTime);
                        osc.frequency.setValueAtTime(180, ctx.currentTime + 0.15);
                        gain.gain.setValueAtTime(0.25, ctx.currentTime);
                        gain.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + 0.35);
                        osc.connect(gain);
                        gain.connect(ctx.destination);
                        osc.start();
                        osc.stop(ctx.currentTime + 0.35);
                    }
                } catch (e) {}
            }

            // ==================================================================
            // FULLSCREEN TOGGLE
            // ==================================================================
            function updateFullscreenUI() {
                if (fullscreenIcon && btnFullscreen) {
                    if (document.fullscreenElement) {
                        fullscreenIcon.className = 'fa-solid fa-compress';
                        btnFullscreen.title = 'Keluar Layar Penuh';
                    } else {
                        fullscreenIcon.className = 'fa-solid fa-expand';
                        btnFullscreen.title = 'Masuk Layar Penuh (Kiosk Mode)';
                    }
                }
            }

            if (btnFullscreen) {
                btnFullscreen.addEventListener('click', function () {
                    if (!document.fullscreenElement) {
                        if (document.documentElement.requestFullscreen) {
                            document.documentElement.requestFullscreen().catch(() => {});
                        }
                    } else {
                        if (document.exitFullscreen) {
                            document.exitFullscreen().catch(() => {});
                        }
                    }
                });
                document.addEventListener('fullscreenchange', updateFullscreenUI);
            }

            // ==================================================================
            // NETWORK STATUS INDICATOR
            // ==================================================================
            function updateNetworkStatus() {
                if (!networkStatus || !networkText) return;
                if (navigator.onLine) {
                    networkStatus.className = 'kiosk-network-pill online';
                    networkText.innerText = 'Online';
                    networkStatus.title = 'Terhubung ke Jaringan';
                } else {
                    networkStatus.className = 'kiosk-network-pill offline';
                    networkText.innerText = 'Offline';
                    networkStatus.title = 'Koneksi Terputus - Periksa Wi-Fi';
                }
            }
            window.addEventListener('online', updateNetworkStatus);
            window.addEventListener('offline', updateNetworkStatus);
            updateNetworkStatus();

            // ==================================================================
            // JAM DIGITAL REALTIME, KALENDER GANDA & STATUS SESI
            // ==================================================================
            function updateKioskClock() {
                const now = new Date();

                // 1. Digital Clock HH:mm:ss
                const h = String(now.getHours()).padStart(2, '0');
                const m = String(now.getMinutes()).padStart(2, '0');
                const s = String(now.getSeconds()).padStart(2, '0');
                const clockEl = document.getElementById('kioskClock');
                if (clockEl) {
                    clockEl.innerHTML = `${h}:${m}<span class="clock-seconds">:${s}</span>`;
                }

                // 2. Masehi Date
                const masehiEl = document.getElementById('clockMasehi');
                if (masehiEl) {
                    const options = { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' };
                    masehiEl.innerHTML = `<i class="fa-regular fa-calendar-days"></i> <span>${now.toLocaleDateString('id-ID', options)}</span>`;
                }

                // 3. Hijri Date Otomatis
                const hijriEl = document.getElementById('clockHijri');
                if (hijriEl) {
                    try {
                        const hijriFormatter = new Intl.DateTimeFormat('id-u-ca-islamic-umalqura', {
                            day: 'numeric',
                            month: 'long',
                            year: 'numeric'
                        });
                        const hijriText = hijriFormatter.format(now);
                        hijriEl.innerHTML = `<i class="fa-solid fa-moon"></i> <span>${hijriText.endsWith('H') ? hijriText : hijriText + ' H'}</span>`;
                    } catch (e) {
                        hijriEl.style.display = 'none';
                    }
                }

                // 4. Status Sesi Gerbang Otomatis
                const timeVal = now.getHours() * 100 + now.getMinutes();
                const pill = document.getElementById('kioskSessionPill');
                const textEl = document.getElementById('sessionText');
                if (pill && textEl) {
                    if (timeVal < 715) {
                        pill.className = 'kiosk-session-pill ontime';
                        textEl.innerText = 'Sesi Masuk: Tepat Waktu (s/d 07:15)';
                    } else if (timeVal < 1200) {
                        pill.className = 'kiosk-session-pill late';
                        textEl.innerText = 'KBM Sedang Berlangsung (Terlambat)';
                    } else if (timeVal < 1700) {
                        pill.className = 'kiosk-session-pill pulang';
                        textEl.innerText = 'Sesi Kepulangan Siswa & Staff';
                    } else {
                        pill.className = 'kiosk-session-pill standby';
                        textEl.innerText = 'Gerbang Standby • Sesi Selesai';
                    }
                }
            }
            setInterval(updateKioskClock, 1000);
            updateKioskClock();

            // ==================================================================
            // ROTASI HADITS / KUTIPAN ISLAMI
            // ==================================================================
            const islamicQuotes = [
                { ar: "طَلَبُ الْعِلْمِ فَرِيْضَةٌ عَلَى كُلِّ مُسْلِمٍ", id: "\"Menuntut ilmu adalah kewajiban bagi setiap muslim.\" (HR. Ibnu Majah)" },
                { ar: "مَنْ سَلَكَ طَرِيقًا يَلْتَمِسُ فِيهِ عِلْمًا سَهَّلَ اللَّهُ لَهُ طَرِيقًا إِلَى الْجَنَّةِ", id: "\"Barangsiapa menempuh jalan mencari ilmu, Allah mudahkan jalannya menuju surga.\" (HR. Muslim)" },
                { ar: "خَيْرُكُمْ مَنْ تَعَلَّمَ الْقُرْآنَ وَعَلَّمَهُ", id: "\"Sebaik-baik kalian adalah orang yang belajar Al-Qur'an dan mengajarkannya.\" (HR. Bukhari)" },
                { ar: "إِنَّمَا بُعِثْتُ لِأُتَمِّمَ مَكَارِمَ الْأَخْلَاقِ", id: "\"Sesungguhnya aku diutus untuk menyempurnakan kemuliaan akhlak.\" (HR. Al-Baihaqi)" },
                { ar: "بِسْمِ اللَّهِ تَوَكَّلْتُ عَلَى اللَّهِ وَلَا حَوْلَ وَلَا قُوَّةَ إِلَّا بِاللَّهِ", id: "\"Dengan nama Allah, aku bertawakal kepada-Nya. Tiada daya dan kekuatan kecuali pertolongan-Nya.\"" }
            ];
            let quoteIdx = 0;
            function rotateQuote() {
                quoteIdx = (quoteIdx + 1) % islamicQuotes.length;
                const arEl = document.getElementById('quoteArabic');
                const idEl = document.getElementById('quoteMeaning');
                if (arEl && idEl) {
                    arEl.style.opacity = '0';
                    idEl.style.opacity = '0';
                    setTimeout(() => {
                        arEl.innerText = islamicQuotes[quoteIdx].ar;
                        idEl.innerText = islamicQuotes[quoteIdx].id;
                        arEl.style.opacity = '1';
                        idEl.style.opacity = '1';
                    }, 350);
                }
            }
            setInterval(rotateQuote, 14000);

            function escapeHtml(str) {
                if (!str) return '';
                const div = document.createElement('div');
                div.innerText = str;
                return div.innerHTML;
            }

            // ==================================================================
            // PREPEND RIWAYAT SCAN TERBARU KE FEED KANAN SECARA LIVE
            // ==================================================================
            function pushToRecentFeed(data) {
                if (!recentList) return;
                if (recentEmpty) recentEmpty.style.display = 'none';

                const isStaff = (data.type === 'staff');
                const folder = isStaff ? 'staff' : 'students';
                const initial = (data.name || 'U').charAt(0).toUpperCase();

                let avatarHtml = '';
                if (data.photo) {
                    avatarHtml = `<img src="../uploads/${folder}/${escapeHtml(data.photo)}" alt="Foto">`;
                } else {
                    avatarHtml = `<div class="avatar-initial">${initial}</div>`;
                }

                const subInfo = isStaff ? 'Staff / Guru' : (data.class_name || 'Siswa');
                const isOntime = (data.badge_class === 'ontime');
                const statusText = isOntime ? 'Tepat Waktu' : 'Terlambat';
                const statusClass = isOntime ? 'ontime' : 'late';
                const timeStr = (data.time || '').substring(0, 5) || '--:--';

                const item = document.createElement('div');
                item.className = 'recent-item just-scanned';
                item.innerHTML = `
                    <div class="recent-avatar">
                        ${avatarHtml}
                    </div>
                    <div class="recent-info">
                        <span class="recent-name">${escapeHtml(data.name)}</span>
                        <span class="recent-role">${escapeHtml(subInfo)}</span>
                    </div>
                    <div class="recent-badge-col">
                        <span class="recent-time">${timeStr}</span>
                        <span class="recent-status ${statusClass}">${statusText}</span>
                    </div>
                `;

                recentList.prepend(item);

                // Batasi hanya menampilkan 4 riwayat terakhir
                const currentItems = recentList.querySelectorAll('.recent-item');
                if (currentItems.length > 4) {
                    for (let i = 4; i < currentItems.length; i++) {
                        currentItems[i].remove();
                    }
                }

                // Update counter kartu statistik jika presensi baru
                if (!data.already) {
                    if (isStaff) {
                        currentStaffTotal++;
                        if (statStaffCount) statStaffCount.innerText = currentStaffTotal;
                    } else {
                        currentStudentTotal++;
                        if (statStudentCount) statStudentCount.innerText = currentStudentTotal;
                    }
                }
            }

            // ==================================================================
            // TAMPILKAN MODAL HASIL SCAN REAL-TIME
            // ==================================================================
            function showLiveResult(data, isSuccess) {
                clearTimeout(dismissTimer);
                if (progressAnim) cancelAnimationFrame(progressAnim);

                if (isSuccess && data.success) {
                    const isStudent = (data.type === 'student');
                    const isStaff = (data.type === 'staff');
                    const folder = isStaff ? 'staff' : 'students';
                    const initial = (data.name || 'U').charAt(0).toUpperCase();

                    let photoHtml = '';
                    if (data.photo) {
                        photoHtml = `<img src="../uploads/${folder}/${escapeHtml(data.photo)}" class="student-photo-large" alt="Foto">`;
                    } else {
                        photoHtml = `<div class="photo-placeholder-large">${initial}</div>`;
                    }

                    let warningHtml = '';
                    if (data.already) {
                        warningHtml = `
                            <div class="warning-banner-large">
                                ⛔ ${escapeHtml(data.message || 'Peringatan Sistem')}
                            </div>
                        `;
                    }

                    let greetingTitle = 'Selamat Datang 🌿';
                    let greetingSub = 'Semoga hari ini penuh berkah dan manfaat.';
                    if (isStudent) {
                        if (data.scan_type === 'pulang') {
                            greetingTitle = 'Fi Amanillah ✨';
                            greetingSub = 'Semoga pembelajaran hari ini diberkahi Allah dan bermanfaat.';
                        } else {
                            greetingTitle = 'Selamat bersekolah dengan bahagia 🌿';
                            greetingSub = 'Jangan lupa baca doa sebelum masuk kelas.';
                        }
                    } else {
                        if (data.scan_type === 'pulang') {
                            greetingTitle = 'Fi Amanillah ✨';
                            greetingSub = 'Terima kasih atas dedikasinya, semoga selamat sampai tujuan.';
                        } else {
                            greetingTitle = 'Selamat Bekerja 🌿';
                            greetingSub = 'Semoga pekerjaan hari ini berkah dan dilancarkan.';
                        }
                    }

                    let lemburHtml = '';
                    if (data.is_lembur && !data.already) {
                        lemburHtml = `
                            <div class="lembur-banner-large">
                                ⏱️ ${escapeHtml(data.lembur_msg || 'Sesi lembur telah tercatat ke dalam sistem.')}
                            </div>
                        `;
                    }

                    liveResultContent.innerHTML = `
                        <div class="attendance-result-card">
                            <div class="attendance-photo-col">
                                ${photoHtml}
                            </div>
                            <div class="attendance-info-col">
                                ${warningHtml}
                                <span class="role-badge-large">
                                    ${isStudent ? 'SISWA' : 'STAFF / GURU'}
                                </span>
                                <h2 class="attendance-name-large">${escapeHtml(data.name)}</h2>
                                <div class="attendance-meta-large">
                                    ${isStudent ? `NIS: <strong>${escapeHtml(data.nis || '-')}</strong> &bull; Kelas: <strong>${escapeHtml(data.class_name || '-')}</strong>` : `NIK: <strong>${escapeHtml(data.nik || '-')}</strong>`}
                                </div>
                                <div class="attendance-time-status-row">
                                    <span class="attendance-time-large">${escapeHtml(data.time)}</span>
                                    <span class="attendance-status attendance-status-large ${escapeHtml(data.badge_class || 'ontime')}">
                                        ${escapeHtml(data.badge_text || '✓ TEPAT WAKTU')}
                                    </span>
                                </div>
                                <div class="greeting-card-large">
                                    <h4>${greetingTitle}</h4>
                                    <p>${greetingSub}</p>
                                </div>
                                ${lemburHtml}
                            </div>
                        </div>
                    `;

                    playAudioFeedback(data.already ? 'warning' : 'success');
                    pushToRecentFeed(data);

                } else {
                    const msg = (data && data.message) ? data.message : 'Data kartu atau NIS / NIK tidak ditemukan dalam sistem!';
                    liveResultContent.innerHTML = `
                        <div style="text-align: center; padding: 32px 20px;">
                            <div style="font-size: 60px; margin-bottom: 12px;">⛔</div>
                            <h2 style="margin: 0 0 10px 0; font-size: 28px; font-weight: 800; color: #f87171;">Tidak Ditemukan</h2>
                            <p style="color: #cbd5e1; font-size: 16px; margin: 0; max-width: 500px; margin: 0 auto; line-height: 1.5;">${escapeHtml(msg)}</p>
                        </div>
                    `;
                    playAudioFeedback('error');
                }

                liveResultModal.style.display = 'flex';

                const duration = isSuccess ? 4500 : 2800;
                const startTime = performance.now();
                liveProgressBar.style.width = '100%';

                function frame(now) {
                    const elapsed = now - startTime;
                    const remaining = Math.max(0, 1 - (elapsed / duration));
                    liveProgressBar.style.width = (remaining * 100) + '%';
                    if (elapsed < duration) {
                        progressAnim = requestAnimationFrame(frame);
                    } else {
                        dismissResult();
                    }
                }
                progressAnim = requestAnimationFrame(frame);
            }

            function dismissResult() {
                clearTimeout(dismissTimer);
                if (progressAnim) cancelAnimationFrame(progressAnim);
                liveResultModal.style.display = 'none';
                if (scanSuccessOverlay) scanSuccessOverlay.style.display = 'none';

                isProcessing = false;

                if (currentMode === 'mesin') {
                    if (manualCodeInput) {
                        manualCodeInput.value = '';
                        manualCodeInput.focus();
                    }
                    if (barcodeInput) {
                        barcodeInput.value = '';
                        barcodeInput.focus();
                    }
                }
            }

            if (btnDismissResult) {
                btnDismissResult.addEventListener('click', dismissResult);
            }
            if (liveResultBackdrop) {
                liveResultBackdrop.addEventListener('click', dismissResult);
            }

            document.addEventListener('keydown', function (e) {
                if (liveResultModal.style.display === 'flex') {
                    if (e.key === ' ' || e.key === 'Enter' || e.key === 'Escape') {
                        e.preventDefault();
                        dismissResult();
                    }
                }
            });

            // ==================================================================
            // PROSES HASIL PEMINDAIAN KODE
            // ==================================================================
            async function onCodeScanned(decodedText) {
                if (isProcessing) return;
                isProcessing = true;

                playAudioFeedback('beep');
                if (navigator.vibrate) {
                    try { navigator.vibrate(100); } catch (e) {}
                }

                if (successCodeText) successCodeText.innerText = decodedText;
                if (scanSuccessOverlay) scanSuccessOverlay.style.display = 'flex';
                if (barcodeInput) barcodeInput.value = decodedText;

                try {
                    const response = await fetch('api/scan.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ code: decodedText })
                    });

                    const data = await response.json();
                    if (scanSuccessOverlay) scanSuccessOverlay.style.display = 'none';

                    if (response.ok && data.success) {
                        showLiveResult(data, true);
                    } else {
                        showLiveResult(data, false);
                    }
                } catch (networkErr) {
                    console.warn('Scan API network error, fallback to form POST:', networkErr);
                    setTimeout(() => {
                        scannerForm.submit();
                    }, 300);
                }
            }

            function showCameraError(title, desc, debugInfo = '') {
                if (cameraLoading) cameraLoading.style.display = 'none';
                if (cameraErrorTitle) cameraErrorTitle.innerText = title;
                if (cameraErrorDesc) cameraErrorDesc.innerText = desc;

                const debugEl = document.getElementById('cameraErrorDebug');
                if (debugEl) {
                    if (debugInfo) {
                        debugEl.innerText = 'Log: ' + debugInfo;
                        debugEl.style.display = 'block';
                    } else {
                        debugEl.style.display = 'none';
                    }
                }

                const iosTip = document.getElementById('iosSafariTip');
                const isApple = /iPhone|iPad|iPod/i.test(navigator.userAgent);
                if (iosTip) {
                    iosTip.style.display = isApple ? 'block' : 'none';
                }

                if (cameraErrorView) cameraErrorView.style.display = 'flex';
            }

            function ensureVideoPlaying() {
                if (!readerElement) return;
                const video = readerElement.querySelector('video');
                if (video) {
                    video.setAttribute('playsinline', 'true');
                    video.setAttribute('webkit-playsinline', 'true');
                    video.setAttribute('autoplay', 'true');
                    video.setAttribute('muted', 'true');
                    video.muted = true;
                    
                    const playPromise = video.play();
                    if (playPromise !== undefined) {
                        playPromise.catch(err => {
                            console.warn('Auto-play notice:', err);
                        });
                    }
                }
            }

            function waitForVideoReady(videoEl, callback) {
                if (!videoEl) {
                    callback();
                    return;
                }
                if (videoEl.readyState >= 2 && !videoEl.paused) {
                    callback();
                    return;
                }
                let isCalled = false;
                function onReady() {
                    if (isCalled) return;
                    isCalled = true;
                    videoEl.removeEventListener('playing', onReady);
                    videoEl.removeEventListener('loadeddata', onReady);
                    callback();
                }
                videoEl.addEventListener('playing', onReady);
                videoEl.addEventListener('loadeddata', onReady);
                setTimeout(onReady, 1200);
            }

            async function stopCamera() {
                if (html5QrCode) {
                    try {
                        const state = html5QrCode.getState ? html5QrCode.getState() : 0;
                        if (state === 2 || state === 3) {
                            await html5QrCode.stop();
                        }
                    } catch (e) {
                        console.warn('html5QrCode.stop() ignored error:', e);
                    }

                    try {
                        await html5QrCode.clear();
                    } catch (e) {
                        console.warn('html5QrCode.clear() ignored error:', e);
                    }

                    html5QrCode = null;
                }

                if (readerElement) {
                    const videos = readerElement.querySelectorAll('video');
                    videos.forEach(v => {
                        try {
                            if (v.srcObject && v.srcObject.getTracks) {
                                v.srcObject.getTracks().forEach(t => t.stop());
                            }
                            v.srcObject = null;
                            v.pause();
                            v.remove();
                        } catch (err) {}
                    });
                    readerElement.innerHTML = '';
                }

                isScanningActive = false;
            }

            async function startCamera(mode, specificCameraId = null) {
                if (isCameraStarting) {
                    console.warn('Camera is already starting, skip duplicate invocation.');
                    return;
                }
                isCameraStarting = true;

                try {
                    await stopCamera();

                    if (cameraLoading) cameraLoading.style.display = 'flex';
                    if (cameraErrorView) cameraErrorView.style.display = 'none';
                    if (cameraLoadingText) cameraLoadingText.innerText = 'Mengaktifkan sensor kamera...';

                    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
                        showCameraError('Browser Tidak Mendukung', 'Browser ini tidak mengizinkan akses kamera atau tidak melalui koneksi aman HTTPS.');
                        return;
                    }

                    if (!window.Html5Qrcode) {
                        showCameraError('Library Error', 'Library pemindai kamera tidak tersedia.');
                        return;
                    }

                    html5QrCode = new Html5Qrcode('reader', {
                        formatsToSupport: [
                            Html5QrcodeSupportedFormats.QR_CODE,
                            Html5QrcodeSupportedFormats.CODE_128,
                            Html5QrcodeSupportedFormats.CODE_39,
                            Html5QrcodeSupportedFormats.EAN_13,
                            Html5QrcodeSupportedFormats.EAN_8,
                            Html5QrcodeSupportedFormats.UPC_A,
                            Html5QrcodeSupportedFormats.UPC_E,
                            Html5QrcodeSupportedFormats.ITF
                        ],
                        verbose: false
                    });

                    if (mode === 'laptop') {
                        readerElement.classList.add('mirror-video');
                        scanTip.innerText = 'Arahkan kartu ke kamera depan / webcam laptop Anda';
                    } else {
                        readerElement.classList.remove('mirror-video');
                        scanTip.innerText = 'Arahkan kartu ke kamera belakang handphone Anda';
                    }

                    let cameraSource = null;
                    if (specificCameraId) {
                        cameraSource = specificCameraId;
                    } else if (mode === 'hp') {
                        cameraSource = { facingMode: 'environment' };
                    } else {
                        cameraSource = { facingMode: 'user' };
                    }

                    const scanConfig = {
                        fps: 15,
                        disableFlip: (mode === 'laptop')
                    };

                    try {
                        await html5QrCode.start(
                            cameraSource,
                            scanConfig,
                            (decodedText) => onCodeScanned(decodedText),
                            () => {}
                        );
                    } catch (firstErr) {
                        console.warn('Percobaan pertama kamera gagal:', firstErr);
                        if (mode === 'hp' && !specificCameraId) {
                            await stopCamera();
                            html5QrCode = new Html5Qrcode('reader', { verbose: false });
                            await html5QrCode.start(
                                { facingMode: 'user' },
                                scanConfig,
                                (decodedText) => onCodeScanned(decodedText),
                                () => {}
                            );
                        } else {
                            throw firstErr;
                        }
                    }

                    isScanningActive = true;
                    ensureVideoPlaying();

                    const videoEl = readerElement.querySelector('video');
                    waitForVideoReady(videoEl, () => {
                        if (cameraLoading) cameraLoading.style.display = 'none';
                        if (cameraErrorView) cameraErrorView.style.display = 'none';
                    });

                    await loadCameraDevices(specificCameraId);

                } catch (err) {
                    console.error('Camera start error:', err);
                    if (cameraLoading) cameraLoading.style.display = 'none';

                    const errStr = String(err).toLowerCase();
                    let title = 'Tidak Dapat Mengakses Kamera';
                    let desc = 'Pastikan kamera aktif dan browser diizinkan mengakses kamera.';

                    if (err.name === 'NotAllowedError' || errStr.includes('notallowed') || errStr.includes('permission') || errStr.includes('denied')) {
                        title = 'Izin Akses Kamera Ditolak';
                        desc = 'Browser Anda belum mengizinkan akses ke kamera. Silakan aktifkan izin kamera di pengaturan browser lalu tekan tombol di bawah.';
                    } else if (err.name === 'NotFoundError' || errStr.includes('notfound') || errStr.includes('no camera')) {
                        title = 'Kamera Tidak Ditemukan';
                        desc = 'Perangkat ini tidak mendeteksi sensor kamera yang kompatibel. Silakan gunakan mode Mesin Scanner.';
                    } else if (err.name === 'NotReadableError' || errStr.includes('notreadable')) {
                        title = 'Kamera Sedang Digunakan';
                        desc = 'Kamera kemungkinan sedang dibuka oleh aplikasi lain. Tutup aplikasi tersebut dan coba lagi.';
                    } else if (err.name === 'OverconstrainedError' || errStr.includes('overconstrained')) {
                        title = 'Sensor Kamera Tidak Didukung';
                        desc = 'Lensa kamera yang diminta tidak dapat dibuka dengan pengaturan saat ini.';
                    }

                    showCameraError(title, desc, String(err.message || err));
                } finally {
                    isCameraStarting = false;
                }
            }

            async function loadCameraDevices(selectedId = null) {
                try {
                    const devices = await Html5Qrcode.getCameras();
                    availableCameras = devices || [];

                    if (availableCameras.length > 1) {
                        cameraSelectWrap.style.display = 'inline-flex';
                        if (btnFlipCamera) btnFlipCamera.style.display = 'inline-flex';

                        cameraSelect.innerHTML = '';
                        availableCameras.forEach((device, index) => {
                            const opt = document.createElement('option');
                            opt.value = device.id;
                            let label = device.label || `Kamera ${index + 1}`;
                            opt.text = label;
                            if (selectedId && device.id === selectedId) {
                                opt.selected = true;
                            }
                            cameraSelect.appendChild(opt);
                        });
                    } else {
                        cameraSelectWrap.style.display = 'none';
                        if (btnFlipCamera) btnFlipCamera.style.display = 'none';
                    }
                } catch (e) {
                    cameraSelectWrap.style.display = 'none';
                    if (btnFlipCamera) btnFlipCamera.style.display = 'none';
                }
            }

            if (cameraSelect) {
                cameraSelect.addEventListener('change', function () {
                    if (this.value) {
                        startCamera(currentMode, this.value);
                    }
                });
            }

            if (btnFlipCamera) {
                btnFlipCamera.addEventListener('click', function () {
                    if (currentMode === 'hp') {
                        switchMode('laptop');
                    } else {
                        switchMode('hp');
                    }
                });
            }

            if (btnRetryCamera) {
                btnRetryCamera.addEventListener('click', async function () {
                    try {
                        if (navigator.mediaDevices && navigator.mediaDevices.getUserMedia) {
                            const stream = await navigator.mediaDevices.getUserMedia({ video: true });
                            stream.getTracks().forEach(t => t.stop());
                        }
                    } catch (e) {}
                    startCamera(currentMode);
                });
            }

            if (btnFallbackMesin) {
                btnFallbackMesin.addEventListener('click', function () {
                    switchMode('mesin');
                });
            }

            async function switchMode(mode, specificCameraId = null) {
                if (isSwitchingMode) return;
                isSwitchingMode = true;

                try {
                    currentMode = mode;
                    localStorage.setItem('ais_scanner_mode', mode);

                    modeButtons.forEach(btn => {
                        if (btn.dataset.mode === mode) {
                            btn.classList.add('active');
                        } else {
                            btn.classList.remove('active');
                        }
                    });

                    if (mode === 'mesin') {
                        await stopCamera();
                        cameraSection.style.display = 'none';
                        mesinSection.style.display = 'block';
                        if (manualCodeInput) manualCodeInput.focus();
                        if (barcodeInput) barcodeInput.focus();
                    } else {
                        mesinSection.style.display = 'none';
                        cameraSection.style.display = 'block';
                        await startCamera(mode, specificCameraId);
                    }
                } finally {
                    isSwitchingMode = false;
                }
            }

            modeHpBtn.addEventListener('click', () => switchMode('hp'));
            modeLaptopBtn.addEventListener('click', () => switchMode('laptop'));
            modeMesinBtn.addEventListener('click', () => switchMode('mesin'));

            function handleManualSubmit() {
                const val = (manualCodeInput ? manualCodeInput.value : '').trim();
                if (!val) {
                    alert('Silakan masukkan NIS atau NIK terlebih dahulu.');
                    if (manualCodeInput) manualCodeInput.focus();
                    return;
                }
                onCodeScanned(val);
            }

            if (manualSubmitBtn) {
                manualSubmitBtn.addEventListener('click', handleManualSubmit);
            }
            if (manualCodeInput) {
                manualCodeInput.addEventListener('keydown', function (e) {
                    if (e.key === 'Enter') {
                        e.preventDefault();
                        handleManualSubmit();
                    }
                });
            }

            document.addEventListener('click', function (e) {
                if (currentMode === 'mesin' && !e.target.closest('#manualCodeInput') && !e.target.closest('#manualSubmitBtn')) {
                    if (barcodeInput) barcodeInput.focus();
                }
            });

            // Deteksi input hardware scanner otomatis
            let barcodeBuffer = '';
            let lastKeyTime = Date.now();
            document.addEventListener('keydown', function (e) {
                const now = Date.now();
                if (e.target === manualCodeInput) return;

                if (e.key === 'Enter') {
                    if (barcodeBuffer.length > 2 && (now - lastKeyTime < 250)) {
                        e.preventDefault();
                        onCodeScanned(barcodeBuffer);
                        barcodeBuffer = '';
                    }
                } else if (e.key.length === 1) {
                    if (now - lastKeyTime > 300) {
                        barcodeBuffer = '';
                    }
                    barcodeBuffer += e.key;
                    lastKeyTime = now;
                }
            });

            function getDefaultMode() {
                const saved = localStorage.getItem('ais_scanner_mode');
                if (saved && ['hp', 'laptop', 'mesin'].includes(saved)) {
                    return saved;
                }
                const isMobileDevice = /Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i.test(navigator.userAgent) || window.innerWidth <= 768;
                return isMobileDevice ? 'hp' : 'laptop';
            }

            const defaultMode = getDefaultMode();
            switchMode(defaultMode);

        })();
        </script>

    <?php endif; ?>
</div>
</body>
</html>
