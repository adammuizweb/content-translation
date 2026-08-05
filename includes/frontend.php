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
        $homepage = ct_homepage_theme_post($pdo);
        if (!$homepage || !ct_get_published_translation($pdo, (int)$homepage['id'], $first)) ct_render_not_found();

        if (function_exists('set_locale')) set_locale($first);
        $GLOBALS['ct_request_locale'] = $first;
        $GLOBALS['ct_current_post'] = $homepage;
        $GLOBALS['ct_localized_homepage'] = true;
        return '';
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

// ─── Localized document metadata ───
add_filter('html_lang_attribute', function ($lang) {
    return $GLOBALS['ct_request_locale'] ?? $lang;
});

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
        if (!is_array($current)) return '';

        $locales = ct_enabled_locales($pdo);
        if (empty($locales)) return '';
    $currentLocale = $GLOBALS['ct_request_locale'] ?? content_default_locale();

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
        return '<div class="widget widget-lang-switcher">' . ct_switcher_html($pdo, $title, $style) . '</div>';
    }
}

// ─── Shortcode: [[widget:lang_switcher title="..." style="pills|select"]] ───
if (function_exists('register_widget_shortcode_handler')) {
    register_widget_shortcode_handler('lang_switcher', function (PDO $pdo, array $vars, array $ctx = []) {
        $title = (string)($vars['title'] ?? '');
        $style = (string)($vars['style'] ?? 'pills');
        return '<div class="widget widget-lang-switcher">' . ct_switcher_html($pdo, $title, $style) . '</div>';
    }, ['title' => '', 'style' => 'pills']);
}

// ─── Language switcher — sidebar widget ───
add_filter('sidebar_widget_types', function ($types) {
    if (!is_array($types)) $types = [];
    $types['lang_switcher'] = [
        'label' => __('Language Switcher'),
        'desc'  => __('Links to translated versions of the current page.'),
        'default_config' => ['title' => __('Languages')],
    ];
    return $types;
});

add_filter('render_sidebar_widget', function ($html, $type, $config, $pdo) {
    if ($type !== 'lang_switcher') return $html;
    if (!$pdo instanceof PDO) return '';

    $title = (string)($config['title'] ?? __('Languages'));
    return '<div class="widget widget-lang-switcher">' . ct_switcher_html($pdo, $title) . '</div>';
}, 10, 4);
