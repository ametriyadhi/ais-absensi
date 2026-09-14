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


$stmt =
    $pdo->prepare("
        UPDATE activities

        SET status = 'closed'

        WHERE id = ?
    ");

$stmt->execute([
    $id
]);

logAudit($pdo, 'close', 'activities', $id);


flash(
    'success',
    'Activity ditutup.'
);


redirect(
    'index.php'
);