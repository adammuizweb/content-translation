<?php
declare(strict_types=1);

// Content Translation — admin hooks

// ─── Ensure schema exists when in admin ───
add_action('admin_init', function () {
    $pdo = $GLOBALS['pdo'] ?? null;
    if (!$pdo instanceof PDO || !ct_user_can_workspace($pdo)) return;
    ct_ensure_schema($pdo);
    ct_seed_shortcode_preset_ui_translations($pdo);

    $page = trim((string)($_GET['page'] ?? ''), '/');
    $types = [
        'admin/posts/edit' => 'article',
        'admin/pages/edit' => 'page',
    ];
    if (!isset($types[$page])) return;

    $postId = (int)($_GET['id'] ?? 0);
    if ($postId <= 0) return;
    $stmt = $pdo->prepare('SELECT id, type, meta, status, created_by FROM posts WHERE id = ? AND type = ? AND is_deleted = 0 LIMIT 1');
    $stmt->execute([$postId, $types[$page]]);
    $post = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$post || !ct_user_can_translate_post($pdo, $post, 'update')) return;

    $locale = ct_author_default_locale($pdo, ct_current_user_id());
    if ($locale === ct_post_source_locale($pdo, $post)
        || !in_array($locale, ct_post_translation_locales($pdo, $post), true)) {
        return;
    }

    $base = defined('ADMIN_BASE_PATH') ? ADMIN_BASE_PATH : '/adiwira';
    $defaultReturnTo = $base . '/?page=' . ($post['type'] === 'page' ? 'admin/pages/index' : 'admin/posts/index');
    $returnTo = function_exists('adiwira_safe_return_to')
        ? adiwira_safe_return_to($_GET['return_to'] ?? null, $defaultReturnTo)
        : $defaultReturnTo;
    $target = $base . '/?' . http_build_query([
        'page' => 'admin/tools/content-translation/edit',
        'post_id' => $postId,
        'locale' => $locale,
        'return_to' => $returnTo,
    ]);
    header('Location: ' . $target, true, 302);
    exit;
});

if (!function_exists('ct_create_authored_post_workflow')) {
    function ct_create_authored_post_workflow(int $postId, PDO $pdo): void {
        if ($postId <= 0 || ct_post_workflow($pdo, $postId) !== null) return;
        $stmt = $pdo->prepare("SELECT id, type, title, slug, content, meta, status, created_by FROM posts WHERE id = ? AND type = 'article' AND is_deleted = 0 LIMIT 1 FOR UPDATE");
        $stmt->execute([$postId]);
        $post = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$post) throw new RuntimeException('Authored post is unavailable.');

        $authorLocale = ct_author_default_locale($pdo, (int)$post['created_by']);
        $sourceLocale = function_exists('content_default_locale') ? content_default_locale() : 'en';
        if ($authorLocale === $sourceLocale) return;
        if (!in_array($authorLocale, ct_enabled_locales($pdo), true)) {
            throw new RuntimeException('Author writing language is unavailable.');
        }
        $slugLock = ct_translation_slug_lock_name($authorLocale, (string)$post['slug']);
        $lock = $pdo->prepare('SELECT GET_LOCK(?, 5)');
        $lock->execute([$slugLock]);
        if ((int)$lock->fetchColumn() !== 1) {
            throw new RuntimeException('Authored translation slug could not be reserved.');
        }
        $GLOBALS['_ct_authored_slug_locks'][$postId] = $slugLock;
        if (ct_translation_slug_conflict($pdo, $postId, $authorLocale, (string)$post['slug']) !== null) {
            throw new RuntimeException('Authored translation slug conflicts with existing localized content.');
        }

        $meta = is_string($post['meta'] ?? null) && $post['meta'] !== ''
            ? json_decode((string)$post['meta'], true)
            : [];
        $metaDescription = is_array($meta) ? trim((string)($meta['meta_tags']['description'] ?? '')) : '';
        if (is_array($meta)) unset($meta['meta_tags']['description']);
        if (($meta['meta_tags'] ?? null) === []) unset($meta['meta_tags']);
        $sourceMeta = $meta === [] ? null : json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $translationStatus = (string)$post['status'] === 'published' ? 'published' : 'draft';

        if (!ct_save_translation($pdo, $postId, $authorLocale, [
            'title' => (string)$post['title'],
            'slug' => (string)$post['slug'],
            'content' => (string)$post['content'],
            'meta_description' => $metaDescription,
            'status' => $translationStatus,
        ]) || !ct_save_post_workflow($pdo, $postId, $sourceLocale, $authorLocale, 'draft')) {
            throw new RuntimeException('Authored translation workflow could not be saved.');
        }

        $placeholderSlug = ct_pending_source_slug($pdo, $postId, $sourceLocale);
        $update = $pdo->prepare('UPDATE posts SET title = ?, slug = ?, content = ?, meta = ?, status = ? WHERE id = ?');
        if (!$update->execute([
            '[' . strtoupper($sourceLocale) . ' translation pending]',
            $placeholderSlug,
            '',
            $sourceMeta,
            ct_post_effective_status($pdo, $postId, 'draft'),
            $postId,
        ])) {
            throw new RuntimeException('Canonical source draft could not be initialized.');
        }
    }

    function ct_update_authored_post_source(int $postId, PDO $pdo, array $input): void {
        $workflow = ct_post_workflow($pdo, $postId);
        if (!$workflow) return;
        $actorId = ct_current_user_id();
        if (!ct_user_is_site_owner($pdo, $actorId)
            && ct_author_default_locale($pdo, $actorId) !== (string)$workflow['source_locale']) {
            throw new RuntimeException('Only the canonical-language editor may update this source.');
        }
        $sourceStatus = (string)($input['status'] ?? 'draft');
        if (!ct_update_post_source_status($pdo, $postId, $sourceStatus)) {
            throw new RuntimeException('Canonical source status could not be updated.');
        }
    }
}

add_action('admin_post_before_add_commit', function ($postId, $pdo): void {
    if (!$pdo instanceof PDO) return;
    ct_create_authored_post_workflow((int)$postId, $pdo);
}, 10, 2);

add_action('admin_post_before_edit_commit', function ($postId, $pdo, $input): void {
    if (!$pdo instanceof PDO || !is_array($input)) return;
    ct_update_authored_post_source((int)$postId, $pdo, $input);
}, 10, 3);

add_action('admin_post_after_add', function ($postId, $pdo): void {
    $lockName = $GLOBALS['_ct_authored_slug_locks'][(int)$postId] ?? null;
    unset($GLOBALS['_ct_authored_slug_locks'][(int)$postId]);
    if (!$pdo instanceof PDO || !is_string($lockName) || $lockName === '') return;
    $release = $pdo->prepare('SELECT RELEASE_LOCK(?)');
    $release->execute([$lockName]);
}, 1, 2);

add_filter('admin_post_editor_status', function ($status, $post, $pdo) {
    if (!$pdo instanceof PDO || !is_array($post)) return $status;
    $workflow = ct_post_workflow($pdo, (int)($post['id'] ?? 0));
    return $workflow ? (string)$workflow['source_status'] : $status;
}, 10, 3);

add_filter('site_settings_validation_errors', function ($errors, $pdo, $input, $context) {
    if (!is_array($errors) || !$pdo instanceof PDO || !is_array($context)) return $errors;
    $current = function_exists('content_default_locale') ? content_default_locale() : 'en';
    $requested = trim((string)($context['content_default_language'] ?? $current));
    if ($requested === '' || $requested === $current) return $errors;
    if (ct_post_authoring_locales_in_use($pdo) !== []) {
        $errors[] = __('Content default language cannot change while localized article workflows exist.');
    }
    return $errors;
}, 10, 4);

add_action('admin_posts_bulk_before_mutation', function ($action, $posts, $pdo): void {
    if (!$pdo instanceof PDO || !is_array($posts) || !in_array($action, ['change_status', 'change_author'], true)) return;
    foreach ($posts as $post) {
        if (is_array($post) && ct_post_workflow($pdo, (int)($post['id'] ?? 0)) !== null) {
            throw new RuntimeException('Localized workflow status and ownership must be changed in its language editor.');
        }
    }
}, 10, 3);

add_action('admin_footer', function (): void {
    static $rendered = false;
    if ($rendered) return;

    $page = trim((string)($_GET['page'] ?? ''), '/');
    if ($page !== 'admin/posts/add') return;
    $pdo = $GLOBALS['pdo'] ?? null;
    if (!$pdo instanceof PDO) return;
    $default = function_exists('content_default_locale') ? content_default_locale() : 'en';
    $formId = 'post-add-form';
    $locale = ct_author_default_locale($pdo, ct_current_user_id());
    if (!is_string($locale) || $locale === '' || $locale === $default) return;
    $rendered = true;

    $title = __('Writing language') . ': ' . strtoupper($locale);
    $message = sprintf(
        __('This article will be saved for /%s/. The site default language remains %s.'),
        $locale,
        strtoupper($default)
    );
    echo '<script>(function(){var form=document.getElementById(' . json_encode($formId) . ');if(!form)return;var notice=document.createElement("div");notice.className="ct-author-language-notice";notice.innerHTML="<strong>"+' . json_encode($title, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . '+"</strong><span>"+' . json_encode($message, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . '+"</span>";form.prepend(notice)})()</script>';
});

// Show each dashboard user the published representation for their configured
// writing locale while retaining Core's source row as the fallback.
add_filter('post_list_join', function (string $join): string {
    $pdo = $GLOBALS['pdo'] ?? null;
    if (!$pdo instanceof PDO) return $join;
    $locale = ct_author_default_locale($pdo, ct_current_user_id());
    $default = function_exists('content_default_locale') ? content_default_locale() : 'en';
    $localeSql = $pdo->quote($locale);
    $translationCondition = $locale === $default ? ' AND 1 = 0' : '';

    return $join . " LEFT JOIN ct_post_workflows ct_post_list_workflow
        ON ct_post_list_workflow.post_id = p.id
        LEFT JOIN post_translations ct_post_list_display
        ON ct_post_list_display.post_id = p.id
        AND ct_post_list_display.locale = {$localeSql}
        AND TRIM(ct_post_list_display.title) <> ''
        AND TRIM(ct_post_list_display.slug) <> ''{$translationCondition}";
});

add_filter('post_list_select', function (string $select): string {
    $pdo = $GLOBALS['pdo'] ?? null;
    if (!$pdo instanceof PDO) return $select;
    $locale = ct_author_default_locale($pdo, ct_current_user_id());
    $localeSql = $pdo->quote($locale);

    return $select . ",
        CASE WHEN ct_post_list_display.post_id IS NOT NULL THEN ct_post_list_display.title ELSE p.title END AS title,
        CASE WHEN ct_post_list_display.post_id IS NOT NULL THEN ct_post_list_display.slug ELSE p.slug END AS slug,
        CASE
            WHEN {$localeSql} = ct_post_list_workflow.source_locale THEN ct_post_list_workflow.source_status
            WHEN ct_post_list_display.post_id IS NOT NULL THEN ct_post_list_display.status
            ELSE p.status
        END AS status,
        CASE WHEN ct_post_list_display.post_id IS NOT NULL THEN {$localeSql} ELSE NULL END AS ct_locale,
        CASE WHEN ct_post_list_display.post_id IS NOT NULL THEN ct_post_list_display.slug ELSE NULL END AS ct_translated_slug";
});

add_action('site_settings_after_general', function ($pdo) {
    if (!$pdo instanceof PDO || !ct_user_can_workspace($pdo)
        || !user_can($pdo, ct_current_user_id(), 'core.settings.manage')) return;
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

add_filter('post_list_status_expression', function ($expression) {
    $pdo = $GLOBALS['pdo'] ?? null;
    if (!$pdo instanceof PDO) return $expression;
    $localeSql = $pdo->quote(ct_author_default_locale($pdo, ct_current_user_id()));
    return "CASE
        WHEN {$localeSql} = ct_post_list_workflow.source_locale THEN ct_post_list_workflow.source_status
        WHEN ct_post_list_display.post_id IS NOT NULL THEN ct_post_list_display.status
        ELSE p.status
    END";
}, 10, 1);

add_filter('post_list_search_condition', function ($condition) {
    $pdo = $GLOBALS['pdo'] ?? null;
    if (!$pdo instanceof PDO) return $condition;
    return '(CASE WHEN ct_post_list_display.post_id IS NOT NULL THEN ct_post_list_display.title ELSE p.title END LIKE :search
        OR CASE WHEN ct_post_list_display.post_id IS NOT NULL THEN ct_post_list_display.slug ELSE p.slug END LIKE :search)';
}, 10, 1);

add_action('site_settings_after_save', function ($pdo, $input) {
    if (!$pdo instanceof PDO || !is_array($input) || !ct_user_can_workspace($pdo)
        || !user_can($pdo, ct_current_user_id(), 'core.settings.manage')) return;
    foreach (ct_enabled_locales($pdo) as $locale) {
        ct_save_site_translation($pdo, $locale, trim((string)($input['ct_site_title'][$locale] ?? '')), trim((string)($input['ct_site_description'][$locale] ?? '')));
    }
}, 10, 2);

// ─── Translation picker in Core content editors ───
if (!function_exists('ct_render_editor_translation_picker')) {
    function ct_render_editor_translation_picker(array $post, PDO $pdo): void {
        if (!ct_user_can_translate_post($pdo, $post, 'update')) return;
        $id = (int)($post['id'] ?? 0);
        if ($id <= 0) return;

        $locales = ct_post_translation_locales($pdo, $post);
        if (empty($locales)) return;

        $base = defined('ADMIN_BASE_PATH') ? ADMIN_BASE_PATH : '/adiwira';
        $packageComposed = ($post['type'] ?? '') === 'theme'
            && ct_parse_theme_section_composition((string)($post['content'] ?? '')) !== null;
        $editUrl = $base . '/?page=admin/tools/content-translation/' . ($packageComposed ? 'theme-section-edit' : 'edit');
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

add_action('shortcode_layout_editor_after_header', function ($context, $pdo): void {
    if (!is_array($context) || !$pdo instanceof PDO
        || ($context['scope'] ?? '') !== 'section' || !empty($context['is_new'])
        || !ct_user_can_workspace($pdo)
        || !user_can($pdo, ct_current_user_id(), 'core.shortcode_layouts.manage')) {
        return;
    }
    $sectionName = is_string($context['name'] ?? null) ? trim($context['name']) : '';
    if (!function_exists('theme_section_name_is_valid') || !theme_section_name_is_valid($sectionName)) return;

    $base = defined('ADMIN_BASE_PATH') ? ADMIN_BASE_PATH : '/adiwira';
    $editorUrl = is_string($context['editor_url'] ?? null) ? $context['editor_url'] : '';
    $locales = ct_enabled_locales($pdo);
    try {
        $usages = ct_theme_section_template_usages($pdo, $sectionName);
    } catch (Throwable $error) {
        error_log('[content-translation] Theme Section usage lookup failed: ' . $error->getMessage());
        $usages = null;
    }

    echo '<section class="ct-layout-translation-panel">';
    echo '<div class="ct-layout-translation-heading"><div><strong>' . htmlspecialchars(__('Translations'), ENT_QUOTES, 'UTF-8') . '</strong>';
    echo '<span>' . htmlspecialchars(__('Translations belong to each Theme Template that uses this renderer. PHP remains the shared source for every language.'), ENT_QUOTES, 'UTF-8') . '</span></div>';
    echo '<code>' . htmlspecialchars($sectionName, ENT_QUOTES, 'UTF-8') . '</code></div>';
    if ($usages === null) {
        echo '<p class="ct-layout-translation-empty">' . htmlspecialchars(__('Theme Template usage could not be loaded.'), ENT_QUOTES, 'UTF-8') . '</p></section>';
        return;
    }
    if ($usages === []) {
        echo '<p class="ct-layout-translation-empty">' . htmlspecialchars(__('This renderer is not used by a package-composed Theme Template, so it has no translation target yet.'), ENT_QUOTES, 'UTF-8') . '</p></section>';
        return;
    }
    if ($locales === []) {
        echo '<p class="ct-layout-translation-empty">' . htmlspecialchars(__('No translation locales enabled.'), ENT_QUOTES, 'UTF-8') . '</p></section>';
        return;
    }

    echo '<div class="ct-layout-translation-usages">';
    foreach ($usages as $usage) {
        $postId = (int)$usage['id'];
        $resource = ct_theme_section_source_resource($pdo, $usage, false);
        $translations = ct_translations_for_post($pdo, $postId);
        $sourceUrl = $base . '/?page=admin/themes/edit&id=' . $postId;
        echo '<article class="ct-layout-translation-usage"><div class="ct-layout-translation-template">';
        echo '<span>' . htmlspecialchars(__('Used by Theme Template'), ENT_QUOTES, 'UTF-8') . '</span>';
        echo '<a href="' . htmlspecialchars($sourceUrl, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars((string)$usage['title'], ENT_QUOTES, 'UTF-8') . '</a>';
        echo '<code>' . htmlspecialchars((string)$usage['slug'], ENT_QUOTES, 'UTF-8') . '</code></div>';
        echo '<div class="ct-layout-translation-locales">';
        foreach ($locales as $locale) {
            $translation = $translations[$locale] ?? null;
            $package = $translation ? ct_decode_theme_section_package((string)($translation['content'] ?? '')) : null;
            $packageHasSection = is_array($package) && $resource !== null
                && (string)($package['theme_folder'] ?? '') === (string)$resource['theme_folder']
                && isset($package['sections'][$sectionName]);
            $status = !$translation ? 'empty' : (!$packageHasSection ? 'incomplete' : ((string)($translation['status'] ?? 'draft') === 'published' ? 'published' : 'draft'));
            $label = $status === 'empty' ? __('Add') : ($status === 'incomplete' ? __('Incomplete') : __($status === 'published' ? 'Published' : 'Draft'));
            $sourceState = '';
            if ($translation && $resource !== null) {
                $saved = ct_theme_section_saved_source_fingerprint($pdo, $postId, $locale);
                $sourceState = ct_theme_section_source_state($saved, (string)$resource['source_fingerprint']);
                if ($sourceState === 'stale') $label .= ' / ' . __('Stale source');
                elseif ($sourceState === 'unverified') $label .= ' / ' . __('Unverified source');
            }
            $query = [
                'page' => 'admin/tools/content-translation/theme-section-edit',
                'post_id' => $postId,
                'locale' => $locale,
                'section' => $sectionName,
            ];
            if ($editorUrl !== '') $query['return_to'] = $editorUrl;
            $url = $base . '/?' . http_build_query($query);
            $class = 'ct-layout-locale ct-layout-locale--' . $status;
            if ($resource === null) {
                echo '<span class="' . $class . '" aria-disabled="true"><b>' . htmlspecialchars(strtoupper($locale), ENT_QUOTES, 'UTF-8') . '</b><small>' . htmlspecialchars(__('Unavailable'), ENT_QUOTES, 'UTF-8') . '</small></span>';
            } else {
                echo '<a class="' . $class . '" href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '"><b>' . htmlspecialchars(strtoupper($locale), ENT_QUOTES, 'UTF-8') . '</b><small>' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</small></a>';
            }
        }
        echo '</div></article>';
    }
    echo '</div></section>';
}, 10, 2);

add_action('theme_zone_item_editor_actions', function ($item, $context, $pdo): void {
    if (!is_array($item) || !is_array($context) || !$pdo instanceof PDO
        || !ct_user_can_workspace($pdo) || !ct_user_is_site_owner($pdo)
        || !user_can($pdo, ct_current_user_id(), 'core.themes.manage')) return;
    try {
        $resource = ct_theme_zone_resource_from_row($item);
        if (!$resource) return;
        $locales = array_slice(ct_enabled_locales($pdo), 0, 20);
        if ($locales === []) return;
        $statuses = ct_theme_zone_translation_statuses($pdo, (int)$resource['id']);
        $base = defined('ADMIN_BASE_PATH') ? ADMIN_BASE_PATH : '/adiwira';
        $returnUrl = is_string($context['return_url'] ?? null) ? $context['return_url'] : '';
        echo '<div class="ct-theme-zone-actions" style="margin-top:.8rem;padding-top:.75rem;border-top:1px solid rgba(127,127,127,.18)">';
        echo '<strong style="display:block;margin-bottom:.45rem;font-size:12px">' . htmlspecialchars(__('Translations'), ENT_QUOTES, 'UTF-8') . '</strong><div style="display:flex;flex-wrap:wrap;gap:.35rem">';
        foreach ($locales as $locale) {
            $status = $statuses[$locale] ?? 'empty';
            $label = strtoupper($locale) . ' / ' . __($status === 'empty' ? 'Add' : ucfirst($status));
            $query = ['page' => 'admin/tools/content-translation/theme-zone-edit', 'item_id' => $resource['id'], 'locale' => $locale];
            if ($returnUrl !== '') $query['return_to'] = $returnUrl;
            echo '<a class="btn btn-sm btn-secondary" href="' . htmlspecialchars($base . '/?' . http_build_query($query), ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</a>';
        }
        echo '</div></div>';
    } catch (Throwable $error) {
        error_log('[content-translation] Theme Zone editor actions failed: ' . $error->getMessage());
    }
}, 10, 3);

add_action('category_editor_after_fields', function ($category, $pdo) {
    if (!is_array($category) || !$pdo instanceof PDO) return;
    if (!ct_user_can_workspace($pdo)
        || !user_can($pdo, ct_current_user_id(), 'core.categories.update', ['owner_id' => (int)($category['created_by'] ?? 0)])) return;
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
    if (!ct_user_can_workspace($pdo) || (int)($user['id'] ?? 0) !== ct_current_user_id()
        || !user_can($pdo, ct_current_user_id(), 'core.profile.manage')) return;
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
    if (!ct_user_can_workspace($pdo) || (int)$userId !== ct_current_user_id()
        || !user_can($pdo, ct_current_user_id(), 'core.profile.manage')) return;
    foreach ((array)($input['ct_author_bio'] ?? []) as $locale => $bio) {
        ct_save_author_profile_translation($pdo, (int)$userId, (string)$locale, trim((string)$bio));
    }
}, 10, 3);
