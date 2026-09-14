<?php

require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../config/auth.php';
require_once '../../config/security.php';
require_once '../../includes/functions.php';

requireSuperAdmin();

$pageTitle = 'Edit Subkelas';


$id =
    (int)($_GET['id'] ?? 0);


$stmt =
    $pdo->prepare("
        SELECT *
        FROM class_groups
        WHERE id = ?
        LIMIT 1
    ");

$stmt->execute([
    $id
]);

$class =
    $stmt->fetch();


if (!$class) {

    die(
        'Subkelas tidak ditemukan.'
    );

}


$grades =
    $pdo->query("
        SELECT *
        FROM grades
        ORDER BY sort_order, grade
    ")->fetchAll();


if (
    $_SERVER['REQUEST_METHOD']
    === 'POST'
) {

    $gradeId =
        (int)(
            $_POST['grade_id']
            ?? 0
        );

    $name =
        trim(
            $_POST['name'] ?? ''
        );


    $stmt =
        $pdo->prepare("
            UPDATE class_groups

            SET
                grade_id = ?,
                name = ?

            WHERE id = ?
        ");

    $stmt->execute([
        $gradeId,
        $name,
        $id
    ]);


    flash(
        'success',
        'Subkelas berhasil diperbarui.'
    );


    redirect(
        'index.php'
    );
}


require '../../includes/header.php';

?>


<div class="card">

<h3>
    Edit Subkelas
</h3>


<form method="POST">

<div class="form-grid">


<div class="form-group">

<label>
    Grade
</label>

<select
    name="grade_id"
    required
>

<?php foreach (
    $grades as $grade
): ?>

<option
    value="<?= $grade['id'] ?>"
    <?= (
        $class['grade_id']
        == $grade['id']
    )
    ? 'selected'
    : ''
    ?>
>

<?= e($grade['grade']) ?>

</option>

<?php endforeach; ?>

</select>

</div>


<div class="form-group">

<label>
    Subkelas
</label>

<input
    type="text"
    name="name"
    value="<?= e($class['name']) ?>"
    required
>

</div>


</div>


<div style="margin-top:20px">

<button
    class="btn btn-primary"
>
    Simpan
</button>

<a
    href="index.php"
    class="btn btn-success"
>
    Kembali
</a>

</div>

</form>

</div>


<?php require '../../includes/footer.php'; ?>