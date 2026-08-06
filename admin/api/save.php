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

if (!in_array($locale, ct_enabled_locales($pdo), true)) {
    echo json_encode(['error' => 'Locale not enabled']);
    return;
}

$stmt = $pdo->prepare("SELECT id FROM posts WHERE id = ? AND is_deleted = 0 LIMIT 1");
$stmt->execute([$postId]);
if (!$stmt->fetchColumn()) {
    echo json_encode(['error' => 'Post not found']);
    return;
}

$title = trim((string)($_POST['title'] ?? ''));
$slug = trim((string)($_POST['slug'] ?? ''));
if ($slug === '' && $title !== '') {
    $slug = function_exists('cms_slugify') ? (string)cms_slugify($title) : strtolower(trim(preg_replace('/[^a-zA-Z0-9]+/', '-', $title), '-'));
}
$slug = preg_replace('/[^a-zA-Z0-9_\-\/]/', '', $slug);

// Ensure translated slug is unique within this locale (excluding this post)
if ($slug !== '') {
    ct_ensure_schema($pdo);
    $chk = $pdo->prepare("SELECT post_id FROM post_translations WHERE locale = ? AND slug = ? AND post_id != ? LIMIT 1");
    $chk->execute([$locale, $slug, $postId]);
    if ($chk->fetchColumn()) {
        echo json_encode(['error' => __('Slug already used by another translation in this locale')]);
        return;
    }
    // Prevent collision with an original slug that would shadow routing
    $chk2 = $pdo->prepare("SELECT id FROM posts WHERE slug = ? AND id != ? AND is_deleted = 0 LIMIT 1");
    $chk2->execute([$slug, $postId]);
    if ($chk2->fetchColumn()) {
        echo json_encode(['error' => __('Slug collides with an existing original slug')]);
        return;
    }
}

$ok = ct_save_translation($pdo, $postId, $locale, [
    'title'   => $title,
    'slug'    => $slug,
    'content' => (string)($_POST['content'] ?? ''),
]);

echo json_encode($ok
    ? ['success' => true, 'message' => __('Translation saved.')]
    : ['error' => __('Save failed.')]);
