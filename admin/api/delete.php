<?php
declare(strict_types=1);
header('Content-Type: application/json');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    echo json_encode(['error' => 'POST required']);
    return;
}

$pdo = $GLOBALS['pdo'] ?? null;
if (!$pdo) { echo json_encode(['error' => 'Database not available']); return; }

if (!function_exists('csrf_check') || !csrf_check((string)($_POST['csrf_token'] ?? ''))) {
    echo json_encode(['error' => 'Invalid CSRF token']);
    return;
}

$postId = (int)($_POST['post_id'] ?? 0);
$locale = trim((string)($_POST['locale'] ?? ''));

if ($postId <= 0 || $locale === '') {
    echo json_encode(['error' => 'post_id and locale required']);
    return;
}

$ok = ct_delete_translation($pdo, $postId, $locale);

echo json_encode($ok
    ? ['success' => true]
    : ['error' => __('Delete failed.')]);
