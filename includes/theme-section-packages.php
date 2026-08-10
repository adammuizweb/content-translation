<?php
declare(strict_types=1);

if (!function_exists('ct_theme_section_package_format')) {
    function ct_theme_section_package_format(): string {
        return 'ct-theme-sections-v1';
    }

    function ct_decode_theme_section_package(string $content): ?array {
        if ($content === '' || strlen($content) > 2 * 1024 * 1024) return null;
        try {
            $package = json_decode($content, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            return null;
        }
        if (!is_array($package)
            || array_keys($package) !== ['format', 'theme_folder', 'composition', 'source_sha256', 'sections']
            || $package['format'] !== ct_theme_section_package_format()
            || preg_match('/\A[a-z0-9][a-z0-9_-]*\z/i', (string)$package['theme_folder']) !== 1
            || $package['composition'] !== 'theme-sections-v1'
            || preg_match('/\A[a-f0-9]{64}\z/', (string)$package['source_sha256']) !== 1
            || !is_array($package['sections'])
            || $package['sections'] === []
            || count($package['sections']) > 100) {
            return null;
        }

        foreach ($package['sections'] as $name => $section) {
            if (!is_string($name)
                || !function_exists('theme_section_name_is_valid')
                || !theme_section_name_is_valid($name)
                || !is_array($section)
                || array_keys($section) !== ['html', 'fallback', 'sha256']
                || !is_string($section['html'])
                || trim($section['html']) === ''
                || strlen($section['html']) > 1024 * 1024
                || str_contains($section['html'], '<?')
                || str_contains($section['html'], '[[widget:')
                || preg_match('/\A[a-f0-9]{64}\z/', (string)$section['sha256']) !== 1
                || !hash_equals(hash('sha256', $section['html']), (string)$section['sha256'])
                || !is_array($section['fallback'])
                || array_keys($section['fallback']) !== ['title', 'summary', 'url', 'link_label']) {
                return null;
            }
            foreach ($section['fallback'] as $value) {
                if (!is_string($value)) return null;
            }
            if (trim($section['fallback']['title']) === '' || trim($section['fallback']['summary']) === '') return null;
            if ($section['fallback']['url'] !== '' && function_exists('theme_section_safe_url')
                && theme_section_safe_url($section['fallback']['url']) === '') {
                return null;
            }
        }

        $combined = implode('', array_column($package['sections'], 'html'));
        return hash_equals(hash('sha256', $combined), (string)$package['source_sha256']) ? $package : null;
    }

    function ct_theme_section_shortcode_value(string $value): string {
        $value = trim((string)(preg_replace('/\s+/u', ' ', $value) ?? $value));
        return str_replace(['&', '"'], ['&amp;', '&quot;'], $value);
    }

    function ct_theme_section_package_composition(array $package): string {
        $lines = [];
        foreach ($package['sections'] as $name => $section) {
            $fallback = $section['fallback'];
            $attrs = ['name' => $name];
            foreach (['title', 'summary', 'url', 'link_label'] as $key) {
                if ((string)$fallback[$key] !== '') $attrs[$key] = (string)$fallback[$key];
            }
            $serialized = [];
            foreach ($attrs as $key => $value) {
                $serialized[] = $key . '="' . ct_theme_section_shortcode_value($value) . '"';
            }
            $lines[] = '[[widget:theme_section ' . implode(' ', $serialized) . ']]';
        }
        return implode("\n", $lines);
    }

    function ct_apply_theme_section_package(array $post): array {
        if (empty($post['ct_locale'])) return $post;
        $package = ct_decode_theme_section_package((string)($post['content'] ?? ''));
        if ($package === null) return $post;
        $post['ct_theme_section_package'] = $package;
        $post['content'] = ct_theme_section_package_composition($package);
        $GLOBALS['ct_current_post'] = $post;
        return $post;
    }

    function ct_is_homepage_post(PDO $pdo, int $postId): bool {
        if ($postId <= 0) return false;
        $homepage = ct_homepage_theme_post($pdo);
        return is_array($homepage) && (int)($homepage['id'] ?? 0) === $postId;
    }

    function ct_homepage_sitemap_paths(PDO $pdo): array {
        $paths = ['sitemap_homepage.xml'];
        $raw = function_exists('settings_get') ? settings_get($pdo, 'content_translation_homepage_sitemap_aliases', '') : '';
        $aliases = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
        if (is_array($aliases)) {
            foreach ($aliases as $alias) {
                $alias = trim((string)$alias, '/');
                if (preg_match('/\Asitemap_[a-z0-9_-]+\.xml\z/i', $alias) === 1 && !in_array($alias, $paths, true)) {
                    $paths[] = $alias;
                }
            }
        }
        return $paths;
    }
}

add_filter('theme_post_data', function ($post) {
    return is_array($post) ? ct_apply_theme_section_package($post) : $post;
}, 20, 1);

add_filter('theme_slot_post_data', function ($post) {
    return is_array($post) ? ct_apply_theme_section_package($post) : $post;
}, 20, 1);

add_filter('content_translation_post_translation_is_complete', function ($complete, $translation, $pdo) {
    if (!$pdo instanceof PDO || !is_array($translation)) return $complete;
    $package = ct_decode_theme_section_package((string)($translation['content'] ?? ''));
    if ($package === null) return $complete;
    $postId = (int)($translation['post_id'] ?? 0);
    $homepage = ct_is_homepage_post($pdo, $postId);
    $identityComplete = ($translation['status'] ?? 'published') === 'published'
        && trim((string)($translation['title'] ?? '')) !== ''
        && trim((string)($translation['meta_description'] ?? '')) !== ''
        && ($homepage || trim((string)($translation['slug'] ?? '')) !== '');
    return $identityComplete;
}, 20, 3);

add_filter('content_translation_slug_is_reserved', function ($reserved, $postId, $locale, $slug, $pdo) {
    if (!$pdo instanceof PDO || !function_exists('content_route_find_canonical')) return $reserved;
    try {
        $route = content_route_find_canonical($pdo, (int)$postId, (string)$locale);
    } catch (Throwable $e) {
        return $reserved;
    }
    return is_array($route) && trim((string)$route['path'], '/') === trim((string)$slug, '/') ? false : $reserved;
}, 20, 6);

add_filter('theme_section_attrs', function ($attrs, $name, $definition, $context) {
    if (!is_array($attrs) || !is_array($context)) return $attrs;
    foreach (['title', 'summary', 'url', 'link_label'] as $key) {
        if (isset($attrs[$key]) && is_string($attrs[$key])) {
            $attrs[$key] = html_entity_decode($attrs[$key], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
    }
    $post = $context['post'] ?? null;
    $package = is_array($post) ? ($post['ct_theme_section_package'] ?? null) : null;
    $fallback = is_array($package) ? ($package['sections'][(string)$name]['fallback'] ?? null) : null;
    if (!is_array($fallback)) return $attrs;
    foreach ($fallback as $key => $value) {
        if ($value !== '' || !array_key_exists($key, $attrs)) $attrs[$key] = $value;
    }
    return $attrs;
}, 20, 4);

add_filter('theme_section_html', function ($html, $name, $attrs, $context, $pdo, $layout) {
    if (!$pdo instanceof PDO || !is_array($context) || !is_string($layout)) return $html;
    $post = $context['post'] ?? null;
    $package = is_array($post) ? ($post['ct_theme_section_package'] ?? null) : null;
    if (!is_array($package) || !function_exists('get_active_theme_folder')) return $html;
    $owner = (string)$package['theme_folder'];
    if (get_active_theme_folder($pdo) !== $owner || !function_exists('theme_section_theme_directory')) return $html;
    $sectionRoot = theme_section_theme_directory($pdo, false, $owner);
    $layoutPath = realpath($layout);
    if (!$sectionRoot || !$layoutPath || !theme_section_path_is_within($layoutPath, $sectionRoot)) return $html;
    $translated = $package['sections'][(string)$name]['html'] ?? null;
    return is_string($translated) && trim($translated) !== '' ? $translated : $html;
}, 20, 6);

add_filter('content_permalink', function ($url, $post) {
    $pdo = $GLOBALS['pdo'] ?? null;
    if (!$pdo instanceof PDO || !is_array($post) || !ct_is_homepage_post($pdo, (int)($post['id'] ?? 0))) return $url;
    $locale = $GLOBALS['ct_request_locale'] ?? null;
    return $locale ? ct_homepage_url((string)$locale) : '/';
}, 5, 2);

add_action('init', function (): void {
    $pdo = $GLOBALS['pdo'] ?? null;
    if (!$pdo instanceof PDO) return;
    $path = trim((string)(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? ''), '/');
    $homepage = ct_homepage_theme_post($pdo);
    if (!$homepage || $path === '' || $path !== trim((string)($homepage['slug'] ?? ''), '/')) return;
    if (function_exists('redirect')) redirect('/', 301);
    header('Location: /', true, 301);
    exit;
}, 19);

add_filter('sitemap_index_entries', function ($entries, $pdo, $domain) {
    if (!is_array($entries) || !$pdo instanceof PDO) return $entries;
    $homepage = ct_homepage_theme_post($pdo);
    if (!$homepage) return $entries;
    foreach (ct_enabled_locales($pdo) as $locale) {
        if (!ct_get_published_translation($pdo, (int)$homepage['id'], $locale)) return $entries;
    }
    $entries[] = ['loc' => rtrim((string)$domain, '/') . '/sitemap_homepage.xml'];
    return $entries;
}, 20, 3);

add_action('init', function (): void {
    $pdo = $GLOBALS['pdo'] ?? null;
    if (!$pdo instanceof PDO) return;
    $path = trim((string)(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? ''), '/');
    if (!in_array($path, ct_homepage_sitemap_paths($pdo), true)) return;
    $homepage = ct_homepage_theme_post($pdo);
    if (!$homepage) return;

    $base = rtrim(ct_base_url(), '/');
    $urls = [['loc' => $base . '/', 'lastmod' => (string)($homepage['updated_at'] ?? $homepage['created_at'] ?? '')]];
    foreach (ct_enabled_locales($pdo) as $locale) {
        $translation = ct_get_published_translation($pdo, (int)$homepage['id'], $locale);
        if (!$translation) continue;
        $urls[] = [
            'loc' => $base . ct_homepage_url($locale),
            'lastmod' => (string)($translation['updated_at'] ?? $homepage['updated_at'] ?? ''),
        ];
    }

    header('Content-Type: application/xml; charset=utf-8');
    header('Cache-Control: public, max-age=3600');
    echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
    foreach ($urls as $url) {
        echo '  <url><loc>' . htmlspecialchars((string)$url['loc'], ENT_XML1) . '</loc>';
        if ($url['lastmod'] !== '') echo '<lastmod>' . htmlspecialchars(date('c', strtotime((string)$url['lastmod'])), ENT_XML1) . '</lastmod>';
        echo '</url>' . "\n";
    }
    echo '</urlset>';
    exit;
}, 18);
