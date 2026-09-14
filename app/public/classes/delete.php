<?php

require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../config/auth.php';
require_once '../../config/security.php';
require_once '../../includes/functions.php';

requireSuperAdmin();
requireCsrf();


$id =
    (int)($_POST['id'] ?? 0);


/*
|--------------------------------------------------------------------------
| Jangan hapus jika masih digunakan siswa
|--------------------------------------------------------------------------
*/

$stmt =
    $pdo->prepare("
        SELECT COUNT(*)
        FROM student_enrollments
        WHERE class_group_id = ?
    ");

$stmt->execute([
    $id
]);


$count =
    (int)$stmt->fetchColumn();


if ($count > 0) {

    die(
        'Subkelas tidak bisa dihapus karena masih digunakan oleh siswa.'
    );

}


$stmt =
    $pdo->prepare("
        DELETE FROM class_groups
        WHERE id = ?
    ");

$stmt->execute([
    $id
]);

logAudit($pdo, 'delete', 'class_groups', $id);

flash(
    'success',
    'Subkelas berhasil dihapus.'
);


redirect(
    'index.php'
);