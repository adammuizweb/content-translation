<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'POST required']);
    return;
}
$pdo = $GLOBALS['pdo'] ?? null;
if (!$pdo instanceof PDO) {
    http_response_code(500);
    echo json_encode(['error' => 'Database not available']);
    return;
}
if (!function_exists('current_user_role') || current_user_role($pdo) !== 'admin') {
    http_response_code(403);
    echo json_encode(['error' => 'Admin role required']);
    return;
}
if (!function_exists('csrf_check') || !csrf_check((string)($_POST['csrf_token'] ?? ''))) {
    http_response_code(419);
    echo json_encode(['error' => 'Invalid CSRF token']);
    return;
}

$postId = (int)($_POST['post_id'] ?? 0);
$locale = trim((string)($_POST['locale'] ?? ''));
$title = trim((string)($_POST['title'] ?? ''));
$slug = trim((string)($_POST['slug'] ?? ''));
$metaDescription = trim((string)($_POST['meta_description'] ?? ''));
$status = (string)($_POST['status'] ?? 'draft');
$sections = $_POST['sections'] ?? null;
if ($postId <= 0 || $locale === '' || !is_array($sections) || !in_array($locale, ct_enabled_locales($pdo), true)) {
    echo json_encode(['error' => __('Invalid Theme Template, locale, or sections.')]);
    return;
}
$homepage = ct_is_homepage_post($pdo, $postId);
if (!$homepage && $slug === '' && $title !== '') {
    $slug = function_exists('cms_slugify') ? (string)cms_slugify($title) : strtolower(trim((string)preg_replace('/[^a-zA-Z0-9]+/', '-', $title), '-'));
}
$slug = trim((string)preg_replace('#/+#', '/', (string)preg_replace('/[^a-zA-Z0-9_\-\/]/', '', $slug)), '/');
$length = static fn(string $value): int => function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
if ($length($title) > 255 || $length($slug) > 255 || $length($metaDescription) > 320) {
    echo json_encode(['error' => __('Page metadata is too long.')]);
    return;
}
if (!in_array($status, ['draft', 'published'], true)) {
    echo json_encode(['error' => __('Invalid translation status')]);
    return;
}
if ($status === 'published' && ($title === '' || $metaDescription === '' || (!$homepage && $slug === ''))) {
    echo json_encode(['error' => __('Published Theme Section translations require a title, meta description, and slug unless this is the homepage.')]);
    return;
}

$slugLock = '';
try {
    if ($slug !== '') {
        $slugLock = 'ct_slug_' . substr(hash('sha256', $locale . ':' . $slug), 0, 56);
        $lock = $pdo->prepare('SELECT GET_LOCK(?, 5)');
        $lock->execute([$slugLock]);
        if ((int)$lock->fetchColumn() !== 1) throw new RuntimeException(__('Could not reserve the translated slug. Please try again.'));

        $firstSegment = (string)strtok($slug, '/');
        $reservedRoutes = array_merge(
            function_exists('get_posts_list_routes') ? get_posts_list_routes($pdo) : ['artikel'],
            function_exists('get_pages_list_routes') ? get_pages_list_routes($pdo) : ['halaman'],
            function_exists('get_category_routes') ? get_category_routes($pdo) : ['category'],
            ct_enabled_locales($pdo),
            ['author']
        );
        $reserved = in_array($firstSegment, $reservedRoutes, true)
            || preg_match('/^\d{4}$/', $firstSegment) === 1
            || (function_exists('ct_find_directory_page') && ct_find_directory_page($pdo, $slug) !== null);
        $reserved = (bool)apply_filters('content_translation_slug_is_reserved', $reserved, $postId, $locale, $slug, $pdo);
        if ($reserved) throw new InvalidArgumentException(__('Slug uses a reserved public route'));

        $check = $pdo->prepare('SELECT post_id FROM post_translations WHERE locale = ? AND slug = ? AND post_id != ? LIMIT 1');
        $check->execute([$locale, $slug, $postId]);
        if ($check->fetchColumn()) throw new InvalidArgumentException(__('Slug already used by another translation in this locale'));
        $check = $pdo->prepare('SELECT id FROM posts WHERE slug = ? AND id != ? AND is_deleted = 0 LIMIT 1');
        $check->execute([$slug, $postId]);
        if ($check->fetchColumn()) throw new InvalidArgumentException(__('Slug collides with an existing original slug'));
    }

    $result = ct_save_theme_section_package_translation(
        $pdo,
        $postId,
        $locale,
        (string)($_POST['source_fingerprint'] ?? ''),
        (string)($_POST['translation_state'] ?? ''),
        ['title' => $title, 'slug' => $slug, 'meta_description' => $metaDescription, 'status' => $status],
        array_values($sections)
    );
    $row = $pdo->prepare('SELECT * FROM post_translations WHERE post_id = ? AND locale = ? LIMIT 1');
    $row->execute([$postId, $locale]);
    $saved = $row->fetch(PDO::FETCH_ASSOC) ?: null;
    echo json_encode([
        'success' => true,
        'message' => __('Theme Section translation saved.'),
        'source_fingerprint' => $result['source_fingerprint'],
        'translation_state' => ct_translation_row_state_token($saved),
    ]);
} catch (Throwable $e) {
    http_response_code($e instanceof InvalidArgumentException ? 422 : 409);
    echo json_encode(['error' => $e->getMessage()]);
} finally {
    if ($slugLock !== '') {
        try {
            $release = $pdo->prepare('SELECT RELEASE_LOCK(?)');
            $release->execute([$slugLock]);
        } catch (Throwable $e) {
            error_log('[content-translation] slug lock release error: ' . $e->getMessage());
        }
    }
}
