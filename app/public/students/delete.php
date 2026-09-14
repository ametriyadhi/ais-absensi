<?php

require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../config/auth.php';
require_once '../../config/security.php';
require_once '../../includes/functions.php';

requireSuperAdmin();
requireCsrf();


if (
    $_SERVER['REQUEST_METHOD']
    !== 'POST'
) {

    redirect('index.php');

}


$id =
    (int)($_POST['id'] ?? 0);


if ($id <= 0) {

    redirect('index.php');

}


$stmt =
    $pdo->prepare("
        SELECT name, photo
        FROM students
        WHERE id = ?
        LIMIT 1
    ");

$stmt->execute([
    $id
]);

$student =
    $stmt->fetch();


if ($student) {

    $stmt =
        $pdo->prepare("
            UPDATE students
            SET deleted_at = NOW()
            WHERE id = ?
        ");

    $stmt->execute([
        $id
    ]);

    logAudit($pdo, 'delete', 'students', $id, ['name' => $student['name'] ?? '']);

    flash(
        'success',
        'Siswa berhasil dihapus.'
    );
}


redirect(
    'index.php'
);