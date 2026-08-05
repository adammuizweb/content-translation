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

// ─── Translation picker in Core content editors ───
if (!function_exists('ct_render_editor_translation_picker')) {
    function ct_render_editor_translation_picker(array $post, PDO $pdo): void {
    $id = (int)($post['id'] ?? 0);
    if ($id <= 0) return;

    $locales = ct_enabled_locales($pdo);
    if (empty($locales)) return;

    $base = defined('ADMIN_BASE_PATH') ? ADMIN_BASE_PATH : '/adiwira';
    $editUrl = $base . '/?page=admin/tools/content-translation/edit';
    $translations = ct_translations_for_post($pdo, $id);

    echo '<section class="ct-editor-translation-control">';
    echo '<label for="ct-editor-translation-locale">' . htmlspecialchars(__('Translations'), ENT_QUOTES) . '</label>';
    echo '<select id="ct-editor-translation-locale" onchange="if(this.value) window.location.href=this.value">';
    echo '<option value="">' . htmlspecialchars(__('Choose translation language…'), ENT_QUOTES) . '</option>';
    foreach ($locales as $locale) {
        $translation = $translations[$locale] ?? null;
        $has = $translation !== null;
        $isDraft = $has && ($translation['status'] ?? 'published') === 'draft';
        $label = strtoupper($locale) . ' — ' . ($isDraft ? __('Draft') : ($has ? __('Edit') : __('Add')));
        $url = $editUrl . '&post_id=' . $id . '&locale=' . urlencode($locale);
        echo '<option value="' . htmlspecialchars($url, ENT_QUOTES) . '">' . htmlspecialchars($label, ENT_QUOTES) . '</option>';
    }
    echo '</select>';
    echo '</section>';
    }
}

add_action('editor_mode_before_options', function ($post, $chosenMode) {
    if (!is_array($post)) return;
    if (!in_array((string)($post['type'] ?? ''), ['article', 'page'], true)) return;

    $pdo = $GLOBALS['pdo'] ?? null;
    if (!$pdo instanceof PDO) return;
    ct_render_editor_translation_picker($post, $pdo);
}, 10, 2);

add_action('theme_editor_before_content', function ($theme, $pdo) {
    if (!is_array($theme) || !$pdo instanceof PDO) return;
    if (($theme['type'] ?? '') !== 'theme') return;
    ct_render_editor_translation_picker($theme, $pdo);
}, 10, 2);
