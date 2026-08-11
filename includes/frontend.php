<?php
declare(strict_types=1);

// Content Translation — frontend hooks

// ─── Routing: strip locale prefix, resolve translated slugs ───
add_filter('router_path', function ($path) {
    $path = (string)$path;
    if ($path === '') return $path;

    $pdo = $GLOBALS['pdo'] ?? null;
    if (!$pdo instanceof PDO) return $path;

    $first = strtok($path, '/');
    if ($first === false) return $path;

    $locales = ct_enabled_locales($pdo);
    if (!in_array($first, $locales, true)) return $path;

    $rest = ltrim(substr($path, strlen($first)), '/');
    if ($rest === '') {
        // Search does not require a translated homepage, but it must retain its locale.
        if (trim((string)($_GET['s'] ?? '')) !== '') {
            if (function_exists('set_locale')) set_locale($first);
            $GLOBALS['ct_request_locale'] = $first;
            return '';
        }
        $homepage = ct_homepage_theme_post($pdo);
        if ($homepage) {
            if (!ct_get_published_translation($pdo, (int)$homepage['id'], $first)) ct_render_not_found();
            if (function_exists('set_locale')) set_locale($first);
            $GLOBALS['ct_request_locale'] = $first;
            $GLOBALS['ct_current_post'] = $homepage;
            $GLOBALS['ct_localized_homepage'] = true;
            return '';
        }

        $resource = ct_homepage_theme_file_resource($pdo);
        $translation = $resource
            ? ct_get_published_theme_file_translation($pdo, (string)$resource['theme_folder'], (string)$resource['slot_key'], $first)
            : null;
        if (!$resource || !$translation) ct_render_not_found();

        if (function_exists('set_locale')) set_locale($first);
        $GLOBALS['ct_request_locale'] = $first;
        $GLOBALS['ct_current_theme_file'] = $resource;
        $GLOBALS['ct_current_theme_file_translation'] = $translation;
        $GLOBALS['ct_theme_file_homepage'] = true;
        return '';
    }

    $listRoutes = array_merge(
        function_exists('get_posts_list_routes') ? get_posts_list_routes($pdo) : ['artikel'],
        function_exists('get_pages_list_routes') ? get_pages_list_routes($pdo) : ['halaman']
    );
    if (function_exists('collection_match_route_base') && collection_match_route_base($rest, $listRoutes) !== null
        || preg_match('#^(author|\d{4})(?:/|$)#', $rest)) {
        if (function_exists('set_locale')) set_locale($first);
        $GLOBALS['ct_request_locale'] = $first;
        return $rest;
    }

    $categoryMatch = function_exists('collection_match_route_base')
        ? collection_match_route_base($rest, get_category_routes($pdo))
        : null;
    if ($categoryMatch !== null) {
        if ($categoryMatch['rest'] === '') {
            if (function_exists('set_locale')) set_locale($first);
            $GLOBALS['ct_request_locale'] = $first;
            return $rest;
        }
        $categoryPath = $categoryMatch['rest'];
        $paginationSuffix = '';
        if (preg_match('#^(.*?)/(?:p|page)/(\d+)$#', $categoryPath, $matches)) {
            $categoryPath = $matches[1];
            $paginationSuffix = '/p/' . $matches[2];
        }
        $resolved = ct_find_category_translation_path($pdo, $first, $categoryPath);
        if (!$resolved) ct_render_not_found();
        if (function_exists('set_locale')) set_locale($first);
        $GLOBALS['ct_request_locale'] = $first;
        return $categoryMatch['base'] . '/' . $resolved['source_path'] . $paginationSuffix;
    }

    // Canonical and alias routes are valid only when the routed post has a
    // reviewed translation for the requested locale.
    if (function_exists('content_route_resolve')) {
        try {
            $localizedRoute = content_route_resolve($pdo, $rest, $first, 'public');
        } catch (Throwable $e) {
            $localizedRoute = null;
        }
        if (is_array($localizedRoute)) {
            if (!ct_get_published_translation($pdo, (int)$localizedRoute['id'], $first)) ct_render_not_found();
            if (function_exists('set_locale')) set_locale($first);
            $GLOBALS['ct_request_locale'] = $first;
            return $rest;
        }
    }

    // A locale URL exists only for a reviewed, published translation.
    $t = ct_find_translation_by_slug($pdo, $first, $rest);
    if (!$t) ct_render_not_found();

    if (function_exists('content_route_resolve')) {
        try {
            $routed = content_route_resolve($pdo, $rest, $first, 'public');
        } catch (Throwable $e) {
            $routed = null;
        }
        if (is_array($routed) && (int)($routed['id'] ?? 0) === (int)$t['post_id']) {
            if (function_exists('set_locale')) set_locale($first);
            $GLOBALS['ct_request_locale'] = $first;
            return $rest;
        }
    }

    $origSlug = ct_original_slug($pdo, (int)$t['post_id']);
    if ($origSlug === null || $origSlug === '') {
        ct_render_not_found();
    }

    if (function_exists('set_locale')) {
        set_locale($first);
    }
    $GLOBALS['ct_request_locale'] = $first;
    return $origSlug;
});

add_filter('content_route_request_locale', function ($locale) {
    return $GLOBALS['ct_request_locale'] ?? $locale;
});

// ─── Content swap: overlay translated fields on the resolved post ───
add_filter('site_title', function ($title, $pdo) {
    $locale = $GLOBALS['ct_request_locale'] ?? null;
    $translation = $locale && $pdo instanceof PDO ? ct_site_translation($pdo, $locale) : null;
    return ($translation['title'] ?? '') !== '' ? $translation['title'] : $title;
}, 10, 2);

add_filter('site_description', function ($description, $pdo) {
    $locale = $GLOBALS['ct_request_locale'] ?? null;
    $translation = $locale && $pdo instanceof PDO ? ct_site_translation($pdo, $locale) : null;
    return ($translation['description'] ?? '') !== '' ? $translation['description'] : $description;
}, 10, 2);

add_filter('post_data', function ($post, $pdo) {
    if (!is_array($post)) return $post;
    if (!$pdo instanceof PDO) return $post;

    $id = (int)($post['id'] ?? 0);
    if ($id <= 0) return $post;

    // Always capture the current post so hreflang/switcher work on default-locale pages too
    $GLOBALS['ct_current_post'] = $post;

    $locale = $GLOBALS['ct_request_locale'] ?? null;
    if (!$locale) return $post;

    return ct_overlay_published_translation($post, $pdo, $locale);
});

// Sidebar and shortcode widgets fetch their own rows, outside the controller post_data path.
add_filter('widget_recent_posts', function ($items, $pdo) {
    if (!is_array($items) || !$pdo instanceof PDO) return $items;
    $locale = $GLOBALS['ct_request_locale'] ?? null;
    if (!$locale) return $items;

    $translated = [];
    foreach ($items as $item) {
        if (!is_array($item)) continue;
        if (!ct_get_published_translation($pdo, (int)($item['id'] ?? 0), $locale)) continue;
        $translated[] = ct_overlay_published_translation($item, $pdo, $locale);
    }
    return $translated;
}, 10, 2);

add_filter('widget_categories', function ($items, $pdo) {
    if (!is_array($items) || !$pdo instanceof PDO || empty($GLOBALS['ct_request_locale'])) return $items;
    $locale = (string)$GLOBALS['ct_request_locale'];
    return array_values(array_map(
        fn($item) => ct_overlay_category_translation($item, $pdo, $locale),
        array_filter($items, fn($item) => ct_get_published_category_translation($pdo, (int)($item['id'] ?? 0), $locale) !== null)
    ));
}, 10, 2);

add_filter('widget_search_action', function ($action, $pdo) {
    $locale = $GLOBALS['ct_request_locale'] ?? null;
    return $locale ? ct_homepage_url($locale) : $action;
}, 10, 2);

add_filter('search_form_action', function ($action, $pdo) {
    $locale = $GLOBALS['ct_request_locale'] ?? null;
    return $locale ? ct_homepage_url($locale) : $action;
}, 10, 2);

add_filter('search_query_parts', function ($parts, $pdo, $query) {
    $locale = $GLOBALS['ct_request_locale'] ?? null;
    if (!$locale || !is_array($parts)) return $parts;
    $parts['where'][3] = "EXISTS (SELECT 1 FROM post_translations ct_search WHERE ct_search.post_id = posts.id AND ct_search.locale = :ct_search_locale AND ct_search.status = 'published' AND (ct_search.title LIKE :kw OR ct_search.content LIKE :kw))";
    $parts['params'][':ct_search_locale'] = $locale;
    return $parts;
}, 10, 3);

add_filter('search_results', function ($results, $pdo) {
    $locale = $GLOBALS['ct_request_locale'] ?? null;
    if (!$locale || !is_array($results) || !$pdo instanceof PDO) return $results;
    return array_map(fn($post) => ct_overlay_published_translation($post, $pdo, $locale), $results);
}, 10, 2);

add_filter('widget_category_url', function ($url, $category, $pdo) {
    $locale = $GLOBALS['ct_request_locale'] ?? null;
    if (!$pdo instanceof PDO || !$locale || !is_array($category)) return $url;
    return ct_category_url($pdo, $category, $locale) ?? $url;
}, 10, 3);

add_filter('menu_items', function ($items, $menuId, $pdo) {
    if (!is_array($items) || !$pdo instanceof PDO || empty($GLOBALS['ct_request_locale'])) return $items;
    $translations = ct_menu_item_translations_for_menu($pdo, (int)$menuId);
    $locale = (string)$GLOBALS['ct_request_locale'];
    foreach ($items as &$item) {
        $translation = $translations[(int)($item['id'] ?? 0)][$locale] ?? null;
        if (!$translation) continue;
        if (($translation['label'] ?? '') !== '') $item['label'] = $translation['label'];
        if (($translation['url'] ?? '') !== '') $item['manual_url'] = $translation['url'];
    }
    unset($item);
    return $items;
}, 10, 3);

add_filter('sidebar_zone_items', function ($items, $zoneId, $pdo) {
    if (!is_array($items) || !$pdo instanceof PDO || empty($GLOBALS['ct_request_locale'])) return $items;
    $locale = (string)$GLOBALS['ct_request_locale'];
    foreach ($items as &$item) {
        $translation = ct_sidebar_item_translation($pdo, (int)($item['id'] ?? 0), $locale);
        if (!$translation) continue;
        if (($translation['title'] ?? '') !== '') $item['title'] = $translation['title'];
        $item['config'] = array_merge((array)($item['config'] ?? []), (array)($translation['config'] ?? []));
        if (($translation['title'] ?? '') !== '') $item['config']['title'] = $translation['title'];
    }
    unset($item);
    return $items;
}, 10, 3);

// ─── Theme posts: direct routes and assigned slots ───
add_filter('theme_post_data', function ($post, $pdo) {
    if (!is_array($post) || !$pdo instanceof PDO) return $post;
    $GLOBALS['ct_current_post'] = $post;
    return ct_overlay_published_translation($post, $pdo);
}, 10, 2);

add_filter('theme_slot_post_data', function ($post, $slotKey, $pdo) {
    if (!is_array($post) || !$pdo instanceof PDO) return $post;
    $GLOBALS['ct_current_post'] = $post;
    return ct_overlay_published_translation($post, $pdo);
}, 10, 3);

// Jyavani Builder renders published layouts at priority 5. Replace only that
// output, then leave later content filters free to process the translation.
add_filter('post_content', function ($html, $post) {
    $locale = $GLOBALS['ct_request_locale'] ?? null;
    $pdo = $GLOBALS['pdo'] ?? null;
    $postId = is_array($post) ? (int)($post['id'] ?? 0) : 0;
    if (!$locale || !$pdo instanceof PDO || !is_array($post) || ($post['type'] ?? '') !== 'theme' || $postId <= 0
        || !function_exists('jvb_get_layout')) return $html;

    if (isset($_GET['jvb_preview']) && function_exists('is_logged_in') && is_logged_in()) {
        $role = function_exists('current_user_role') ? current_user_role($pdo) : null;
        if (in_array($role, ['editor', 'admin'], true)) return $html;
    }

    try {
        if (jvb_get_layout($pdo, $postId, 'published') === null) return $html;
    } catch (Throwable $e) {
        return $html;
    }

    $translation = ct_get_published_translation($pdo, $postId, (string)$locale);
    if (!$translation || trim((string)($translation['content'] ?? '')) === '') return $html;
    if (!function_exists('render_custom_post_template')) return (string)$translation['content'];

    return render_custom_post_template($post, [
        'post' => $post,
        'page' => $post,
        'site_context' => 'theme',
        'page_title' => (string)($post['title'] ?? ''),
    ]);
}, 6, 2);

// File-backed theme values are overlaid only when the whole declared resource is published.
add_filter('theme_mod_value', function ($value, $fieldKey, $themeFolder, $slotKey, $pdo) {
    static $published = [];
    $locale = $GLOBALS['ct_request_locale'] ?? null;
    if (!$locale || !$pdo instanceof PDO || !is_string($slotKey) || $slotKey === '') return $value;
    $cacheKey = (string)$themeFolder . "\0" . $slotKey . "\0" . (string)$locale;
    if (!array_key_exists($cacheKey, $published)) {
        $published[$cacheKey] = ct_get_published_theme_file_translation($pdo, (string)$themeFolder, $slotKey, (string)$locale);
    }
    $translation = $published[$cacheKey];
    if (!$translation || !array_key_exists((string)$fieldKey, $translation['values'])) return $value;
    return $translation['values'][(string)$fieldKey];
}, 10, 5);

// ─── Localized category collections ───
add_filter('collection_query_clauses', function ($clauses, $context) {
    $locale = $GLOBALS['ct_request_locale'] ?? null;
    $scope = $context['scope'] ?? '';
    if (!$locale || !in_array($scope, ['article_list', 'page_list', 'author_posts', 'archive_posts', 'category_posts', 'post_category_shortcode'], true)) return $clauses;
    $alias = in_array($context['table_alias'] ?? '', ['p', 'posts'], true) ? $context['table_alias'] : 'posts';
    $requiredFields = array_values(array_intersect(
        ['title', 'slug', 'content'],
        is_array($context['required_translation_fields'] ?? null) ? $context['required_translation_fields'] : []
    ));
    $requiredSql = '';
    foreach ($requiredFields as $field) {
        $requiredSql .= " AND TRIM(COALESCE(ct_post_translation.{$field}, '')) <> ''";
    }
    $clauses['where'][] = "EXISTS (SELECT 1 FROM post_translations ct_post_translation WHERE ct_post_translation.post_id = {$alias}.id AND ct_post_translation.locale = :ct_collection_locale AND ct_post_translation.status = 'published'{$requiredSql})";
    $clauses['params'][':ct_collection_locale'] = $locale;
    return $clauses;
}, 10, 2);

add_filter('collection_item', function ($item, $type, $context) {
    $pdo = $GLOBALS['pdo'] ?? null;
    if ($type !== 'category' || !$pdo instanceof PDO) return $item;
    if (in_array($context['scope'] ?? '', ['category', 'category_breadcrumb', 'post_category'], true)) {
        $GLOBALS['ct_current_category'] = $item;
    }
    return ct_overlay_category_translation($item, $pdo);
}, 10, 3);

add_filter('author_permalink', function ($url, $author, $page, $query) {
    $locale = $GLOBALS['ct_request_locale'] ?? null;
    return $locale ? '/' . rawurlencode($locale) . $url : $url;
}, 10, 4);

add_filter('author_profile_data', function ($author, $pdo) {
    $locale = $GLOBALS['ct_request_locale'] ?? null;
    if (!is_array($author) || !$pdo instanceof PDO) return $author;
    $GLOBALS['ct_current_author'] = $author;
    if (!$locale) return $author;
    $translation = ct_get_author_profile_translation($pdo, (int)($author['id'] ?? 0), $locale);
    if (($translation['bio'] ?? '') !== '') $author['bio'] = $translation['bio'];
    return $author;
}, 10, 2);

add_filter('sitemap_index_entries', function ($entries, $pdo, $domain, $limit) {
    if (!$pdo instanceof PDO) return $entries;
    foreach (ct_sitemap_locales($pdo) as $locale) {
        foreach (['posts' => 'article', 'pages' => 'page', 'themes' => 'theme'] as $type => $postType) {
            $routeRequirement = $postType === 'theme'
                ? " AND EXISTS (SELECT 1 FROM content_routes cr WHERE cr.post_id = p.id AND cr.locale = pt.locale AND cr.canonical_slot = 1)"
                : '';
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM post_translations pt INNER JOIN posts p ON p.id = pt.post_id WHERE pt.locale = ? AND pt.status = 'published' AND p.type = ? AND p.is_deleted = 0 AND p.status = 'published'{$routeRequirement}");
            $stmt->execute([$locale, $postType]);
            $maps = (int)ceil((int)$stmt->fetchColumn() / max(1, (int)$limit));
            for ($page = 1; $page <= $maps; $page++) $entries[] = ['loc' => $domain . '/sitemap_' . rawurlencode($locale) . '_' . $type . '_' . $page . '.xml'];
        }
    }
    return $entries;
}, 10, 4);

add_filter('sitemap_locale_rendered', function ($rendered, $locale, $type, $pageNum, $pdo) {
    if ($rendered || !$pdo instanceof PDO || !in_array($locale, ct_sitemap_locales($pdo), true) || !in_array($type, ['posts', 'pages', 'themes'], true)) return $rendered;
    $postType = match ($type) {
        'posts' => 'article',
        'themes' => 'theme',
        default => 'page',
    };
    $limit = 30;
    $routeJoin = $postType === 'theme'
        ? " INNER JOIN content_routes cr ON cr.post_id = p.id AND cr.locale = pt.locale AND cr.canonical_slot = 1"
        : '';
    $pathSelect = $postType === 'theme' ? 'cr.path' : 'pt.slug';
    $stmt = $pdo->prepare("SELECT {$pathSelect} AS slug, COALESCE(pt.updated_at, p.updated_at, p.created_at) AS changed_at FROM post_translations pt INNER JOIN posts p ON p.id = pt.post_id{$routeJoin} WHERE pt.locale = ? AND pt.status = 'published' AND p.type = ? AND p.is_deleted = 0 AND p.status = 'published' ORDER BY p.created_at DESC LIMIT ? OFFSET ?");
    $stmt->bindValue(1, $locale);
    $stmt->bindValue(2, $postType);
    $stmt->bindValue(3, $limit, PDO::PARAM_INT);
    $stmt->bindValue(4, (max(1, (int)$pageNum) - 1) * $limit, PDO::PARAM_INT);
    $stmt->execute();
    header('Content-Type: application/xml; charset=utf-8');
    echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n<urlset xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\">\n";
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $loc = ct_base_url() . ct_post_url((string)$row['slug'], $locale);
        echo '  <url><loc>' . htmlspecialchars($loc, ENT_XML1) . '</loc><lastmod>' . htmlspecialchars(date('c', strtotime((string)$row['changed_at'])), ENT_XML1) . '</lastmod></url>' . "\n";
    }
    echo '</urlset>';
    return true;
}, 10, 5);

add_filter('collection_rows', function ($rows, $context) {
    $pdo = $GLOBALS['pdo'] ?? null;
    $locale = $GLOBALS['ct_request_locale'] ?? null;
    if (!$pdo instanceof PDO || !$locale) return $rows;
    if (($context['scope'] ?? '') === 'category_index') {
        return array_values(array_map(fn($category) => ct_overlay_category_translation($category, $pdo), array_filter($rows, fn($category) => ct_get_published_category_translation($pdo, (int)($category['id'] ?? 0), $locale) !== null)));
    }
    if (in_array($context['scope'] ?? '', ['article_list', 'page_list', 'author_posts', 'archive_posts', 'category_posts', 'post_category_shortcode'], true)) {
        return array_map(fn($post) => ct_overlay_published_translation($post, $pdo, $locale), $rows);
    }
    return $rows;
}, 10, 2);

add_filter('collection_url', function ($url, $type, $context) {
    $pdo = $GLOBALS['pdo'] ?? null;
    $locale = $GLOBALS['ct_request_locale'] ?? null;
    if (!$pdo instanceof PDO || !$locale) return $url;
    if (($context['scope'] ?? '') === 'post_category_shortcode' && in_array($type, ['article', 'page'], true)) {
        $item = is_array($context['item'] ?? null) ? $context['item'] : [];
        $slug = trim((string)($item['ct_translated_slug'] ?? ''));
        return $slug !== '' ? ct_post_url($slug, $locale) : $url;
    }
    if (($context['route'] ?? '') === 'category') {
        $base = trim(get_category_base($pdo), '/');
        $path = isset($context['category_id']) ? ct_category_translation_path($pdo, ['id' => (int)$context['category_id']], $locale) : null;
        if ($path === null && $type !== 'category_index') return $url;
        $localized = '/' . $locale . '/' . ($base !== '' ? $base . '/' : '') . ($path ? $path . '/' : '');
        $page = max(1, (int)($context['page'] ?? 1));
        if ($page > 1) $localized .= 'p/' . $page . '/';
        $query = (string)($context['query'] ?? '');
        return $query !== '' ? $localized . '?' . http_build_query(['q' => $query]) : $localized;
    }
    if ($type === 'category_index') return '/' . $locale . rtrim(get_category_base($pdo), '/') . '/';
    return $url;
}, 10, 3);

add_filter('content_permalink', function ($url, $post, $type) {
    $pdo = $GLOBALS['pdo'] ?? null;
    $locale = $GLOBALS['ct_request_locale'] ?? null;
    if (!$pdo instanceof PDO || !$locale || !is_array($post)) return $url;
    $translation = ct_get_published_translation($pdo, (int)($post['id'] ?? 0), $locale);
    if (!$translation) return $url;
    return ct_post_url((string)($translation['slug'] ?? '') ?: (string)($post['slug'] ?? ''), $locale);
}, 10, 3);

// Preserve historical source URLs after the default content moves to English.
add_filter('unresolved_content_redirect_url', function ($url, $path, $pdo) {
    if ($url !== '' || !$pdo instanceof PDO || !empty($GLOBALS['ct_request_locale'])) return $url;
    $slug = trim((string)$path, '/');
    if ($slug === '') return $url;

    $stmt = $pdo->prepare("SELECT pt.slug FROM post_translations pt INNER JOIN posts p ON p.id = pt.post_id WHERE pt.locale = ? AND pt.slug = ? AND pt.slug <> p.slug AND pt.status = 'published' AND p.status = 'published' AND p.is_deleted = 0 AND NOT EXISTS (SELECT 1 FROM posts live WHERE live.slug = ? AND live.status = 'published' AND live.is_deleted = 0) LIMIT 1");
    foreach (ct_enabled_locales($pdo) as $locale) {
        $stmt->execute([$locale, $slug, $slug]);
        $translatedSlug = $stmt->fetchColumn();
        if (is_string($translatedSlug) && $translatedSlug !== '') return ct_post_url($translatedSlug, $locale);
    }
    return $url;
}, 10, 3);

// ─── Localized document metadata ───
add_filter('html_lang_attribute', function ($lang) {
    return $GLOBALS['ct_request_locale'] ?? $lang;
});

add_filter('html_dir_attribute', function ($direction) {
    $locale = $GLOBALS['ct_request_locale'] ?? (function_exists('content_default_locale') ? content_default_locale() : null);
    $pdo = $GLOBALS['pdo'] ?? null;
    if (!$locale || !($pdo instanceof PDO)) return $direction;
    return ct_locale_direction($pdo, (string)$locale);
}, 10, 1);

add_filter('document_title', function ($title, $pdo) {
    if (!$pdo instanceof PDO) return $title;
    $locale = $GLOBALS['ct_request_locale'] ?? null;
    $category = $GLOBALS['ct_current_category'] ?? null;
    if ($locale && is_array($category)) {
        $category = ct_overlay_category_translation($category, $pdo, (string)$locale);
        $name = trim((string)($category['name'] ?? ''));
        if ($name !== '') return $name . ' — ' . __('Category');
    }
    if (empty($GLOBALS['ct_theme_file_homepage'])) return $title;
    $translation = $GLOBALS['ct_current_theme_file_translation'] ?? null;
    return is_array($translation) && trim((string)($translation['seo_title'] ?? '')) !== ''
        ? $translation['seo_title']
        : $title;
}, 10, 2);

add_filter('document_meta_description', function ($description, $post, $pdo) {
    if (!$pdo instanceof PDO) return $description;
    $locale = $GLOBALS['ct_request_locale'] ?? null;
    if ($locale && is_array($post)) {
        $translation = ct_get_published_translation($pdo, (int)($post['id'] ?? 0), (string)$locale);
        if (trim((string)($translation['meta_description'] ?? '')) !== '') {
            return $translation['meta_description'];
        }
    }
    if (empty($GLOBALS['ct_theme_file_homepage'])) return $description;
    $translation = $GLOBALS['ct_current_theme_file_translation'] ?? null;
    return is_array($translation) && trim((string)($translation['meta_description'] ?? '')) !== ''
        ? $translation['meta_description']
        : $description;
}, 10, 3);

add_filter('canonical_url', function ($url) {
    $locale = $GLOBALS['ct_request_locale'] ?? null;
    $pdo = $GLOBALS['pdo'] ?? null;
    if (!empty($GLOBALS['ct_theme_file_homepage']) && $pdo instanceof PDO && $locale) {
        return ct_base_url() . ct_homepage_url($locale);
    }

    $category = $GLOBALS['ct_current_category'] ?? null;
    if (is_array($category) && $pdo instanceof PDO && $locale) {
        $context = function_exists('collection_current_route_context') ? collection_current_route_context() : [];
        $categoryUrl = ct_category_url($pdo, $category, (string)$locale, (int)($context['page'] ?? 1));
        if ($categoryUrl !== null) return ct_base_url() . $categoryUrl;
    }

    $post = $GLOBALS['ct_current_post'] ?? null;
    if (!is_array($post) || !$pdo instanceof PDO || !$locale) return $url;

    if (!empty($GLOBALS['ct_localized_homepage'])) {
        return ct_base_url() . ct_homepage_url($locale);
    }

    $translation = ct_get_published_translation($pdo, (int)($post['id'] ?? 0), $locale);
    if (!$translation) return $url;

    return ct_base_url() . ct_public_post_url($pdo, $post, (string)$locale);
});

// ─── hreflang alternate links in <head> ───
add_action('jy_head', function () {
    $pdo = $GLOBALS['pdo'] ?? null;
    if (!$pdo instanceof PDO) return;

    $resource = $GLOBALS['ct_current_theme_file'] ?? null;
    if (!empty($GLOBALS['ct_theme_file_homepage']) && is_array($resource)) {
        $base = ct_base_url();
        echo '<link rel="alternate" hreflang="' . htmlspecialchars(content_default_locale(), ENT_QUOTES) . '" href="' . htmlspecialchars($base . '/', ENT_QUOTES) . '">' . "\n";
        foreach (ct_enabled_locales($pdo) as $locale) {
            if (!ct_get_published_theme_file_translation($pdo, (string)$resource['theme_folder'], (string)$resource['slot_key'], $locale)) continue;
            echo '<link rel="alternate" hreflang="' . htmlspecialchars($locale, ENT_QUOTES) . '" href="' . htmlspecialchars($base . ct_homepage_url($locale), ENT_QUOTES) . '">' . "\n";
        }
        echo '<link rel="alternate" hreflang="x-default" href="' . htmlspecialchars($base . '/', ENT_QUOTES) . '">' . "\n";
        return;
    }

    $category = $GLOBALS['ct_current_category'] ?? null;
    if (is_array($category)) {
        $context = function_exists('collection_current_route_context') ? collection_current_route_context() : [];
        $page = (int)($context['page'] ?? 1);
        $query = (string)($context['query'] ?? '');
        $base = ct_base_url();
        $defaultUrl = ct_category_url($pdo, $category, null, $page, $query);
        if ($defaultUrl === null) return;
        echo '<link rel="alternate" hreflang="' . htmlspecialchars(content_default_locale(), ENT_QUOTES) . '" href="' . htmlspecialchars($base . $defaultUrl, ENT_QUOTES) . '">' . "\n";
        foreach (ct_enabled_locales($pdo) as $locale) {
            $url = ct_category_url($pdo, $category, $locale, $page, $query);
            if ($url === null) continue;
            echo '<link rel="alternate" hreflang="' . htmlspecialchars($locale, ENT_QUOTES) . '" href="' . htmlspecialchars($base . $url, ENT_QUOTES) . '">' . "\n";
        }
        echo '<link rel="alternate" hreflang="x-default" href="' . htmlspecialchars($base . $defaultUrl, ENT_QUOTES) . '">' . "\n";
        return;
    }

    $post = $GLOBALS['ct_current_post'] ?? null;
    if (!is_array($post)) return;

    $id = (int)($post['id'] ?? 0);
    $slug = (string)($post['slug'] ?? '');
    if ($id <= 0 || $slug === '') return;

    $base = ct_base_url();
    if (!empty($GLOBALS['ct_localized_homepage'])) {
        echo '<link rel="alternate" hreflang="' . htmlspecialchars(content_default_locale(), ENT_QUOTES) . '" href="' . htmlspecialchars($base . '/', ENT_QUOTES) . '">' . "\n";
        foreach (ct_enabled_locales($pdo) as $locale) {
            if (!ct_get_published_translation($pdo, $id, $locale)) continue;
            echo '<link rel="alternate" hreflang="' . htmlspecialchars($locale, ENT_QUOTES) . '" href="' . htmlspecialchars($base . ct_homepage_url($locale), ENT_QUOTES) . '">' . "\n";
        }
        echo '<link rel="alternate" hreflang="x-default" href="' . htmlspecialchars($base . '/', ENT_QUOTES) . '">' . "\n";
        return;
    }
    $links = [];
    $links[] = ['hreflang' => content_default_locale(), 'href' => $base . ct_public_post_url($pdo, $post)];

    $translations = array_filter(ct_translations_for_post($pdo, $id), fn(array $translation) => ($translation['status'] ?? 'published') === 'published');
    foreach (ct_enabled_locales($pdo) as $locale) {
        $t = $translations[$locale] ?? null;
        if (!$t) continue;
        if (!ct_get_published_translation($pdo, $id, $locale)) continue;
        $links[] = ['hreflang' => $locale, 'href' => $base . ct_public_post_url($pdo, $post, $locale)];
    }

    if (count($links) < 2) return;

    foreach ($links as $l) {
        echo '<link rel="alternate" hreflang="' . htmlspecialchars($l['hreflang'], ENT_QUOTES) . '" href="' . htmlspecialchars($l['href'], ENT_QUOTES) . '">' . "\n";
    }
    echo '<link rel="alternate" hreflang="x-default" href="' . htmlspecialchars($base . ct_public_post_url($pdo, $post), ENT_QUOTES) . '">' . "\n";
});

// ─── Language switcher — shared renderer ───
if (!function_exists('ct_switcher_html')) {
    function ct_switcher_html(PDO $pdo, string $title = '', string $style = 'pills'): string {
        $current = $GLOBALS['ct_current_post'] ?? null;
        $currentCategory = $GLOBALS['ct_current_category'] ?? null;
        $currentAuthor = $GLOBALS['ct_current_author'] ?? null;
        $currentThemeFile = $GLOBALS['ct_current_theme_file'] ?? null;

        $localizedRequestUrl = static function (?string $locale = null): string {
            $path = (string)(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/');
            $query = (string)(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_QUERY) ?? '');
            $path = preg_replace('#^/[a-z]{2,3}(?:-[A-Za-z0-9]{2,8})?(?=/|$)#', '', $path) ?: '/';
            $prefix = $locale ? '/' . rawurlencode($locale) : '';
            return $prefix . ($path === '/' ? '/' : '/' . ltrim($path, '/')) . ($query !== '' ? '?' . $query : '');
        };
        $requestPath = (string)(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/');
        $requestPath = preg_replace('#^/[a-z]{2,3}(?:-[A-Za-z0-9]{2,8})?(?=/|$)#', '', $requestPath) ?: '/';
        $requestPath = trim($requestPath, '/');

        if (!empty($GLOBALS['ct_theme_file_homepage']) && is_array($currentThemeFile)) {
            $locales = ct_enabled_locales($pdo);
            $currentLocale = $GLOBALS['ct_request_locale'] ?? content_default_locale();
            $items = [['locale' => content_default_locale(), 'url' => '/', 'active' => $currentLocale === content_default_locale()]];
            foreach ($locales as $locale) {
                if (!ct_get_published_theme_file_translation($pdo, (string)$currentThemeFile['theme_folder'], (string)$currentThemeFile['slot_key'], $locale)) continue;
                $items[] = ['locale' => $locale, 'url' => ct_homepage_url($locale), 'active' => $currentLocale === $locale];
            }
            return ct_render_switcher_items($items, $title, $style);
        }

        $requestContent = ct_current_content_from_request($pdo);
        if (is_array($requestContent)) {
            $current = $requestContent;
            $GLOBALS['ct_current_post'] = $current;
        }

        $locales = ct_enabled_locales($pdo);
        if (empty($locales)) return '';
        $currentLocale = $GLOBALS['ct_request_locale'] ?? content_default_locale();

        if (!is_array($current) && is_array($currentCategory)) {
            $context = function_exists('collection_current_route_context') ? collection_current_route_context() : [];
            $page = (int)($context['page'] ?? 1);
            $query = (string)($context['query'] ?? '');
            $items = [['locale' => content_default_locale(), 'url' => ct_category_url($pdo, $currentCategory, null, $page, $query), 'active' => $currentLocale === content_default_locale()]];
            foreach ($locales as $locale) {
                $url = ct_category_url($pdo, $currentCategory, $locale, $page, $query);
                if ($url !== null) $items[] = ['locale' => $locale, 'url' => $url, 'active' => $currentLocale === $locale];
            }
            return ct_render_switcher_items($items, $title, $style);
        }
        if (!is_array($current) && is_array($currentAuthor)) {
            $items = [['locale' => content_default_locale(), 'url' => $localizedRequestUrl(), 'active' => $currentLocale === content_default_locale()]];
            foreach ($locales as $locale) {
                if (!ct_get_author_profile_translation($pdo, (int)($currentAuthor['id'] ?? 0), $locale)) continue;
                $items[] = ['locale' => $locale, 'url' => $localizedRequestUrl($locale), 'active' => $currentLocale === $locale];
            }
            return ct_render_switcher_items($items, $title, $style);
        }
        $listRoutes = array_merge(
            function_exists('get_posts_list_routes') ? get_posts_list_routes($pdo) : ['artikel'],
            function_exists('get_pages_list_routes') ? get_pages_list_routes($pdo) : ['halaman']
        );
        $isCollection = function_exists('collection_match_route_base')
            && collection_match_route_base($requestPath, $listRoutes) !== null;
        $isArchive = preg_match('#^\d{4}(?:/\d{2})?(?:/(?:p|page)/\d+)?$#', $requestPath) === 1;
        if (!is_array($current) && ($isCollection || $isArchive || isset($_GET['s']))) {
            $items = [['locale' => content_default_locale(), 'url' => $localizedRequestUrl(), 'active' => $currentLocale === content_default_locale()]];
            foreach ($locales as $locale) $items[] = ['locale' => $locale, 'url' => $localizedRequestUrl($locale), 'active' => $currentLocale === $locale];
            return ct_render_switcher_items($items, $title, $style);
        }
        if (!is_array($current)) return '';

    if (!empty($GLOBALS['ct_localized_homepage'])) {
        $items = [['locale' => content_default_locale(), 'url' => '/', 'active' => $currentLocale === content_default_locale()]];
        foreach ($locales as $locale) {
            if (!ct_get_published_translation($pdo, (int)$current['id'], $locale)) continue;
            $items[] = ['locale' => $locale, 'url' => ct_homepage_url($locale), 'active' => $currentLocale === $locale];
        }
        return ct_render_switcher_items($items, $title, $style);
    }

        $slug = (string)($current['slug'] ?? '');
        if ($slug === '') return '';

        $items = [];
        $items[] = [
            'locale' => content_default_locale(),
            'url'    => ct_public_post_url($pdo, $current),
            'active' => $currentLocale === content_default_locale(),
        ];

        $translations = array_filter(
            ct_translations_for_post($pdo, (int)$current['id']),
            fn(array $translation) => ($translation['status'] ?? 'published') === 'published'
        );
        foreach ($locales as $locale) {
            $t = $translations[$locale] ?? null;
            if (!$t) continue;
            if (!ct_get_published_translation($pdo, (int)$current['id'], $locale)) continue;
            $items[] = [
                'locale' => $locale,
                'url'    => ct_public_post_url($pdo, $current, $locale),
                'active' => $currentLocale === $locale,
            ];
        }

    return ct_render_switcher_items($items, $title, $style);
}

if (!function_exists('ct_render_switcher_items')) {
    function ct_render_switcher_items(array $items, string $title, string $style): string {
        if (count($items) < 2) return '';

        if ($style === 'select') {
            $label = function_exists('__') ? __('Language') : 'Language';
            $out = $title !== '' ? '<h3 class="widget-title">' . htmlspecialchars($title, ENT_QUOTES) . '</h3>' : '';
            $out .= '<select class="ct-lang-select" aria-label="' . htmlspecialchars($label, ENT_QUOTES) . '" onchange="if(this.value)window.location.href=this.value">';
            foreach ($items as $item) {
                $sel = $item['active'] ? ' selected' : '';
                $out .= '<option value="' . htmlspecialchars($item['url'], ENT_QUOTES) . '"' . $sel . '>'
                    . htmlspecialchars(strtoupper($item['locale']), ENT_QUOTES) . '</option>';
            }
            return $out . '</select>';
        }

        $out = '';
        if ($title !== '') {
            $out .= '<h3 class="widget-title">' . htmlspecialchars($title, ENT_QUOTES) . '</h3>';
        }
        $out .= '<ul class="ct-lang-list">';
        foreach ($items as $item) {
            $cls = $item['active'] ? ' class="active"' : '';
            $out .= '<li' . $cls . '><a href="' . htmlspecialchars($item['url'], ENT_QUOTES) . '" hreflang="' . htmlspecialchars($item['locale'], ENT_QUOTES) . '">'
                . htmlspecialchars(strtoupper($item['locale']), ENT_QUOTES) . '</a></li>';
        }
        return $out . '</ul>';
    }
}
}

// Theme helper — call directly in theme files: echo ct_language_switcher()
if (!function_exists('ct_language_switcher')) {
    function ct_language_switcher(string $title = '', string $style = 'pills'): string {
        $pdo = $GLOBALS['pdo'] ?? null;
        if (!$pdo instanceof PDO) return '';
        $html = ct_switcher_html($pdo, $title, $style);
        return $html === '' ? '' : '<div class="widget widget-lang-switcher">' . $html . '</div>';
    }
}

// ─── Shortcode: [[widget:lang_switcher title="..." style="pills|select"]] ───
if (function_exists('register_widget_shortcode_handler')) {
    register_widget_shortcode_handler('lang_switcher', function (PDO $pdo, array $vars, array $ctx = []) {
        $title = (string)($vars['title'] ?? '');
        $style = (string)($vars['style'] ?? 'pills');
        $html = ct_switcher_html($pdo, $title, $style);
        return $html === '' ? '' : '<div class="widget widget-lang-switcher">' . $html . '</div>';
    }, ['title' => '', 'style' => 'pills']);
}

// ─── Language switcher — sidebar widget ───
add_filter('theme_zone_widget_types', function ($types) {
    if (!is_array($types)) $types = [];
    $types['lang_switcher'] = [
        'label' => __('Content Translation'),
        'desc' => __('Links to published translations of the current content.'),
        'default_config' => ['title' => ''],
    ];
    return $types;
});

add_filter('theme_zone_render_widget', function ($html, $type, $config, $pdo) {
    if ($html !== '' || $type !== 'lang_switcher' || !$pdo instanceof PDO) return $html;
    return ct_language_switcher((string)($config['title'] ?? ''), 'select');
}, 10, 4);

add_filter('sidebar_widget_types', function ($types) {
    if (!is_array($types)) $types = [];
    $types['lang_switcher'] = [
        'label' => __('Content Translation'),
        'desc'  => __('Links to published translations of the current content.'),
        'default_config' => ['title' => __('Languages')],
    ];
    return $types;
});

add_filter('render_sidebar_widget', function ($html, $type, $config, $pdo) {
    if ($type !== 'lang_switcher') return $html;
    if (!$pdo instanceof PDO) return '';

    $title = (string)($config['title'] ?? __('Languages'));
    $html = ct_switcher_html($pdo, $title, 'select');
    return $html === '' ? '' : '<div class="widget widget-lang-switcher">' . $html . '</div>';
}, 10, 4);

add_action('init', function () {
    static $migrated = false;
    if ($migrated) return;
    $migrated = true;
    $pdo = $GLOBALS['pdo'] ?? null;
    if (!$pdo instanceof PDO) return;
    try {
        $pdo->exec("UPDATE theme_zone_items SET type = 'lang_switcher' WHERE type = 'tz_lang_switcher'");
    } catch (Throwable $e) {
        // Theme zones may not be installed on a minimal Core installation.
    }

    // public/index.php can serve the default root without invoking router_path.
    $path = trim((string)(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? ''), '/');
    if ($path !== '' || trim((string)($_GET['s'] ?? '')) !== '') return;
    if (ct_homepage_theme_post($pdo)) return;
    $resource = ct_homepage_theme_file_resource($pdo);
    if (!$resource) return;
    $GLOBALS['ct_current_theme_file'] = $resource;
    $GLOBALS['ct_theme_file_homepage'] = true;
});
