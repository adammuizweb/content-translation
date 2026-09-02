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
$translationState = trim((string)($_POST['translation_state'] ?? ''));

if ($postId <= 0 || $locale === '' || preg_match('/\A[a-f0-9]{64}\z/', $translationState) !== 1) {
    echo json_encode(['error' => 'post_id, locale, and translation state required']);
    return;
}

$actorId = ct_current_user_id();
$source = $pdo->prepare("SELECT id, type, created_by FROM posts WHERE id = ? AND type IN ('article', 'page', 'theme') AND is_deleted = 0 LIMIT 1");
$source->execute([$postId]);
$post = $source->fetch(PDO::FETCH_ASSOC);
if (!$post || $locale === ct_post_source_locale($pdo, $post)
    || !ct_user_can_edit_post_locale($pdo, $post, $locale, $actorId)) {
    http_response_code(404);
    echo json_encode(['error' => 'Post not found']);
    return;
}

$ok = ct_delete_translation($pdo, $postId, $locale, $translationState, $actorId);

echo json_encode($ok
    ? ['success' => true]
    : ['error' => __('Delete failed.')]);
