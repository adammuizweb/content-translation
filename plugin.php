<?php
declare(strict_types=1);

// Content Translation — plugin bootstrap

$__ct_dir = __DIR__;

require_once $__ct_dir . '/includes/helpers.php';
require_once $__ct_dir . '/includes/media.php';
require_once $__ct_dir . '/includes/shortcode-presets.php';
require_once $__ct_dir . '/includes/frontend.php';
require_once $__ct_dir . '/includes/theme-section-packages.php';
require_once $__ct_dir . '/includes/admin.php';

unset($__ct_dir);

if (function_exists('register_frontend_route') && function_exists('media_serve_public_file')) {
    register_frontend_route('media', static function (PDO $pdo): void {
        $path = trim((string)(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? ''), '/');
        $segments = $path === '' ? [] : explode('/', rawurldecode($path));
        $defaultLocale = content_default_locale();
        $locale = $defaultLocale;
        $localePrefixed = isset($segments[0]) && in_array($segments[0], ct_enabled_locales($pdo), true);
        if ($localePrefixed) $locale = array_shift($segments);
        if (($segments[0] ?? '') !== 'media' || count($segments) !== 2) {
            http_response_code(404);
            return;
        }
        $slug = ct_media_alias_slug((string)$segments[1]);
        if ($slug === '' || $slug !== (string)$segments[1]) {
            http_response_code(404);
            return;
        }
        if ($localePrefixed && $locale === $defaultLocale) {
            header('Location: ' . ct_media_alias_url($defaultLocale, $slug), true, 301);
            return;
        }
        $stmt = $pdo->prepare('SELECT m.* FROM ct_media_aliases a INNER JOIN media m ON m.id = a.media_id WHERE a.locale = ? AND a.slug = ? AND m.is_deleted = 0 LIMIT 1');
        $stmt->execute([$locale, $slug]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row) || !ct_media_is_available($pdo, (int)$row['id'], $locale)) {
            http_response_code(404);
            return;
        }
        if (media_public_file_descriptor($row) !== null) {
            if (!media_serve_public_file($row)) http_response_code(404);
            return;
        }
        $legacyUrl = ct_media_legacy_alias_redirect_url($row);
        if ($legacyUrl !== null) header('Location: ' . $legacyUrl, true, 301);
        else http_response_code(404);
    }, ['match' => 'prefix', 'methods' => ['GET', 'HEAD'], 'priority' => 10]);
}

add_action('theme_zone_item_before_delete', function (int $itemId, PDO $pdo): void {
    if ($itemId <= 0) return;
    ct_ensure_schema($pdo);
    $stmt = $pdo->prepare('DELETE FROM ct_theme_zone_item_translations WHERE theme_zone_item_id = ?');
    if (!$stmt->execute([$itemId])) throw new RuntimeException('Theme Zone translation cleanup failed.');
}, 10, 2);

add_filter('plugin_state_change_preflight', function (array $state, string $name, string $operation): array {
    if (!$state['allowed'] || $name !== 'content-translation' || !in_array($operation, ['disable', 'delete'], true)) return $state;
    $pdo = $GLOBALS['pdo'] ?? null;
    if (!$pdo instanceof PDO) return ['allowed' => false, 'message' => __('Content Translation state could not be verified.')];
    try {
        if (ct_post_authoring_locales_in_use($pdo) !== []) {
            return ['allowed' => false, 'message' => __('Content Translation cannot be disabled or uninstalled while localized content workflows exist.')];
        }
        if (ct_localized_media_state_exists($pdo)) {
            return ['allowed' => false, 'message' => __('Content Translation cannot be disabled or deleted while localized media state exists.')];
        }
        return $state;
    } catch (Throwable $error) {
        return ['allowed' => false, 'message' => __('Content Translation state could not be verified.')];
    }
}, 10, 3);

add_action('plugin_uninstall', function (string $name): void {
    if ($name !== 'content-translation') return;

    $pdo = $GLOBALS['pdo'] ?? null;
    if (!$pdo instanceof PDO) return;

    $ownedSeedTable = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
    $ownedSeedTable->execute(['ct_ui_translation_seeds']);
    if ((int)$ownedSeedTable->fetchColumn() > 0) {
        $pdo->exec('DELETE ui FROM ui_translations ui INNER JOIN ct_ui_translation_seeds owned ON owned.scope = ui.scope AND owned.source = ui.source AND owned.locale = ui.locale AND owned.value = ui.value');
    }

    $pdo->exec("UPDATE posts p INNER JOIN ct_post_workflows w ON w.post_id = p.id SET p.status = w.source_status WHERE p.type IN ('article', 'page') AND p.is_deleted = 0");
    $pdo->exec("UPDATE posts SET status = 'draft', meta = JSON_REMOVE(meta, '$.content_translation.authoring_locale') WHERE type = 'article' AND is_deleted = 0 AND JSON_VALID(meta) AND JSON_EXTRACT(meta, '$.content_translation.authoring_locale') IS NOT NULL");

    foreach ([
        'post_translations',
        'ct_post_workflows',
        'category_translations',
        'menu_item_translations',
        'sidebar_item_translations',
        'author_profile_translations',
        'site_translations',
        'theme_file_translations',
        'ct_theme_zone_item_translations',
        'ct_theme_string_translations',
        'ct_theme_section_translation_meta',
        'shortcode_preset_translations',
        'ct_ui_translation_seeds',
        'ct_user_locale_edit_grants',
        'ct_role_locale_edit_grants',
        'ct_post_featured_media',
        'ct_media_aliases',
        'ct_media_translations',
        'ct_media_available_locales',
        'ct_media_profiles',
    ] as $table) {
        $pdo->exec("DROP TABLE IF EXISTS `{$table}`");
    }

    $stmt = $pdo->prepare('DELETE FROM settings WHERE `key` IN (?, ?, ?, ?, ?, ?)');
    $stmt->execute(['content_translation_locales', 'content_translation_locale_directions', 'content_translation_sitemap_locales', 'content_translation_homepage_sitemap_aliases', 'content_translation_shortcode_ui_seed', 'content_translation_author_locales']);
});
