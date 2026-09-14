<?php
require_once '../config/database.php';
require_once '../config/auth.php';
require_once '../config/security.php';
require_once '../config/config.php';
require_once '../includes/functions.php';

requireLogin();
$pageTitle = 'Dashboard';
$role = $_SESSION['role'];

/*
|--------------------------------------------------------------------------
| Statistik Chart (Filter Hari, Bulan, Tahun)
|--------------------------------------------------------------------------
*/
$chartType = $_GET['chart_type'] ?? 'student'; 
$filterMode = $_GET['filter_mode'] ?? 'year'; 
$filterYear = (int)($_GET['filter_year'] ?? date('Y'));
$filterMonth = (int)($_GET['filter_month'] ?? date('n'));

$chartLabels = [];
$chartOntime = [];
$chartLate = [];

if ($role !== 'super_admin') {
    $chartType = 'student';
}
$tableTarget = $chartType === 'student' ? 'student_attendances' : 'staff_attendances';

$whereCondition = "WHERE YEAR(sa.attendance_date) = ?";
$paramsChart = [$filterYear];

if ($filterMode === 'month') {
    $daysInMonth = cal_days_in_month(CAL_GREGORIAN, $filterMonth, $filterYear);
    for ($i = 1; $i <= $daysInMonth; $i++) {
        $chartLabels[] = $i;
        $chartOntime[$i] = 0;
        $chartLate[$i] = 0;
    }
    $whereCondition .= " AND MONTH(sa.attendance_date) = ?";
    $paramsChart[] = $filterMonth;
} else {
    $months = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];
    foreach ($months as $i => $m) {
        $chartLabels[] = $m;
        $chartOntime[$i+1] = 0;
        $chartLate[$i+1] = 0;
    }
}

if ($role !== 'super_admin' && $chartType === 'student') {
    $joinSql = "
        JOIN student_enrollments se ON se.id = sa.enrollment_id
        JOIN class_groups cg ON cg.id = se.class_group_id
        JOIN grades g ON g.id = cg.grade_id
    ";
    $whereCondition .= " AND (g.id IN (SELECT grade_id FROM admin_grade_permissions WHERE user_id = ?) 
                         OR cg.id IN (SELECT class_group_id FROM admin_class_permissions WHERE user_id = ?))";
    $paramsChart[] = currentUserId();
    $paramsChart[] = currentUserId();
} else {
    $joinSql = "";
}

$groupCol = ($filterMode === 'month') ? 'DAY(sa.attendance_date)' : 'MONTH(sa.attendance_date)';
$stmtChart = $pdo->prepare("
    SELECT {$groupCol} as label, sa.status, COUNT(sa.id) as total 
    FROM {$tableTarget} sa
    {$joinSql}
    {$whereCondition}
    GROUP BY {$groupCol}, sa.status
");
$stmtChart->execute($paramsChart);

$chartData = $stmtChart->fetchAll();
foreach ($chartData as $row) {
    $idx = (int)$row['label'];
    if ($row['status'] === 'tepat_waktu') {
        $chartOntime[$idx] = (int)$row['total'];
    } else {
        $chartLate[$idx] = (int)$row['total'];
    }
}

$jsLabels = json_encode(array_values($chartLabels));
$jsOntime = json_encode(array_values($chartOntime));
$jsLate = json_encode(array_values($chartLate));

/*
|--------------------------------------------------------------------------
| Counter Atas
|--------------------------------------------------------------------------
*/
if ($role === 'super_admin') {
    $totalStudents = $pdo->query("SELECT COUNT(*) FROM students WHERE deleted_at IS NULL")->fetchColumn();
    $totalStaff = $pdo->query("SELECT COUNT(*) FROM staff WHERE deleted_at IS NULL")->fetchColumn();
    $todayTotal = $pdo->query("SELECT COUNT(*) FROM student_attendances WHERE attendance_date = CURDATE()")->fetchColumn();
    $todayLate = $pdo->query("SELECT COUNT(*) FROM student_attendances WHERE attendance_date = CURDATE() AND status = 'terlambat'")->fetchColumn();
} else {
    $stmt = $pdo->prepare("
        SELECT COUNT(DISTINCT s.id) FROM students s
        JOIN student_enrollments se ON se.student_id = s.id
        JOIN class_groups cg ON cg.id = se.class_group_id
        JOIN grades g ON g.id = cg.grade_id
        JOIN academic_years ay ON ay.id = se.academic_year_id
        WHERE s.deleted_at IS NULL AND ay.status = 'active'
        AND (g.id IN (SELECT grade_id FROM admin_grade_permissions WHERE user_id = ?) 
        OR cg.id IN (SELECT class_group_id FROM admin_class_permissions WHERE user_id = ?))
    ");
    $stmt->execute([currentUserId(), currentUserId()]);
    $totalStudents = $stmt->fetchColumn();
    $totalStaff = 0;

    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM student_attendances sa
        JOIN student_enrollments se ON se.id = sa.enrollment_id
        JOIN class_groups cg ON cg.id = se.class_group_id
        JOIN grades g ON g.id = cg.grade_id
        WHERE sa.attendance_date = CURDATE()
        AND (g.id IN (SELECT grade_id FROM admin_grade_permissions WHERE user_id = ?) 
        OR cg.id IN (SELECT class_group_id FROM admin_class_permissions WHERE user_id = ?))
    ");
    $stmt->execute([currentUserId(), currentUserId()]);
    $todayTotal = $stmt->fetchColumn();

    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM student_attendances sa
        JOIN student_enrollments se ON se.id = sa.enrollment_id
        JOIN class_groups cg ON cg.id = se.class_group_id
        JOIN grades g ON g.id = cg.grade_id
        WHERE sa.attendance_date = CURDATE() AND sa.status = 'terlambat'
        AND (g.id IN (SELECT grade_id FROM admin_grade_permissions WHERE user_id = ?) 
        OR cg.id IN (SELECT class_group_id FROM admin_class_permissions WHERE user_id = ?))
    ");
    $stmt->execute([currentUserId(), currentUserId()]);
    $todayLate = $stmt->fetchColumn();
}

require '../includes/header.php';
?>

<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-icon stat-icon-student">
            <i class="fa-solid fa-user-graduate"></i>
        </div>
        <h3><?= number_format($totalStudents) ?></h3>
        <p>Total Siswa</p>
    </div>
    <?php if ($role === 'super_admin'): ?>
    <div class="stat-card">
        <div class="stat-icon stat-icon-staff">
            <i class="fa-solid fa-chalkboard-user"></i>
        </div>
        <h3><?= number_format($totalStaff) ?></h3>
        <p>Staff / Guru</p>
    </div>
    <?php endif; ?>
    <div class="stat-card">
        <div class="stat-icon stat-icon-success">
            <i class="fa-solid fa-clipboard-check"></i>
        </div>
        <h3><?= number_format($todayTotal) ?></h3>
        <p>Absensi Hari Ini</p>
    </div>
    <div class="stat-card">
        <div class="stat-icon stat-icon-warning">
            <i class="fa-solid fa-clock-rotate-left"></i>
        </div>
        <h3><?= number_format($todayLate) ?></h3>
        <p>Terlambat Hari Ini</p>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <div>
            <h3 style="display: flex; align-items: center; gap: 8px;">
                <i class="fa-solid fa-chart-column" style="color: var(--primary);"></i>
                Statistik Kehadiran
            </h3>
            <small>Data akurat berdasarkan absensi harian</small>
        </div>
    </div>
    
    <!-- Filter yang dirubah menjadi Horizontal (Grid) -->
    <form method="GET" style="border-bottom: 1px solid #e2e8f0; padding-bottom: 20px; margin-bottom: 20px;">
        <div class="filter-grid">
            <div class="form-group">
                <label><i class="fa-solid fa-users" style="font-size: 12px; color: #64748b; margin-right: 4px;"></i> Tipe Data</label>
                <select name="chart_type" onchange="this.form.submit()">
                    <option value="student" <?= $chartType === 'student' ? 'selected' : '' ?>>Siswa</option>
                    <option value="staff" <?= $chartType === 'staff' ? 'selected' : '' ?>>Staff / Guru</option>
                </select>
            </div>
            
            <div class="form-group">
                <label><i class="fa-solid fa-chart-simple" style="font-size: 12px; color: #64748b; margin-right: 4px;"></i> Tampilan Grafik</label>
                <select name="filter_mode" onchange="this.form.submit()">
                    <option value="year" <?= $filterMode === 'year' ? 'selected' : '' ?>>Grafik Bulanan (1 Tahun)</option>
                    <option value="month" <?= $filterMode === 'month' ? 'selected' : '' ?>>Grafik Harian (1 Bulan)</option>
                </select>
            </div>

            <?php if ($filterMode === 'month'): ?>
            <div class="form-group">
                <label><i class="fa-regular fa-calendar" style="font-size: 12px; color: #64748b; margin-right: 4px;"></i> Pilih Bulan</label>
                <select name="filter_month" onchange="this.form.submit()">
                    <?php 
                    $namaBulan = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];
                    foreach($namaBulan as $index => $nama): 
                        $num = $index + 1;
                    ?>
                        <option value="<?= $num ?>" <?= $filterMonth == $num ? 'selected' : '' ?>><?= $nama ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>

            <div class="form-group">
                <label><i class="fa-solid fa-calendar-day" style="font-size: 12px; color: #64748b; margin-right: 4px;"></i> Tahun</label>
                <select name="filter_year" onchange="this.form.submit()">
                    <?php for($y = date('Y'); $y >= 2023; $y--): ?>
                        <option value="<?= $y ?>" <?= $filterYear == $y ? 'selected' : '' ?>><?= $y ?></option>
                    <?php endfor; ?>
                </select>
            </div>
        </div>
    </form>
    
    <div style="padding: 10px;">
        <canvas id="attendanceChart" height="100"></canvas>
    </div>
</div>

<script>
const ctx = document.getElementById('attendanceChart');
new Chart(ctx, {
    type: 'bar', 
    data: {
        labels: <?= $jsLabels ?>,
        datasets: [
            {
                label: 'Tepat Waktu',
                data: <?= $jsOntime ?>,
                backgroundColor: '#10b981',
                borderRadius: 4
            },
            {
                label: 'Terlambat',
                data: <?= $jsLate ?>,
                backgroundColor: '#ef4444',
                borderRadius: 4
            }
        ]
    },
    options: {
        responsive: true,
        plugins: { legend: { position: 'bottom' } },
        scales: {
            y: {
                beginAtZero: true,
                ticks: {
                    stepSize: 1, 
                    precision: 0 
                }
            }
        }
    }
});
</script>

<?php require '../includes/footer.php'; ?>