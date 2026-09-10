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

$postIdInput = $_POST['post_id'] ?? 0;
$postId = is_scalar($postIdInput) ? (int)$postIdInput : 0;
$localeInput = $_POST['locale'] ?? '';
$locale = is_scalar($localeInput) ? trim((string)$localeInput) : '';
$stateInput = $_POST['translation_state'] ?? '';
$loadedState = is_string($stateInput) ? trim($stateInput) : '';

if ($postId <= 0 || $locale === '') {
    echo json_encode(['error' => 'post_id and locale required']);
    return;
}
if (preg_match('/\A[a-f0-9]{64}\z/', $loadedState) !== 1) {
    echo json_encode(['error' => __('Editor lock state is invalid. Reload the editor.')]);
    return;
}

$actorId = ct_current_user_id();
$localizedMediaSupported = function_exists('ct_localized_media_supported') && ct_localized_media_supported();
$mediaColumns = $localizedMediaSupported ? ', thumbnail_media_id, thumbnail, youtube' : '';
$stmt = $pdo->prepare("SELECT id, type, content, meta, status, created_by{$mediaColumns} FROM posts WHERE id = ? AND is_deleted = 0 LIMIT 1");
$stmt->execute([$postId]);
$sourcePost = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$sourcePost || !in_array((string)($sourcePost['type'] ?? ''), ['article', 'page', 'theme'], true)) {
    echo json_encode(['error' => 'Post not found']);
    return;
}
if (!in_array($locale, ct_post_translation_locales($pdo, $sourcePost), true)) {
    echo json_encode(['error' => 'Locale not available for this source post']);
    return;
}
if (!ct_user_can_edit_post_locale($pdo, $sourcePost, $locale, $actorId)) {
    http_response_code(404);
    echo json_encode(['error' => 'Post not found']);
    return;
}
if (($sourcePost['type'] ?? '') === 'theme' && ct_parse_theme_section_composition((string)($sourcePost['content'] ?? '')) !== null) {
    echo json_encode(['error' => __('Use the Theme Section editor for this Theme Template.')]);
    return;
}

$homepage = function_exists('ct_homepage_theme_post') ? ct_homepage_theme_post($pdo) : null;
$isHomepage = is_array($homepage) && (int)($homepage['id'] ?? 0) === $postId;
$title = trim((string)($_POST['title'] ?? ''));
$slug = trim((string)($_POST['slug'] ?? ''));
if (!$isHomepage && $slug === '' && $title !== '') {
    $slug = function_exists('cms_slugify') ? (string)cms_slugify($title) : strtolower(trim(preg_replace('/[^a-zA-Z0-9]+/', '-', $title), '-'));
}
$slug = preg_replace('/[^a-zA-Z0-9_\-\/]/', '', $slug);
$slug = trim((string)preg_replace('#/+#', '/', $slug), '/');
$metaDescription = trim((string)($_POST['meta_description'] ?? ''));
$length = static fn(string $value): int => function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
if ($length($title) > 255 || $length($slug) > 255) {
    echo json_encode(['error' => __('Title and slug must not exceed 255 characters')]);
    return;
}
if ($length($metaDescription) > 320) {
    echo json_encode(['error' => __('Meta description must not exceed 320 characters')]);
    return;
}

$existing = ct_get_translation($pdo, $postId, $locale);
$status = (string)($_POST['status'] ?? ($existing['status'] ?? 'published'));
if (!in_array($status, ['draft', 'published'], true)) {
    echo json_encode(['error' => __('Invalid translation status')]);
    return;
}
if (($status === 'published' || (string)($existing['status'] ?? '') === 'published')
    && !ct_user_can_publish_post_translation($pdo, $sourcePost, $locale, $actorId)) {
    http_response_code(403);
    echo json_encode(['error' => __('Publishing translation permission denied.')]);
    return;
}
if ($status === 'published' && ($title === '' || (!$isHomepage && $slug === ''))) {
    echo json_encode(['error' => __('Published translations require a title and slug')]);
    return;
}

$slugLock = '';
try {
    if ($slug !== '') {
        $slugLock = ct_translation_slug_lock_name($locale, $slug);
        $lock = $pdo->prepare('SELECT GET_LOCK(?, 5)');
        $lock->execute([$slugLock]);
        if ((int)$lock->fetchColumn() !== 1) {
            echo json_encode(['error' => __('Could not reserve the translated slug. Please try again.')]);
            return;
        }
    }

    // Ensure translated slug is unique within this locale (excluding this post).
    if ($slug !== '') {
        $firstSegment = (string)strtok($slug, '/');
        $reservedRoutes = array_merge(
            function_exists('get_posts_list_routes') ? get_posts_list_routes($pdo) : ['artikel'],
            function_exists('get_pages_list_routes') ? get_pages_list_routes($pdo) : ['halaman'],
            function_exists('get_category_routes') ? get_category_routes($pdo) : ['category'],
            function_exists('ct_content_locales') ? ct_content_locales($pdo) : ct_enabled_locales($pdo),
            ['author']
        );
        $routeReserved = in_array($firstSegment, $reservedRoutes, true)
            || preg_match('/^\d{4}$/', $firstSegment);
        $routeReserved = (bool)apply_filters(
            'content_translation_slug_is_reserved',
            $routeReserved,
            $postId,
            $locale,
            $slug,
            $pdo
        );
        if ($routeReserved) {
            echo json_encode(['error' => __('Slug uses a reserved public route')]);
            return;
        }

        $slugConflict = ct_translation_slug_conflict($pdo, $postId, $locale, $slug);
        if ($slugConflict === 'route') {
            echo json_encode(['error' => __('Slug conflicts with an existing public route')]);
            return;
        }

        ct_ensure_schema($pdo);
        $chk = $pdo->prepare("SELECT post_id FROM post_translations WHERE locale = ? AND slug = ? AND post_id != ? LIMIT 1");
        $chk->execute([$locale, $slug, $postId]);
        if ($chk->fetchColumn()) {
            echo json_encode(['error' => __('Slug already used by another translation in this locale')]);
            return;
        }
        // Prevent collision with an original slug that would shadow routing.
        $chk2 = $pdo->prepare("SELECT id FROM posts WHERE slug = ? AND id != ? AND is_deleted = 0 LIMIT 1");
        $chk2->execute([$slug, $postId]);
        if ($chk2->fetchColumn()) {
            echo json_encode(['error' => __('Slug collides with an existing original slug')]);
            return;
        }
    }

    $candidate = [
        'post_id'          => $postId,
        'locale'           => $locale,
        'title'            => $title,
        'slug'             => $slug,
        'content'          => ct_sanitize_translation_content($pdo, $sourcePost, (string)($_POST['content'] ?? ''), $actorId),
        'meta_description' => $metaDescription,
        'status'           => $status,
    ];
    if ($status === 'published' && !ct_post_translation_is_complete($pdo, $candidate)) {
        echo json_encode(['error' => __('Published translation is incomplete')]);
        return;
    }

    try {
        $featured = $localizedMediaSupported && in_array((string)$sourcePost['type'], ['article', 'page'], true)
            ? ct_featured_candidate_from_input($pdo, $_POST, $locale, $sourcePost, $status)
            : null;
    } catch (InvalidArgumentException $error) {
        echo json_encode(['error' => __($error->getMessage())]);
        return;
    }

    try {
        $saved = ct_save_translation_locked($pdo, $postId, $locale, $loadedState, $candidate, $actorId, $featured);
        $savedFeatured = $featured !== null ? ct_featured_selection($pdo, $postId, $locale) : null;
        echo json_encode([
            'success' => true,
            'message' => __('Translation saved.'),
            'translation_state' => $featured !== null ? ct_translation_editor_state($saved, $savedFeatured) : ct_translation_row_state_token($saved),
        ]);
    } catch (Throwable $error) {
        error_log('[content-translation] locked save error: ' . $error->getMessage());
        $message = match (true) {
            str_contains($error->getMessage(), 'changed by another editor') => __('This translation was changed by another editor. Reload before saving.'),
            str_contains($error->getMessage(), 'available public media') => __('This media is unavailable, private, or deleted for the selected locale. Save as draft or choose compatible media.'),
            default => __('Save failed.'),
        };
        echo json_encode(['error' => $message]);
    }
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
