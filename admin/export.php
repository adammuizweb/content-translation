<?php
declare(strict_types=1);

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    exit('POST required');
}

if (!function_exists('csrf_check') || !csrf_check((string)($_POST['csrf_token'] ?? ''))) {
    http_response_code(419);
    exit('Invalid CSRF token');
}

$pdo = $GLOBALS['pdo'] ?? null;
if (!$pdo instanceof PDO) {
    http_response_code(500);
    exit('Database not available');
}
if (!function_exists('current_user_role') || current_user_role($pdo) !== 'admin') {
    http_response_code(403);
    exit('Admin role required');
}

try {
    $json = json_encode(ct_export_data($pdo), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="content-translation-backup-' . gmdate('Ymd-His') . '.json"');
    header('Content-Length: ' . strlen($json));
    echo $json;
} catch (Throwable $e) {
    error_log('[content-translation] export error: ' . $e->getMessage());
    http_response_code(500);
    echo 'Export failed';
}
