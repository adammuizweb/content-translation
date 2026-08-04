<?php
declare(strict_types=1);

// Content Translation — admin hooks

// ─── Ensure schema exists when in admin ───
add_action('admin_init', function () {
    $pdo = $GLOBALS['pdo'] ?? null;
    if ($pdo instanceof PDO) {
        ct_ensure_schema($pdo);
    }
});

// ─── Translations panel on post/page edit screen ───
add_action('editor_mode_after_areas', function ($post, $chosenMode) {
    if (!is_array($post)) return;
    $id = (int)($post['id'] ?? 0);
    if ($id <= 0) return;

    $pdo = $GLOBALS['pdo'] ?? null;
    if (!$pdo instanceof PDO) return;

    $type = (string)($post['type'] ?? 'article');
    if (!in_array($type, ['article', 'page'], true)) return;

    $locales = ct_enabled_locales($pdo);
    if (empty($locales)) return;

    $base = defined('ADMIN_BASE_PATH') ? ADMIN_BASE_PATH : '/adiwira';
    $editUrl = $base . '/?page=admin/tools/content-translation/edit';
    $translations = ct_translations_for_post($pdo, $id);

    echo '<div class="ct-edit-panel">';
    echo '<h3>' . htmlspecialchars(__('Translations'), ENT_QUOTES) . '</h3>';
    echo '<ul>';
    foreach ($locales as $locale) {
        $translation = $translations[$locale] ?? null;
        $has = $translation !== null;
        $isDraft = $has && ($translation['status'] ?? 'published') === 'draft';
        $label = strtoupper($locale) . ' — ' . ($isDraft ? __('Draft') : ($has ? __('Edit') : __('Add')));
        $cls = $has && !$isDraft ? 'ct-status ct-status-done' : 'ct-status ct-status-empty';
        echo '<li><a class="' . $cls . '" href="' . htmlspecialchars($editUrl . '&post_id=' . $id . '&locale=' . urlencode($locale), ENT_QUOTES) . '">'
            . htmlspecialchars($label, ENT_QUOTES) . '</a></li>';
    }
    echo '</ul>';
    echo '</div>';
}, 10, 2);
