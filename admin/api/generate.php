<?php
declare(strict_types=1);
header('Content-Type: application/json');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    echo json_encode(['error' => 'POST required']);
    return;
}

$pdo = $GLOBALS['pdo'] ?? null;
if (!$pdo instanceof PDO) { echo json_encode(['error' => 'Database not available']); return; }
if (!function_exists('csrf_check') || !csrf_check((string)($_POST['csrf_token'] ?? ''))) {
    echo json_encode(['error' => 'Invalid CSRF token']);
    return;
}
if (ct_machine_provider($pdo) !== 'libretranslate') {
    echo json_encode(['error' => 'LibreTranslate is not enabled']);
    return;
}

$postId = (int)($_POST['post_id'] ?? 0);
$locale = trim((string)($_POST['locale'] ?? ''));
if ($postId <= 0 || !in_array($locale, ct_enabled_locales($pdo), true)) {
    echo json_encode(['error' => 'Valid post_id and enabled locale required']);
    return;
}

$stmt = $pdo->prepare('SELECT id, title, slug, content FROM posts WHERE id = ? AND is_deleted = 0 LIMIT 1');
$stmt->execute([$postId]);
$post = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$post) { echo json_encode(['error' => 'Post not found']); return; }

$sourceLocale = function_exists('content_default_locale') ? content_default_locale() : (function_exists('default_locale') ? default_locale() : 'en');
$title = ct_libretranslate_request($pdo, (string)$post['title'], $sourceLocale, $locale);
if (!$title['ok']) { echo json_encode(['error' => $title['error']]); return; }
$content = ['ok' => true, 'text' => (string)$post['content']];
if ((string)$post['content'] !== '') $content = ct_libretranslate_request($pdo, (string)$post['content'], $sourceLocale, $locale);
if (!$content['ok']) { echo json_encode(['error' => $content['error']]); return; }

$existing = ct_get_translation($pdo, $postId, $locale);
$ok = ct_save_translation($pdo, $postId, $locale, [
    'title' => $title['text'],
    'slug' => (string)($existing['slug'] ?? ''),
    'content' => $content['text'],
    'status' => 'draft',
    'provider' => 'libretranslate',
    'source_hash' => ct_translation_source_hash($post),
]);

echo json_encode($ok
    ? ['success' => true, 'message' => 'LibreTranslate draft generated. Review it before saving.', 'title' => $title['text'], 'content' => $content['text']]
    : ['error' => 'Draft could not be saved']);
