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

    if (function_exists('set_locale')) {
        set_locale($first);
    }
    $GLOBALS['ct_request_locale'] = $first;

    $rest = ltrim(substr($path, strlen($first)), '/');
    if ($rest === '') return '';

    // Translated slug → original slug (try full path, then last segment)
    $t = ct_find_translation_by_slug($pdo, $first, $rest);
    if (!$t && str_contains($rest, '/')) {
        $last = substr($rest, strrpos($rest, '/') + 1);
        $t = ct_find_translation_by_slug($pdo, $first, $last);
    }
    if ($t) {
        $origSlug = ct_original_slug($pdo, (int)$t['post_id']);
        if ($origSlug !== null && $origSlug !== '') {
            return $origSlug;
        }
    }

    return $rest;
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

    $t = ct_get_translation($pdo, $id, $locale);
    if (!$t) return $post;

    foreach (['title', 'content'] as $f) {
        if (isset($t[$f]) && $t[$f] !== '' && $t[$f] !== null) {
            $post[$f] = $t[$f];
        }
    }
    $post['ct_locale'] = $locale;
    $post['ct_translated_slug'] = (string)($t['slug'] ?? '') !== '' ? (string)$t['slug'] : (string)($post['slug'] ?? '');

    return $post;
});

// ─── hreflang alternate links in <head> ───
add_action('wp_head', function () {
    $post = $GLOBALS['ct_current_post'] ?? null;
    $pdo = $GLOBALS['pdo'] ?? null;
    if (!is_array($post) || !$pdo instanceof PDO) return;

    $id = (int)($post['id'] ?? 0);
    $slug = (string)($post['slug'] ?? '');
    if ($id <= 0 || $slug === '') return;

    $base = ct_base_url();
    $links = [];
    $links[] = ['hreflang' => default_locale(), 'href' => $base . ct_post_url($slug)];

    $translations = ct_translations_for_post($pdo, $id);
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
        $locales = ct_enabled_locales($pdo);
        if (empty($locales)) return '';

        $current = $GLOBALS['ct_current_post'] ?? null;
        $currentLocale = $GLOBALS['ct_request_locale'] ?? default_locale();

        $items = [];

        // Default locale entry
        if (is_array($current)) {
            $slug = (string)($current['slug'] ?? '');
            if ($slug === '') return '';
            $url = ct_post_url($slug);
        } else {
            $url = '/';
        }
        $items[] = [
            'locale' => default_locale(),
            'url'    => $url,
            'active' => $currentLocale === default_locale(),
        ];

        $translations = is_array($current) ? ct_translations_for_post($pdo, (int)$current['id']) : [];
        foreach ($locales as $locale) {
            if (is_array($current)) {
                $t = $translations[$locale] ?? null;
                $tSlug = ($t && (string)($t['slug'] ?? '') !== '') ? (string)$t['slug'] : (string)$current['slug'];
                $url = ct_post_url($tSlug, $locale);
            } else {
                $url = '/' . $locale . '/';
            }
            $items[] = [
                'locale' => $locale,
                'url'    => $url,
                'active' => $currentLocale === $locale,
            ];
        }

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
