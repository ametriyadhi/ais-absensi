<?php

require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../config/auth.php';
require_once '../../config/security.php';
require_once '../../includes/functions.php';

requireSuperAdmin();

$pageTitle = 'Kenaikan Kelas';


$years =
    $pdo->query("
        SELECT *
        FROM academic_years
        ORDER BY start_date DESC
    ")->fetchAll();


$classGroups =
    $pdo->query("
        SELECT
            cg.id,
            cg.name,
            g.grade,
            g.sort_order

        FROM class_groups cg

        INNER JOIN grades g
            ON g.id = cg.grade_id

        ORDER BY
            g.sort_order,
            cg.name
    ")->fetchAll();


$sourceYearId =
    (int)(
        $_GET['source_year_id']
        ?? 0
    );

$targetYearId =
    (int)(
        $_GET['target_year_id']
        ?? 0
    );


if ($sourceYearId === 0) {

    foreach ($years as $year) {

        if (
            $year['status']
            === 'active'
        ) {

            $sourceYearId =
                $year['id'];

            break;

        }

    }

}


$students = [];


if (
    $sourceYearId > 0
    &&
    $targetYearId > 0
) {

    $stmt =
        $pdo->prepare("
            SELECT

                s.id,
                s.name,
                s.nis,

                se.id AS enrollment_id,

                cg.name AS current_class,

                g.grade

            FROM students s

            INNER JOIN student_enrollments se
                ON se.student_id = s.id

            INNER JOIN class_groups cg
                ON cg.id = se.class_group_id

            INNER JOIN grades g
                ON g.id = cg.grade_id

            WHERE

                se.academic_year_id = ?

            AND se.status = 'active'

            AND s.deleted_at IS NULL

            ORDER BY
                g.sort_order,
                cg.name,
                s.name
        ");

    $stmt->execute([
        $sourceYearId
    ]);

    $students =
        $stmt->fetchAll();

}


/*
|--------------------------------------------------------------------------
| PROSES
|--------------------------------------------------------------------------
*/

if (
    $_SERVER['REQUEST_METHOD']
    === 'POST'
) {
    requireCsrf();

    $sourceYear =
        (int)(
            $_POST['source_year_id']
            ?? 0
        );

    $targetYear =
        (int)(
            $_POST['target_year_id']
            ?? 0
        );


    $studentIds =
        $_POST['student_ids']
        ?? [];


    $classMap =
        $_POST['class_group_id']
        ?? [];


    $actionMap =
        $_POST['action']
        ?? [];


    try {

        $pdo->beginTransaction();


        foreach (
            $studentIds as $studentId
        ) {

            $studentId =
                (int)$studentId;


            $action =
                $actionMap[$studentId]
                ?? 'promote';


            /*
            |--------------------------------------------------------------------------
            | Sudah ada target?
            |--------------------------------------------------------------------------
            */

            $stmt =
                $pdo->prepare("
                    SELECT id
                    FROM student_enrollments

                    WHERE
                        student_id = ?

                    AND academic_year_id = ?

                    LIMIT 1
                ");

            $stmt->execute([
                $studentId,
                $targetYear
            ]);


            if (
                $stmt->fetch()
            ) {

                continue;

            }


            if (
                $action === 'promote'
            ) {

                $classId =
                    (int)(
                        $classMap[$studentId]
                        ?? 0
                    );


                if (
                    $classId <= 0
                ) {

                    continue;

                }


                $status =
                    'active';

            } elseif (
                $action === 'graduate'
            ) {

                /*
                | Tidak masuk kelas baru.
                */

                $stmt =
                    $pdo->prepare("
                        UPDATE student_enrollments

                        SET status = 'graduated'

                        WHERE
                            id = (

                                SELECT
                                    source.id

                                FROM (
                                    SELECT id
                                    FROM student_enrollments

                                    WHERE
                                        student_id = ?

                                    AND academic_year_id = ?

                                    LIMIT 1

                                ) AS source
                            )
                    ");

                $stmt->execute([
                    $studentId,
                    $sourceYear
                ]);

                continue;


            } elseif (
                $action === 'transfer'
            ) {

                $stmt =
                    $pdo->prepare("
                        UPDATE student_enrollments

                        SET status = 'transferred'

                        WHERE
                            id = (

                                SELECT
                                    source.id

                                FROM (
                                    SELECT id
                                    FROM student_enrollments

                                    WHERE
                                        student_id = ?

                                    AND academic_year_id = ?

                                    LIMIT 1

                                ) AS source
                            )
                    ");

                $stmt->execute([
                    $studentId,
                    $sourceYear
                ]);

                continue;


            } else {

                /*
                | Tinggal kelas
                */

                $classId =
                    (int)(
                        $classMap[$studentId]
                        ?? 0
                    );


                if (
                    $classId <= 0
                ) {

                    continue;

                }


                $status =
                    'repeated';

            }


            $stmt =
                $pdo->prepare("
                    INSERT INTO student_enrollments
                    (
                        student_id,
                        academic_year_id,
                        class_group_id,
                        status
                    )
                    VALUES (?, ?, ?, ?)
                ");

            $stmt->execute([

                $studentId,

                $targetYear,

                $classId,

                $status

            ]);

        }


        $pdo->commit();


        flash(
            'success',
            'Proses kenaikan kelas berhasil.'
        );


        redirect(
            'index.php?source_year_id='
            . $sourceYear
            . '&target_year_id='
            . $targetYear
        );

    } catch (Throwable $e) {

        if (
            $pdo->inTransaction()
        ) {

            $pdo->rollBack();

        }

        die(
            $e->getMessage()
        );

    }

}


require '../../includes/header.php';

?>


<div class="card">

<div class="card-header">

<div>

<h3>
    Kenaikan Kelas
</h3>

<small>
    Atur siswa satu per satu sebelum diproses.
</small>

</div>

</div>


<form method="GET">

<div class="filter-grid">


<div class="form-group">

<label>
    Tahun Asal
</label>

<select
    name="source_year_id"
    required
>

<option value="">
    Pilih
</option>

<?php foreach (
    $years as $year
): ?>

<option
    value="<?= $year['id'] ?>"
    <?= $sourceYearId
        == $year['id']
        ? 'selected'
        : ''
    ?>
>

<?= e($year['name']) ?>

</option>

<?php endforeach; ?>

</select>

</div>


<div class="form-group">

<label>
    Tahun Tujuan
</label>

<select
    name="target_year_id"
    required
>

<option value="">
    Pilih
</option>

<?php foreach (
    $years as $year
): ?>

<option
    value="<?= $year['id'] ?>"
    <?= $targetYearId
        == $year['id']
        ? 'selected'
        : ''
    ?>
>

<?= e($year['name']) ?>

</option>

<?php endforeach; ?>

</select>

</div>


<div>

<button
    class="btn btn-primary"
    type="submit"
>
    Tampilkan
</button>

</div>


</div>

</form>

</div>


<?php if (
    $students
): ?>


<form method="POST">
<?= csrfField() ?>

<input
    type="hidden"
    name="source_year_id"
    value="<?= $sourceYearId ?>"
>

<input
    type="hidden"
    name="target_year_id"
    value="<?= $targetYearId ?>"
>


<div class="card">

<div class="table-wrapper">

<table>

<thead>

<tr>

<th></th>

<th>Nama</th>

<th>NIS</th>

<th>Asal</th>

<th>Aksi</th>

<th>Kelas Tujuan</th>

</tr>

</thead>


<tbody>

<?php foreach (
    $students as $student
): ?>

<tr>

<td>

<input
    type="checkbox"
    name="student_ids[]"
    value="<?= $student['id'] ?>"
>


</td>


<td>
    <?= e($student['name']) ?>
</td>


<td>
    <?= e($student['nis']) ?>
</td>


<td>

<?= e($student['grade']) ?>
-
<?= e($student['current_class']) ?>

</td>


<td>

<select
    name="action[<?= $student['id'] ?>]"
>

<option value="promote">
    Naik Kelas
</option>

<option value="repeat">
    Tinggal Kelas
</option>

<option value="graduate">
    Lulus
</option>

<option value="transfer">
    Pindah
</option>

</select>

</td>


<td>

<select
    name="class_group_id[<?= $student['id'] ?>]"
>

<option value="">
    Pilih
</option>

<?php foreach (
    $classGroups as $class
): ?>

<option
    value="<?= $class['id'] ?>"
>

<?= e($class['grade']) ?>
-
<?= e($class['name']) ?>

</option>

<?php endforeach; ?>

</select>

</td>

</tr>

<?php endforeach; ?>

</tbody>

</table>

</div>


<div style="margin-top:20px">

<button
    class="btn btn-primary"
    type="submit"
>
    Proses yang Dipilih
</button>

</div>

</div>

</form>


<?php elseif (
    $sourceYearId
    &&
    $targetYearId
): ?>

<div class="card">

Tidak ada siswa aktif pada tahun asal.

</div>

<?php endif; ?>


<?php require '../../includes/footer.php'; ?>