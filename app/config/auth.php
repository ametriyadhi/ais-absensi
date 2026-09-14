<?php

require_once __DIR__ . '/config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}


function requireLogin()
{
    if (empty($_SESSION['user_id'])) {
        $loginUrl = defined('BASE_URL') ? BASE_URL . '/login.php' : '/public/login.php';
        header('Location: ' . $loginUrl);
        exit;
    }
}


function requireSuperAdmin()
{
    requireLogin();

    if (
        ($_SESSION['role'] ?? '')
        !== 'super_admin'
    ) {

        http_response_code(403);

        die(
            '403 - Hanya Super Admin yang dapat mengakses halaman ini.'
        );
    }
}


function requireAdmin()
{
    requireLogin();

    if (
        !in_array(
            $_SESSION['role'] ?? '',
            ['super_admin', 'admin']
        )
    ) {

        http_response_code(403);

        die(
            '403 - Anda tidak memiliki akses.'
        );
    }
}


function currentUserId()
{
    return $_SESSION['user_id'] ?? null;
}


function currentRole()
{
    return $_SESSION['role'] ?? null;
}