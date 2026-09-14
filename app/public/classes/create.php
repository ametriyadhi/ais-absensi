<?php

require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../config/auth.php';
require_once '../../config/security.php';
require_once '../../includes/functions.php';

requireSuperAdmin();

$pageTitle = 'Tambah Subkelas';


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
            INSERT INTO class_groups
            (
                grade_id,
                name
            )
            VALUES (?, ?)
        ");

    $stmt->execute([
        $gradeId,
        $name
    ]);


    flash(
        'success',
        'Subkelas berhasil dibuat.'
    );


    redirect(
        'index.php'
    );
}


require '../../includes/header.php';

?>


<div class="card">

<h3>
    Tambah Subkelas
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

<option value="">
    Pilih Grade
</option>

<?php foreach (
    $grades as $grade
): ?>

<option
    value="<?= $grade['id'] ?>"
>

<?= e($grade['grade']) ?>

</option>

<?php endforeach; ?>

</select>

</div>


<div class="form-group">

<label>
    Nama Subkelas
</label>

<input
    type="text"
    name="name"
    placeholder="Contoh: 7A"
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