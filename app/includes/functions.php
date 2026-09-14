<?php

function redirect($url)
{
    header(
        'Location: ' . $url
    );

    exit;
}


function flash(
    $type,
    $message
) {

    $_SESSION['flash'] = [
        'type' => $type,
        'message' => $message
    ];
}


function getFlash()
{
    $flash =
        $_SESSION['flash'] ?? null;

    unset($_SESSION['flash']);

    return $flash;
}


function uploadStudentPhoto($file)
{
    if (
        empty($file) ||
        $file['error'] !== UPLOAD_ERR_OK
    ) {
        return null;
    }

    $allowed = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp'
    ];

    $mime =
        mime_content_type(
            $file['tmp_name']
        );

    if (!isset($allowed[$mime])) {
        return null;
    }

    $extension =
        $allowed[$mime];

    $filename =
        uniqid('student_', true)
        . '.'
        . $extension;

    global $uploadStudentPath;

    if (
        !is_dir($uploadStudentPath)
    ) {

        mkdir(
            $uploadStudentPath,
            0755,
            true
        );
    }

    move_uploaded_file(
        $file['tmp_name'],
        $uploadStudentPath . $filename
    );

    return $filename;
}


/*
|--------------------------------------------------------------------------
| ACCESS CONTROL SISWA
|--------------------------------------------------------------------------
*/

function getStudentAccessCondition($gradeAlias = 'g', $classAlias = 'cg')
{
    if (($_SESSION['role'] ?? '') === 'super_admin') {

        return [
            'condition' => '1 = 1',
            'params' => []
        ];
    }

    return [

        'condition' => "(
            {$gradeAlias}.id IN (
                SELECT grade_id
                FROM admin_grade_permissions
                WHERE user_id = ?
            )

            OR

            {$classAlias}.id IN (
                SELECT class_group_id
                FROM admin_class_permissions
                WHERE user_id = ?
            )
        )",

        'params' => [
            currentUserId(),
            currentUserId()
        ]
    ];
}


/*
|--------------------------------------------------------------------------
| PERIODE LAPORAN
|--------------------------------------------------------------------------
*/

function getReportPeriod()
{
    $preset = $_GET['preset'] ?? 'month';

    $today = new DateTime();

    if ($preset === 'today') {

        return [
            'start' => $today->format('Y-m-d'),
            'end' => $today->format('Y-m-d')
        ];
    }


    if ($preset === 'week') {

        $monday =
            new DateTime(
                'monday this week'
            );

        $sunday =
            new DateTime(
                'sunday this week'
            );

        return [

            'start' =>
                $monday->format('Y-m-d'),

            'end' =>
                $sunday->format('Y-m-d')

        ];
    }


    if ($preset === 'month') {

        return [

            'start' =>
                $today->format('Y-m-01'),

            'end' =>
                $today->format('Y-m-t')

        ];
    }


    $start =
        $_GET['start_date']
        ?? $today->format('Y-m-01');

    $end =
        $_GET['end_date']
        ?? $today->format('Y-m-d');


    return [

        'start' =>
            preg_match(
                '/^\d{4}-\d{2}-\d{2}$/',
                $start
            )
            ? $start
            : $today->format('Y-m-01'),

        'end' =>
            preg_match(
                '/^\d{4}-\d{2}-\d{2}$/',
                $end
            )
            ? $end
            : $today->format('Y-m-d')

    ];
}


/*
|--------------------------------------------------------------------------
| URL QUERY STRING
|--------------------------------------------------------------------------
*/

function buildQuery(array $params = [])
{
    return http_build_query(
        array_merge(
            $_GET,
            $params
        )
    );
}

/*
|--------------------------------------------------------------------------
| AUDIT LOGGING HELPER
|--------------------------------------------------------------------------
*/

function logAudit($pdo, $action, $entityType, $entityId = null, $details = [])
{
    try {
        $userId = currentUserId() ?? null;
        $username = $_SESSION['username'] ?? 'system';
        $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        $userAgent = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500);
        $detailsJson = !empty($details) ? json_encode($details, JSON_UNESCAPED_UNICODE) : null;

        $stmt = $pdo->prepare("
            INSERT INTO audit_logs (user_id, username, action, entity_type, entity_id, details, ip_address, user_agent, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([$userId, $username, $action, $entityType, $entityId, $detailsJson, $ip, $userAgent]);
    } catch (\Throwable $e) {
        error_log('Audit log error: ' . $e->getMessage());
    }
}

/*
|--------------------------------------------------------------------------
| PAGINATION RENDERER
|--------------------------------------------------------------------------
*/

function renderPagination($currentPage, $totalPages, array $queryParams = [])
{
    if ($totalPages <= 1) {
        return '';
    }

    $html = '<div class="pagination" style="display:flex; gap:6px; align-items:center; justify-content:center; margin-top:20px; flex-wrap:wrap;">';

    if ($currentPage > 1) {
        $prevUrl = '?' . http_build_query(array_merge($queryParams, ['page' => $currentPage - 1]));
        $html .= '<a href="' . e($prevUrl) . '" class="btn" style="padding:6px 12px; background:#f1f5f9; color:#334155; border:1px solid #cbd5e1; text-decoration:none; border-radius:6px;">&laquo; Sebelumnya</a>';
    }

    $start = max(1, $currentPage - 2);
    $end = min($totalPages, $currentPage + 2);

    if ($start > 1) {
        $firstUrl = '?' . http_build_query(array_merge($queryParams, ['page' => 1]));
        $html .= '<a href="' . e($firstUrl) . '" class="btn" style="padding:6px 12px; background:#f1f5f9; color:#334155; border:1px solid #cbd5e1; text-decoration:none; border-radius:6px;">1</a>';
        if ($start > 2) {
            $html .= '<span style="padding:6px 4px; color:#94a3b8;">...</span>';
        }
    }

    for ($p = $start; $p <= $end; $p++) {
        $url = '?' . http_build_query(array_merge($queryParams, ['page' => $p]));
        $activeStyle = ($p === (int)$currentPage)
            ? 'background:#0284c7; color:#ffffff; border-color:#0284c7; font-weight:bold;'
            : 'background:#ffffff; color:#334155; border:1px solid #cbd5e1;';
        $html .= '<a href="' . e($url) . '" class="btn" style="padding:6px 12px; border-radius:6px; text-decoration:none; ' . $activeStyle . '">' . $p . '</a>';
    }

    if ($end < $totalPages) {
        if ($end < $totalPages - 1) {
            $html .= '<span style="padding:6px 4px; color:#94a3b8;">...</span>';
        }
        $lastUrl = '?' . http_build_query(array_merge($queryParams, ['page' => $totalPages]));
        $html .= '<a href="' . e($lastUrl) . '" class="btn" style="padding:6px 12px; background:#f1f5f9; color:#334155; border:1px solid #cbd5e1; text-decoration:none; border-radius:6px;">' . $totalPages . '</a>';
    }

    if ($currentPage < $totalPages) {
        $nextUrl = '?' . http_build_query(array_merge($queryParams, ['page' => $currentPage + 1]));
        $html .= '<a href="' . e($nextUrl) . '" class="btn" style="padding:6px 12px; background:#f1f5f9; color:#334155; border:1px solid #cbd5e1; text-decoration:none; border-radius:6px;">Berikutnya &raquo;</a>';
    }

    $html .= '</div>';
    return $html;
}