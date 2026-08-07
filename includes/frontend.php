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
        if (!$homepage || !ct_get_published_translation($pdo, (int)$homepage['id'], $first)) ct_render_not_found();

        if (function_exists('set_locale')) set_locale($first);
        $GLOBALS['ct_request_locale'] = $first;
        $GLOBALS['ct_current_post'] = $homepage;
        $GLOBALS['ct_localized_homepage'] = true;
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
        if (preg_match('#^(.*?)/page/(\d+)$#', $categoryPath, $matches)) {
            $categoryPath = $matches[1];
            $paginationSuffix = '/page/' . $matches[2];
        }
        $resolved = ct_find_category_translation_path($pdo, $first, $categoryPath);
        if (!$resolved) ct_render_not_found();
        if (function_exists('set_locale')) set_locale($first);
        $GLOBALS['ct_request_locale'] = $first;
        return $categoryMatch['base'] . '/' . $resolved['source_path'] . $paginationSuffix;
    }

    // A locale URL exists only for a reviewed, published translation.
    $t = ct_find_translation_by_slug($pdo, $first, $rest);
    if (!$t) ct_render_not_found();

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
    return ct_overlay_published_translation($post, $pdo);
}, 10, 3);

// ─── Localized category collections ───
add_filter('collection_query_clauses', function ($clauses, $context) {
    $locale = $GLOBALS['ct_request_locale'] ?? null;
    $scope = $context['scope'] ?? '';
    if (!$locale || !in_array($scope, ['article_list', 'page_list', 'author_posts', 'archive_posts', 'category_posts'], true)) return $clauses;
    $alias = in_array($context['table_alias'] ?? '', ['p', 'posts'], true) ? $context['table_alias'] : 'posts';
    $clauses['where'][] = "EXISTS (SELECT 1 FROM post_translations ct_post_translation WHERE ct_post_translation.post_id = {$alias}.id AND ct_post_translation.locale = :ct_collection_locale AND ct_post_translation.status = 'published')";
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
    if (!$locale || !is_array($author) || !$pdo instanceof PDO) return $author;
    $translation = ct_get_author_profile_translation($pdo, (int)($author['id'] ?? 0), $locale);
    if (($translation['bio'] ?? '') !== '') $author['bio'] = $translation['bio'];
    return $author;
}, 10, 2);

add_filter('sitemap_index_entries', function ($entries, $pdo, $domain, $limit) {
    if (!$pdo instanceof PDO) return $entries;
    foreach (ct_sitemap_locales($pdo) as $locale) {
        foreach (['posts' => 'article', 'pages' => 'page'] as $type => $postType) {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM post_translations pt INNER JOIN posts p ON p.id = pt.post_id WHERE pt.locale = ? AND pt.status = 'published' AND p.type = ? AND p.is_deleted = 0 AND p.status = 'published'");
            $stmt->execute([$locale, $postType]);
            $maps = (int)ceil((int)$stmt->fetchColumn() / max(1, (int)$limit));
            for ($page = 1; $page <= $maps; $page++) $entries[] = ['loc' => $domain . '/sitemap_' . rawurlencode($locale) . '_' . $type . '_' . $page . '.xml'];
        }
    }
    return $entries;
}, 10, 4);

add_filter('sitemap_locale_rendered', function ($rendered, $locale, $type, $pageNum, $pdo) {
    if ($rendered || !$pdo instanceof PDO || !in_array($locale, ct_sitemap_locales($pdo), true) || !in_array($type, ['posts', 'pages'], true)) return $rendered;
    $postType = $type === 'posts' ? 'article' : 'page';
    $limit = 30;
    $stmt = $pdo->prepare("SELECT pt.slug, COALESCE(pt.updated_at, p.updated_at, p.created_at) AS changed_at FROM post_translations pt INNER JOIN posts p ON p.id = pt.post_id WHERE pt.locale = ? AND pt.status = 'published' AND p.type = ? AND p.is_deleted = 0 AND p.status = 'published' ORDER BY p.created_at DESC LIMIT ? OFFSET ?");
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
    if (in_array($context['scope'] ?? '', ['article_list', 'page_list', 'author_posts', 'archive_posts', 'category_posts'], true)) {
        return array_map(fn($post) => ct_overlay_published_translation($post, $pdo, $locale), $rows);
    }
    return $rows;
}, 10, 2);

add_filter('collection_url', function ($url, $type, $context) {
    $pdo = $GLOBALS['pdo'] ?? null;
    $locale = $GLOBALS['ct_request_locale'] ?? null;
    if (!$pdo instanceof PDO || !$locale) return $url;
    if (($context['route'] ?? '') === 'category') {
        $base = trim(get_category_base($pdo), '/');
        $path = isset($context['category_id']) ? ct_category_translation_path($pdo, ['id' => (int)$context['category_id']], $locale) : null;
        if ($path === null && $type !== 'category_index') return $url;
        $localized = '/' . $locale . '/' . ($base !== '' ? $base . '/' : '') . ($path ? $path . '/' : '');
        $page = max(1, (int)($context['page'] ?? 1));
        if ($page > 1) $localized .= 'page/' . $page . '/';
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

add_filter('canonical_url', function ($url) {
    $post = $GLOBALS['ct_current_post'] ?? null;
    $locale = $GLOBALS['ct_request_locale'] ?? null;
    $pdo = $GLOBALS['pdo'] ?? null;
    if (!is_array($post) || !$pdo instanceof PDO || !$locale) return $url;

    if (!empty($GLOBALS['ct_localized_homepage'])) {
        return ct_base_url() . ct_homepage_url($locale);
    }

    $translation = ct_get_published_translation($pdo, (int)($post['id'] ?? 0), $locale);
    if (!$translation) return $url;

    $slug = (string)($translation['slug'] ?? '') ?: (string)($post['slug'] ?? '');
    return ct_base_url() . ct_post_url($slug, $locale);
});

// ─── hreflang alternate links in <head> ───
add_action('jy_head', function () {
    $post = $GLOBALS['ct_current_post'] ?? null;
    $pdo = $GLOBALS['pdo'] ?? null;
    if (!is_array($post) || !$pdo instanceof PDO) return;

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
    $links[] = ['hreflang' => content_default_locale(), 'href' => $base . ct_post_url($slug)];

    $translations = array_filter(ct_translations_for_post($pdo, $id), fn(array $translation) => ($translation['status'] ?? 'published') === 'published');
    foreach (ct_enabled_locales($pdo) as $locale) {
        $t = $translations[$locale] ?? null;
        if (!$t) continue;
        $tSlug = (string)($t['slug'] ?? '') !== '' ? (string)$t['slug'] : $slug;
        $links[] = ['hreflang' => $locale, 'href' => $base . ct_post_url($tSlug, $locale)];
    }

    if (count($links) < 2) return;

    foreach ($links as $l) {
        echo '<link rel="alternate" hreflang="' . htmlspecialchars($l['hreflang'], ENT_QUOTES) . '" href="' . htmlspecialchars($l['href'], ENT_QUOTES) . '">' . "\n";
    }
    echo '<link rel="alternate" hreflang="x-default" href="' . htmlspecialchars($base . ct_post_url($slug), ENT_QUOTES) . '">' . "\n";
});

// ─── Language switcher — shared renderer ───
if (!function_exists('ct_switcher_html')) {
    function ct_switcher_html(PDO $pdo, string $title = '', string $style = 'pills'): string {
        $current = $GLOBALS['ct_current_post'] ?? null;
        $currentCategory = $GLOBALS['ct_current_category'] ?? null;

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
            'url'    => ct_post_url($slug),
            'active' => $currentLocale === content_default_locale(),
        ];

        $translations = array_filter(
            ct_translations_for_post($pdo, (int)$current['id']),
            fn(array $translation) => ($translation['status'] ?? 'published') === 'published'
        );
        foreach ($locales as $locale) {
            $t = $translations[$locale] ?? null;
            if (!$t) continue;
            $tSlug = (string)($t['slug'] ?? '') ?: $slug;
            $items[] = [
                'locale' => $locale,
                'url'    => ct_post_url($tSlug, $locale),
                'active' => $currentLocale === $locale,
            ];
        }

    return ct_render_switcher_items($items, $title, $style);
}

if (!function_exists('ct_render_switcher_items')) {
    function ct_render_switcher_items(array $items, string $title, string $style): string {
        if (count($items) < 2) return '';

        if ($style === 'select') {
            $out = '<select class="ct-lang-select" onchange="if(this.value)window.location.href=this.value">';
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
    $html = ct_switcher_html($pdo, $title);
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
});
