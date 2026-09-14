<?php

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../config/security.php';
require_once __DIR__ . '/../../includes/functions.php';

requireAdmin();


$period =
    getReportPeriod();


$dateStart =
    $period['start'];

$dateEnd =
    $period['end'];


$name =
    trim(
        $_GET['name']
        ?? ''
    );

$gradeId =
    (int)(
        $_GET['grade_id']
        ?? 0
    );

$classId =
    (int)(
        $_GET['class_group_id']
        ?? 0
    );

$status =
    $_GET['status']
    ?? '';


$access =
    getStudentAccessCondition(
        'g',
        'cg'
    );


$where = [

    "s.deleted_at IS NULL",

    "sa.attendance_date BETWEEN ? AND ?",

    $access['condition']

];


$params = [

    $dateStart,
    $dateEnd

];


$params =
    array_merge(
        $params,
        $access['params']
    );


if ($name !== '') {

    $where[] =
        "s.name LIKE ?";

    $params[] =
        "%{$name}%";
}


if ($gradeId > 0) {

    $where[] =
        "g.id = ?";

    $params[] =
        $gradeId;
}


if ($classId > 0) {

    $where[] =
        "cg.id = ?";

    $params[] =
        $classId;
}


if (
    $status === 'tepat_waktu'
    ||
    $status === 'terlambat'
) {

    $where[] =
        "sa.status = ?";

    $params[] =
        $status;
}


$whereSql =
    implode(
        ' AND ',
        $where
    );


$stmt =
    $pdo->prepare("
        SELECT

            sa.attendance_date,

            s.name,

            s.nis,

            g.grade,

            cg.name AS class_name,

            a.name AS activity_name,

            sa.time_in,

            sa.status

        FROM student_attendances sa

        INNER JOIN students s
            ON s.id = sa.student_id

        INNER JOIN student_enrollments se
            ON se.id = sa.enrollment_id

        INNER JOIN class_groups cg
            ON cg.id = se.class_group_id

        INNER JOIN grades g
            ON g.id = cg.grade_id

        INNER JOIN activities a
            ON a.id = sa.activity_id

        WHERE {$whereSql}

        ORDER BY
            sa.attendance_date,
            g.sort_order,
            cg.name,
            s.name
    ");

$stmt->execute(
    $params
);

$rows = $stmt->fetchAll();

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="laporan_absensi_' . $dateStart . '_sampai_' . $dateEnd . '.csv"');
header('Pragma: no-cache');
header('Expires: 0');

$output = fopen('php://output', 'w');
// Write UTF-8 BOM so Excel opens with proper Indonesian encoding
fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));

// Header Kolom
fputcsv($output, ['No', 'Tanggal', 'Nama Siswa', 'NIS', 'Grade', 'Subkelas', 'Kegiatan KBM', 'Jam Masuk', 'Status Kehadiran']);

foreach ($rows as $index => $row) {
    fputcsv($output, [
        $index + 1,
        $row['attendance_date'],
        $row['name'],
        !empty($row['nis']) ? "'" . $row['nis'] : '-',
        $row['grade'],
        $row['class_name'],
        $row['activity_name'],
        $row['time_in'] ?? '-',
        ($row['status'] === 'tepat_waktu') ? 'Tepat Waktu' : 'Terlambat'
    ]);
}

fclose($output);
exit;