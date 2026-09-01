<?php
declare(strict_types=1);

// Content Translation — admin hooks

// ─── Ensure schema exists when in admin ───
add_action('admin_init', function () {
    $pdo = $GLOBALS['pdo'] ?? null;
    if ($pdo instanceof PDO && ct_user_can_workspace($pdo)) {
        ct_ensure_schema($pdo);
        ct_seed_shortcode_preset_ui_translations($pdo);
    }
});

if (!function_exists('ct_sync_author_locale_post')) {
    function ct_quarantine_authored_post(PDO $pdo, int $postId, ?string $meta = null, array $expected = []): void {
        try {
            $pdo->beginTransaction();
            $where = 'id = ?';
            $whereParams = [$postId];
            foreach (['type', 'title', 'slug', 'content', 'youtube', 'thumbnail', 'meta', 'status', 'created_by', 'created_at', 'updated_at', 'is_deleted'] as $field) {
                if (!array_key_exists($field, $expected)) continue;
                $where .= " AND {$field} <=> ?";
                $whereParams[] = $expected[$field];
            }
            if ($meta !== null) {
                $stmt = $pdo->prepare("UPDATE posts SET meta = ?, status = 'draft' WHERE {$where}");
                $stmt->execute(array_merge([$meta], $whereParams));
            } else {
                $stmt = $pdo->prepare("UPDATE posts SET status = 'draft' WHERE {$where}");
                $stmt->execute($whereParams);
            }
            $pdo->commit();
            if ($stmt->rowCount() === 0) {
                error_log('[content-translation] authored post quarantine skipped because the source changed.');
            }
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('[content-translation] authored post quarantine failed: ' . $error->getMessage());
        }
    }

    function ct_sync_author_locale_post(int $postId, PDO $pdo, bool $isNew, int $attempt = 0): bool {
        $actorId = ct_current_user_id();
        if ($postId <= 0 || $actorId <= 0) return false;

        $stmt = $pdo->prepare("SELECT id, type, title, slug, content, youtube, thumbnail, meta, status, created_by, created_at, updated_at, is_deleted FROM posts WHERE id = ? AND type = 'article' AND is_deleted = 0 LIMIT 1");
        $stmt->execute([$postId]);
        $post = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$post) return false;

        $locale = $isNew ? ct_author_default_locale($pdo, (int)$post['created_by']) : ct_post_authoring_locale($pdo, $post);
        $default = function_exists('content_default_locale') ? content_default_locale() : 'en';
        if ($locale === null || $locale === $default) return true;
        if (!in_array($locale, ct_enabled_locales($pdo), true)) {
            ct_quarantine_authored_post($pdo, $postId, null, $post);
            return false;
        }

        $permission = $isNew ? 'core.posts.create' : 'core.posts.update';
        $context = $isNew ? [] : ['owner_id' => (int)$post['created_by']];
        if (!function_exists('user_can') || !user_can($pdo, $actorId, $permission, $context)) {
            ct_quarantine_authored_post($pdo, $postId, null, $post);
            return false;
        }

        if (!ct_ensure_schema($pdo)) {
            ct_quarantine_authored_post($pdo, $postId, null, $post);
            return false;
        }
        $initialLocale = $locale;
        $initialSlug = (string)$post['slug'];
        $slugLock = ct_translation_slug_lock_name($initialLocale, $initialSlug);
        $quarantineMeta = null;
        $postLocked = false;
        try {
            $lock = $pdo->prepare('SELECT GET_LOCK(?, 5)');
            $lock->execute([$slugLock]);
            if ((int)$lock->fetchColumn() !== 1) throw new RuntimeException('Authored translation slug could not be reserved.');

            $pdo->beginTransaction();
            if (!authorization_lock_actor_permissions($pdo, $actorId)) {
                throw new RuntimeException('Authoring permission state is unavailable.');
            }
            $locked = $pdo->prepare("SELECT id, type, title, slug, content, youtube, thumbnail, meta, status, created_by, created_at, updated_at, is_deleted FROM posts WHERE id = ? AND type = 'article' AND is_deleted = 0 LIMIT 1 FOR UPDATE");
            $locked->execute([$postId]);
            $post = $locked->fetch(PDO::FETCH_ASSOC);
            if (!$post) throw new RuntimeException('Authored post is no longer available.');
            $postLocked = true;
            if (!authorization_lock_owner_contexts($pdo, [(int)$post['created_by']])
                || !user_can($pdo, $actorId, $permission, $isNew ? [] : ['owner_id' => (int)$post['created_by']])) {
                throw new RuntimeException('Post authoring permission changed.');
            }

            $lockedLocale = $isNew ? ct_author_default_locale($pdo, (int)$post['created_by']) : ct_post_authoring_locale($pdo, $post);
            if ($lockedLocale !== $initialLocale || (string)$post['slug'] !== $initialSlug) {
                $pdo->rollBack();
                $release = $pdo->prepare('SELECT RELEASE_LOCK(?)');
                $release->execute([$slugLock]);
                $slugLock = '';
                if ($attempt < 2) return ct_sync_author_locale_post($postId, $pdo, $isNew, $attempt + 1);
                throw new RuntimeException('Authored post changed while reserving its translated slug.');
            }
            $locale = $lockedLocale;
            if ($locale === $default || !in_array($locale, ct_enabled_locales($pdo), true)) {
                throw new RuntimeException('Post authoring locale changed.');
            }
            $translationStatus = (string)$post['status'] === 'published' ? 'published' : 'draft';
            if ($translationStatus === 'published'
                && !user_can($pdo, $actorId, 'core.posts.publish', ['owner_id' => (int)$post['created_by']])) {
                throw new RuntimeException('Translation publish permission changed.');
            }

            $meta = is_string($post['meta'] ?? null) && $post['meta'] !== ''
                ? json_decode((string)$post['meta'], true)
                : [];
            $metaDescription = is_array($meta) ? trim((string)($meta['meta_tags']['description'] ?? '')) : '';

            $marker = ct_post_meta_with_authoring_locale($post, $locale);
            $quarantineMeta = $marker;
            if (ct_translation_slug_conflict($pdo, $postId, $locale, (string)$post['slug']) !== null) {
                throw new RuntimeException('Authored translation slug conflicts with existing localized content.');
            }
            $update = $pdo->prepare('UPDATE posts SET meta = ? WHERE id = ?');
            if (!$update->execute([$marker, $postId])) throw new RuntimeException('Post locale marker could not be saved.');

            if (!ct_save_translation($pdo, $postId, $locale, [
                'title' => (string)$post['title'],
                'slug' => (string)$post['slug'],
                'content' => (string)$post['content'],
                'meta_description' => $metaDescription,
                'status' => $translationStatus,
            ])) {
                throw new RuntimeException('Authored translation could not be saved.');
            }
            $pdo->commit();
            return true;
        } catch (Throwable $error) {
            error_log('[content-translation] author locale sync failed: ' . $error->getMessage());
            $quarantined = false;
            if ($pdo->inTransaction() && $postLocked) {
                try {
                    if ($quarantineMeta !== null) {
                        $quarantine = $pdo->prepare("UPDATE posts SET meta = ?, status = 'draft' WHERE id = ?");
                        $quarantine->execute([$quarantineMeta, $postId]);
                    } else {
                        $quarantine = $pdo->prepare("UPDATE posts SET status = 'draft' WHERE id = ?");
                        $quarantine->execute([$postId]);
                    }
                    $pdo->commit();
                    $quarantined = true;
                } catch (Throwable $quarantineError) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    error_log('[content-translation] in-transaction quarantine failed: ' . $quarantineError->getMessage());
                }
            }
            if (!$quarantined) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                ct_quarantine_authored_post($pdo, $postId, $quarantineMeta, is_array($post) ? $post : []);
            }
            return false;
        } finally {
            try {
                if ($slugLock !== '') {
                    $release = $pdo->prepare('SELECT RELEASE_LOCK(?)');
                    $release->execute([$slugLock]);
                }
            } catch (Throwable $error) {
                error_log('[content-translation] authored slug lock release failed: ' . $error->getMessage());
            }
        }
    }
}

add_action('admin_post_after_add', function ($postId, $pdo): void {
    if ($pdo instanceof PDO && !ct_sync_author_locale_post((int)$postId, $pdo, true)) {
        throw new RuntimeException('Authored-language synchronization failed; the post was retained as a draft.');
    }
}, 10, 2);

add_action('admin_post_after_edit', function ($postId, $pdo): void {
    if ($pdo instanceof PDO && !ct_sync_author_locale_post((int)$postId, $pdo, false)) {
        throw new RuntimeException('Authored-language synchronization failed; the post was retained as a draft.');
    }
}, 10, 2);

add_action('admin_footer', function (): void {
    static $rendered = false;
    if ($rendered) return;

    $page = trim((string)($_GET['page'] ?? ''), '/');
    if (!in_array($page, ['admin/posts/add', 'admin/posts/edit'], true)) return;
    $pdo = $GLOBALS['pdo'] ?? null;
    if (!$pdo instanceof PDO) return;
    $default = function_exists('content_default_locale') ? content_default_locale() : 'en';
    $formId = 'post-add-form';
    if ($page === 'admin/posts/edit') {
        $postId = (int)($_GET['id'] ?? 0);
        $stmt = $pdo->prepare("SELECT id, meta FROM posts WHERE id = ? AND type = 'article' AND is_deleted = 0 LIMIT 1");
        $stmt->execute([$postId]);
        $post = $stmt->fetch(PDO::FETCH_ASSOC);
        $locale = $post ? ct_post_authoring_locale($pdo, $post) : null;
        $formId = 'post-edit-form';
    } else {
        $locale = ct_author_default_locale($pdo, ct_current_user_id());
    }
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
