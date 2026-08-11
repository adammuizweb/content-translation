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

add_action('plugin_uninstall', function (string $name): void {
    if ($name !== 'content-translation') return;

    $pdo = $GLOBALS['pdo'] ?? null;
    if (!$pdo instanceof PDO) return;

    $ownedSeedTable = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
    $ownedSeedTable->execute(['ct_ui_translation_seeds']);
    if ((int)$ownedSeedTable->fetchColumn() > 0) {
        $pdo->exec('DELETE ui FROM ui_translations ui INNER JOIN ct_ui_translation_seeds owned ON owned.scope = ui.scope AND owned.source = ui.source AND owned.locale = ui.locale AND owned.value = ui.value');
    }

    foreach ([
        'post_translations',
        'category_translations',
        'menu_item_translations',
        'sidebar_item_translations',
        'author_profile_translations',
        'site_translations',
        'theme_file_translations',
        'ct_theme_section_translation_meta',
        'shortcode_preset_translations',
        'ct_ui_translation_seeds',
    ] as $table) {
        $pdo->exec("DROP TABLE IF EXISTS `{$table}`");
    }

    $stmt = $pdo->prepare('DELETE FROM settings WHERE `key` IN (?, ?, ?, ?, ?)');
    $stmt->execute(['content_translation_locales', 'content_translation_locale_directions', 'content_translation_sitemap_locales', 'content_translation_homepage_sitemap_aliases', 'content_translation_shortcode_ui_seed']);
});
