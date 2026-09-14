<?php

session_start();

require_once '../config/database.php';
require_once '../config/security.php';
require_once '../config/config.php';

if (!empty($_SESSION['user_id'])) {

    header(
        'Location: dashboard.php'
    );

    exit;
}


$error = '';


if (
    $_SERVER['REQUEST_METHOD']
    === 'POST'
) {

    $username =
        trim(
            $_POST['username'] ?? ''
        );

    $password =
        $_POST['password'] ?? '';


    if (
        $username === ''
        ||
        $password === ''
    ) {

        $error =
            'Username dan password wajib diisi.';

    } else {

        $stmt = $pdo->prepare("
            SELECT
                id,
                username,
                password,
                name,
                role
            FROM users
            WHERE username = ?
            AND deleted_at IS NULL
            LIMIT 1
        ");

        $stmt->execute([
            $username
        ]);

        $user =
            $stmt->fetch();


        if (
            $user
            &&
            verifyPassword(
                $password,
                $user['password']
            )
        ) {
            // Auto rehash password lama (SHA-256) ke Bcrypt
            if (password_needs_rehash($user['password'], PASSWORD_DEFAULT)) {
                $newHash = hashPassword($password);
                $stmtRehash = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
                $stmtRehash->execute([$newHash, $user['id']]);
            }

            session_regenerate_id(true);

            $_SESSION['user_id'] =
                $user['id'];

            $_SESSION['username'] =
                $user['username'];

            $_SESSION['name'] =
                $user['name'];

            $_SESSION['role'] =
                $user['role'];


            header(
                'Location: dashboard.php'
            );

            exit;

        } else {

            $error =
                'Username atau password salah.';

        }

    }

}

?>

<!DOCTYPE html>

<html lang="id">

<head>

<meta charset="UTF-8">

<meta name="viewport"
content="width=device-width,initial-scale=1">

<title>
Login | AIS ABSENSI
</title>

<link
rel="stylesheet"
href="../assets/css/style.css"
>

</head>


<body class="login-page">

<div class="login-card">

    

     <div class="brand-icon" style="background:transparent; padding:0; display:flex; justify-content:center; align-items:center;">
            <img src="<?= BASE_URL ?>/logo.jpg" alt="Logo" style="width: 40px; height: 40px; border-radius: 8px; object-fit: cover;">
        </div>

    <h1>
        An - Nahl Islamic School
    </h1>

    <p>
        Absensi Sekolah
    </p>


    <?php if ($error): ?>

        <div class="alert alert-danger">

            <?= e($error) ?>

        </div>

    <?php endif; ?>


    <form method="POST">

        <label>
            Username
        </label>

        <input
            type="text"
            name="username"
            autocomplete="username"
            required
        >


        <label>
            Password
        </label>

        <input
            type="password"
            name="password"
            autocomplete="current-password"
            required
        >


        <button type="submit">
            Masuk ke Sistem
        </button>

    </form>

</div>

</body>

</html>