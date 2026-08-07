<?php
declare(strict_types=1);

// Content Translation — plugin bootstrap

$__ct_dir = __DIR__;

require_once $__ct_dir . '/includes/helpers.php';
require_once $__ct_dir . '/includes/frontend.php';
require_once $__ct_dir . '/includes/admin.php';

unset($__ct_dir);

add_action('plugin_uninstall', function (string $name): void {
    if ($name !== 'content-translation') return;

    $pdo = $GLOBALS['pdo'] ?? null;
    if (!$pdo instanceof PDO) return;

    foreach ([
        'post_translations',
        'category_translations',
        'menu_item_translations',
        'sidebar_item_translations',
        'author_profile_translations',
        'site_translations',
    ] as $table) {
        $pdo->exec("DROP TABLE IF EXISTS `{$table}`");
    }

    $stmt = $pdo->prepare('DELETE FROM settings WHERE `key` IN (?, ?, ?)');
    $stmt->execute(['content_translation_locales', 'content_translation_locale_directions', 'content_translation_sitemap_locales']);
});
