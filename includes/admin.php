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

add_action('site_settings_after_general', function ($pdo) {
    if (!$pdo instanceof PDO) return;
    $locales = ct_enabled_locales($pdo);
    if (empty($locales)) return;
    $id = 'ct-site-identity';
    echo '<div class="form-group" id="' . $id . '"><label>' . htmlspecialchars(__('Translated site identity'), ENT_QUOTES) . '</label><span class="field-note">' . htmlspecialchars(__('Used for the localized homepage title and description.'), ENT_QUOTES) . '</span>';
    echo '<label style="display:block;margin-top:.6rem">' . htmlspecialchars(__('Language'), ENT_QUOTES) . '<select class="inp inp-w100 ct-site-identity-locale">';
    foreach ($locales as $locale) {
        echo '<option value="' . htmlspecialchars($locale, ENT_QUOTES) . '">' . htmlspecialchars(strtoupper($locale), ENT_QUOTES) . '</option>';
    }
    echo '</select></label>';
    foreach ($locales as $index => $locale) {
        $translation = ct_site_translation($pdo, $locale) ?? [];
        echo '<div data-ct-site-locale="' . htmlspecialchars($locale, ENT_QUOTES) . '" style="display:' . ($index === 0 ? 'block' : 'none') . ';margin-top:.6rem"><input class="inp inp-w100" name="ct_site_title[' . htmlspecialchars($locale, ENT_QUOTES) . ']" value="' . htmlspecialchars((string)($translation['title'] ?? ''), ENT_QUOTES) . '" placeholder="' . htmlspecialchars(__('Site title'), ENT_QUOTES) . '"><textarea class="inp inp-w100" rows="2" style="display:block;margin-top:.6rem" name="ct_site_description[' . htmlspecialchars($locale, ENT_QUOTES) . ']" placeholder="' . htmlspecialchars(__('Site description'), ENT_QUOTES) . '">' . htmlspecialchars((string)($translation['description'] ?? ''), ENT_QUOTES) . '</textarea></div>';
    }
    echo '</div><script>(function(){var box=document.getElementById(' . json_encode($id) . ');if(!box)return;var select=box.querySelector(".ct-site-identity-locale");select.addEventListener("change",function(){box.querySelectorAll("[data-ct-site-locale]").forEach(function(field){field.style.display=field.dataset.ctSiteLocale===select.value?"block":"none"})})})()</script>';
}, 10, 1);

add_action('site_settings_after_save', function ($pdo, $input) {
    if (!$pdo instanceof PDO || !is_array($input)) return;
    foreach (ct_enabled_locales($pdo) as $locale) {
        ct_save_site_translation($pdo, $locale, trim((string)($input['ct_site_title'][$locale] ?? '')), trim((string)($input['ct_site_description'][$locale] ?? '')));
    }
}, 10, 2);

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

add_action('category_editor_after_fields', function ($category, $pdo) {
    if (!is_array($category) || !$pdo instanceof PDO) return;
    $locales = ct_enabled_locales($pdo);
    if (empty($locales)) return;
    $base = defined('ADMIN_BASE_PATH') ? ADMIN_BASE_PATH : '/adiwira';
    echo '<section class="ct-editor-translation-control"><label for="ct-category-translation-locale">' . htmlspecialchars(__('Translations'), ENT_QUOTES) . '</label><select id="ct-category-translation-locale" onchange="if(this.value) window.location.href=this.value"><option value="">' . htmlspecialchars(__('Choose translation language…'), ENT_QUOTES) . '</option>';
    foreach ($locales as $locale) {
        $translation = ct_get_category_translation($pdo, (int)$category['id'], $locale);
        $label = strtoupper($locale) . ' — ' . ($translation ? __('Edit') : __('Add'));
        $url = $base . '/?page=admin/tools/content-translation/category-edit&category_id=' . (int)$category['id'] . '&locale=' . urlencode($locale);
        echo '<option value="' . htmlspecialchars($url, ENT_QUOTES) . '">' . htmlspecialchars($label, ENT_QUOTES) . '</option>';
    }
    echo '</select></section>';
}, 10, 2);

add_action('profile_after_fields', function ($user, $pdo) {
    if (!is_array($user) || !$pdo instanceof PDO) return;
    $locales = ct_enabled_locales($pdo);
    if (empty($locales)) return;
    $id = 'ct-author-bio-' . (int)$user['id'];
    echo '<fieldset id="' . $id . '" style="margin-top:1rem;padding:12px;border:1px solid #ddd;border-radius:6px"><legend>' . htmlspecialchars(__('Bio translations'), ENT_QUOTES) . '</legend>';
    echo '<label>' . htmlspecialchars(__('Translation language'), ENT_QUOTES) . '<select class="ct-author-bio-locale" style="margin-left:8px">';
    foreach ($locales as $locale) {
        echo '<option value="' . htmlspecialchars($locale, ENT_QUOTES) . '">' . htmlspecialchars(strtoupper($locale), ENT_QUOTES) . '</option>';
    }
    echo '</select></label>';
    foreach ($locales as $index => $locale) {
        $translation = ct_get_author_profile_translation($pdo, (int)$user['id'], $locale);
        echo '<label data-locale="' . htmlspecialchars($locale, ENT_QUOTES) . '" style="display:' . ($index === 0 ? 'block' : 'none') . ';margin-top:8px">' . htmlspecialchars(__('Bio / About Me'), ENT_QUOTES) . '<textarea name="ct_author_bio[' . htmlspecialchars($locale, ENT_QUOTES) . ']" rows="4" style="width:100%;padding:.5rem;margin-top:.4rem;border:1px solid #ddd;border-radius:6px">' . htmlspecialchars((string)($translation['bio'] ?? ''), ENT_QUOTES) . '</textarea></label>';
    }
    echo '</fieldset><script>(function(){var box=document.getElementById(' . json_encode($id) . ');if(!box)return;var select=box.querySelector(".ct-author-bio-locale");select.addEventListener("change",function(){box.querySelectorAll("[data-locale]").forEach(function(field){field.style.display=field.dataset.locale===select.value?"block":"none"})})})()</script>';
}, 10, 2);

add_action('profile_after_save', function ($userId, $pdo, $input) {
    if (!$pdo instanceof PDO || !is_array($input)) return;
    foreach ((array)($input['ct_author_bio'] ?? []) as $locale => $bio) {
        ct_save_author_profile_translation($pdo, (int)$userId, (string)$locale, trim((string)$bio));
    }
}, 10, 3);
