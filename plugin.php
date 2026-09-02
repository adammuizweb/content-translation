<?php
declare(strict_types=1);

// Content Translation — plugin bootstrap

$__ct_dir = __DIR__;

require_once $__ct_dir . '/includes/helpers.php';
require_once $__ct_dir . '/includes/shortcode-presets.php';
require_once $__ct_dir . '/includes/frontend.php';
require_once $__ct_dir . '/includes/theme-section-packages.php';
require_once $__ct_dir . '/includes/admin.php';

unset($__ct_dir);

add_action('theme_zone_item_before_delete', function (int $itemId, PDO $pdo): void {
    if ($itemId <= 0) return;
    ct_ensure_schema($pdo);
    $stmt = $pdo->prepare('DELETE FROM ct_theme_zone_item_translations WHERE theme_zone_item_id = ?');
    if (!$stmt->execute([$itemId])) throw new RuntimeException('Theme Zone translation cleanup failed.');
}, 10, 2);

add_filter('plugin_state_change_preflight', function (array $state, string $name, string $operation): array {
    if (!$state['allowed'] || $name !== 'content-translation' || !in_array($operation, ['disable', 'delete'], true)) return $state;
    $pdo = $GLOBALS['pdo'] ?? null;
    if (!$pdo instanceof PDO || ct_post_authoring_locales_in_use($pdo) === []) return $state;

    return [
        'allowed' => false,
        'message' => __('Content Translation cannot be disabled or uninstalled while localized content workflows exist.'),
    ];
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
    ] as $table) {
        $pdo->exec("DROP TABLE IF EXISTS `{$table}`");
    }

    $stmt = $pdo->prepare('DELETE FROM settings WHERE `key` IN (?, ?, ?, ?, ?, ?)');
    $stmt->execute(['content_translation_locales', 'content_translation_locale_directions', 'content_translation_sitemap_locales', 'content_translation_homepage_sitemap_aliases', 'content_translation_shortcode_ui_seed', 'content_translation_author_locales']);
});
