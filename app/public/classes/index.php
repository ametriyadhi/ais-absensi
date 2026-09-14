<?php

require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../config/auth.php';
require_once '../../config/security.php';
require_once '../../includes/functions.php';

requireSuperAdmin();

$pageTitle = 'Subkelas';


$classGroups =
    $pdo->query("
        SELECT
            cg.*,
            g.grade

        FROM class_groups cg

        INNER JOIN grades g
            ON g.id = cg.grade_id

        ORDER BY
            g.sort_order,
            cg.name
    ")->fetchAll();


require '../../includes/header.php';

?>


<div class="card">
<div class="card-header">
<h3 style="display: flex; align-items: center; gap: 8px;">
    <i class="fa-solid fa-shapes" style="color: var(--primary);"></i>
    Data Subkelas
</h3>
<div style="display: flex; gap: 10px; flex-wrap: wrap;">
    <a href="import.php" class="btn btn-success" style="background-color: #10b981; color: white;">
        <i class="fa-solid fa-file-excel"></i> Import Subkelas
    </a>
    <a href="create.php" class="btn btn-primary">
        <i class="fa-solid fa-plus"></i> Tambah Subkelas
    </a>
</div>
</div>


<div class="table-wrapper">

<table>

<thead>

<tr>

<th>No</th>

<th>Grade</th>

<th>Subkelas</th>

<th>Aksi</th>

</tr>

</thead>


<tbody>

<?php foreach (
    $classGroups as $index => $class
): ?>

<tr>

<td>
    <?= $index + 1 ?>
</td>

<td>
    <?= e($class['grade']) ?>
</td>

<td>
    <?= e($class['name']) ?>
</td>

<td>

<a
    href="edit.php?id=<?= $class['id'] ?>"
    class="btn btn-success"
>
    Edit
</a>


<form
    action="delete.php"
    method="POST"
    style="display:inline"
    onsubmit="
        return confirm(
            'Hapus subkelas ini?'
        )
    "
>
<?= csrfField() ?>
<input
    type="hidden"
    name="id"
    value="<?= $class['id'] ?>"
>

<button
    class="btn btn-danger"
>
    Hapus
</button>

</form>

</td>

</tr>

<?php endforeach; ?>

</tbody>

</table>

</div>

</div>


<?php require '../../includes/footer.php'; ?>