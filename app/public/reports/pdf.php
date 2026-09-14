<?php

require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../config/auth.php';
require_once '../../config/security.php';
require_once '../../includes/functions.php';

requireAdmin();


require_once '../../vendor/fpdf/fpdf.php';


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
    $status
    === 'tepat_waktu'
    ||
    $status
    === 'terlambat'
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

$rows =
    $stmt->fetchAll();


$pdf =
    new FPDF(
        'L',
        'mm',
        'A4'
    );


$pdf->AddPage();


$pdf->SetFont(
    'Arial',
    'B',
    16
);


$pdf->Cell(
    0,
    10,
    'LAPORAN ABSENSI SISWA',
    0,
    1,
    'C'
);


$pdf->SetFont(
    'Arial',
    '',
    10
);


$pdf->Cell(
    0,
    7,
    'Periode: '
    . $dateStart
    . ' s/d '
    . $dateEnd,
    0,
    1,
    'C'
);


$pdf->Ln(5);


/*
|--------------------------------------------------------------------------
| Header tabel
|--------------------------------------------------------------------------
*/

$pdf->SetFont(
    'Arial',
    'B',
    8
);


$headers = [

    ['No', 10],

    ['Tanggal', 25],

    ['Nama', 50],

    ['NIS', 25],

    ['Kelas', 20],

    ['Subkelas', 22],

    ['Kegiatan', 45],

    ['Jam', 20],

    ['Status', 30]

];


foreach (
    $headers as $header
) {

    $pdf->Cell(
        $header[1],
        8,
        $header[0],
        1,
        0,
        'C'
    );

}


$pdf->Ln();


/*
|--------------------------------------------------------------------------
| Isi
|--------------------------------------------------------------------------
*/

$pdf->SetFont(
    'Arial',
    '',
    7
);


foreach (
    $rows as $index => $row
) {

    $statusText =
        $row['status']
        === 'tepat_waktu'
        ? 'Tepat Waktu'
        : 'Terlambat';


    $pdf->Cell(
        10,
        7,
        $index + 1,
        1
    );


    $pdf->Cell(
        25,
        7,
        $row['attendance_date'],
        1
    );


    $pdf->Cell(
        50,
        7,
        mb_substr(
            $row['name'],
            0,
            28
        ),
        1
    );


    $pdf->Cell(
        25,
        7,
        $row['nis'],
        1
    );


    $pdf->Cell(
        20,
        7,
        $row['grade'],
        1
    );


    $pdf->Cell(
        22,
        7,
        $row['class_name'],
        1
    );


    $pdf->Cell(
        45,
        7,
        mb_substr(
            $row['activity_name'],
            0,
            25
        ),
        1
    );


    $pdf->Cell(
        20,
        7,
        $row['time_in'],
        1
    );


    $pdf->Cell(
        30,
        7,
        $statusText,
        1
    );


    $pdf->Ln();

}


$pdf->Output(
    'D',
    'laporan_absensi_'
    . $dateStart
    . '_'
    . $dateEnd
    . '.pdf'
);