<?php
declare(strict_types=1);

// Physical directory pages can register translation-aware routes without
// coupling the generic plugin to a site's dispatcher or templates.

if (!function_exists('ct_register_directory_page')) {
    function ct_normalize_directory_page(array $definition): ?array {
        $id = trim((string)($definition['id'] ?? ''));
        $path = trim((string)($definition['path'] ?? ''), '/');
        $label = trim((string)($definition['label'] ?? ''));
        $themeFolder = trim((string)($definition['theme_folder'] ?? ''));
        $slotKey = trim((string)($definition['slot_key'] ?? ''));
        if (!preg_match('/^[A-Za-z0-9._:-]{1,190}$/', $id)
            || ($path !== '' && !preg_match('#^[a-z0-9][a-z0-9_-]*(?:/[a-z0-9][a-z0-9_-]*)*$#', $path))
            || strlen($path) > 512 || $label === '' || strlen($label) > 255
            || !preg_match('/^[A-Za-z0-9_-]{1,100}$/', $themeFolder)
            || !preg_match('/^[A-Za-z0-9._-]{1,150}$/', $slotKey)) {
            return null;
        }

        $requiredMetadata = array_values(array_unique(array_intersect(
            ['seo_title', 'meta_description'],
            is_array($definition['required_metadata'] ?? null) ? $definition['required_metadata'] : []
        )));
        return [
            'id' => $id,
            'path' => $path,
            'label' => $label,
            'theme_folder' => $themeFolder,
            'slot_key' => $slotKey,
            'source_title' => trim((string)($definition['source_title'] ?? $label)),
            'source_meta_description' => trim((string)($definition['source_meta_description'] ?? '')),
            'required_metadata' => $requiredMetadata,
        ];
    }

    function ct_register_directory_page(array $definition): bool {
        $page = ct_normalize_directory_page($definition);
        if ($page === null) return false;
        $registry = is_array($GLOBALS['ct_directory_pages_registry'] ?? null)
            ? $GLOBALS['ct_directory_pages_registry']
            : [];
        foreach ($registry as $existing) {
            if (($existing['id'] ?? '') === $page['id'] || ($existing['path'] ?? '') === $page['path']) return false;
            if (($existing['theme_folder'] ?? '') === $page['theme_folder'] && ($existing['slot_key'] ?? '') === $page['slot_key']) return false;
        }
        $registry[$page['id']] = $page;
        $GLOBALS['ct_directory_pages_registry'] = $registry;
        return true;
    }

    function ct_directory_pages(PDO $pdo): array {
        $registered = is_array($GLOBALS['ct_directory_pages_registry'] ?? null)
            ? $GLOBALS['ct_directory_pages_registry']
            : [];
        $filtered = function_exists('apply_filters')
            ? apply_filters('content_translation_directory_pages', $registered, $pdo)
            : $registered;
        if (!is_array($filtered)) return $registered;

        $pages = [];
        $paths = [];
        $resources = [];
        foreach ($filtered as $definition) {
            if (!is_array($definition)) continue;
            $page = ct_normalize_directory_page($definition);
            if ($page === null || isset($pages[$page['id']]) || isset($paths[$page['path']])) continue;
            $resourceId = ct_theme_file_resource_id($page['theme_folder'], $page['slot_key']);
            if (isset($resources[$resourceId])) continue;
            $pages[$page['id']] = $page;
            $paths[$page['path']] = true;
            $resources[$resourceId] = true;
        }
        return $pages;
    }

    function ct_find_directory_page(PDO $pdo, string $path): ?array {
        $normalized = trim(rawurldecode($path), '/');
        foreach (ct_directory_pages($pdo) as $page) {
            if ($page['path'] === $normalized) return $page;
        }
        return null;
    }

    function ct_directory_page_route_is_available(PDO $pdo, array $page, ?string $locale = null): bool {
        $path = trim((string)($page['path'] ?? ''), '/');
        if ($path === '') return true;
        $firstSegment = (string)strtok($path, '/');
        $reservedFirstSegments = array_values(array_unique(array_merge(
            function_exists('get_posts_list_routes') ? get_posts_list_routes($pdo) : ['artikel'],
            function_exists('get_pages_list_routes') ? get_pages_list_routes($pdo) : ['halaman'],
            function_exists('get_category_routes') ? get_category_routes($pdo) : ['category'],
            ct_enabled_locales($pdo),
            ['author']
        )));
        if (in_array($firstSegment, $reservedFirstSegments, true) || preg_match('/^\d{4}$/', $firstSegment)) return false;

        $stmt = $pdo->prepare("SELECT 1 FROM posts WHERE slug = ? AND status = 'published' AND is_deleted = 0 LIMIT 1");
        $stmt->execute([$path]);
        if ($stmt->fetchColumn()) return false;
        if ($locale === null) return true;

        ct_ensure_schema($pdo);
        $stmt = $pdo->prepare("SELECT * FROM post_translations WHERE locale = ? AND slug = ? AND status = 'published' LIMIT 1");
        $stmt->execute([$locale, $path]);
        $translation = $stmt->fetch(PDO::FETCH_ASSOC);
        return !$translation || !ct_post_translation_is_complete($pdo, $translation);
    }

    function ct_current_directory_page(): ?array {
        $page = $GLOBALS['ct_current_directory_page'] ?? null;
        return is_array($page) ? $page : null;
    }

    function ct_current_directory_page_translation(): ?array {
        $translation = $GLOBALS['ct_current_directory_page_translation'] ?? null;
        return is_array($translation) ? $translation : null;
    }

    function ct_is_localized_directory_page(): bool {
        return !empty($GLOBALS['ct_directory_page_localized']) && ct_current_directory_page() !== null;
    }

    function ct_get_published_directory_page_translation(PDO $pdo, array $page, string $locale): ?array {
        $resource = ct_theme_file_resource($pdo, (string)$page['theme_folder'], (string)$page['slot_key']);
        if (!$resource) return null;
        $translation = ct_get_theme_file_translation($pdo, (string)$page['theme_folder'], (string)$page['slot_key'], $locale);
        if (!$translation || ($translation['status'] ?? '') !== 'published') return null;
        return ct_theme_file_translation_is_complete($translation, $resource) ? $translation : null;
    }

    function ct_directory_page_url(array $page, ?string $locale = null): string {
        $prefix = $locale !== null && $locale !== '' && $locale !== content_default_locale()
            ? '/' . rawurlencode($locale)
            : '';
        $segments = array_filter(explode('/', trim((string)($page['path'] ?? ''), '/')));
        $path = implode('/', array_map('rawurlencode', $segments));
        return $prefix . ($path === '' ? '/' : '/' . $path . '/');
    }

    function ct_set_directory_page_context(array $page, ?array $translation, ?string $locale): void {
        if ($locale !== null && function_exists('set_locale')) set_locale($locale);
        if ($locale !== null) $GLOBALS['ct_request_locale'] = $locale;
        $GLOBALS['ct_current_directory_page'] = $page;
        $GLOBALS['ct_current_directory_page_translation'] = $translation;
        $GLOBALS['ct_directory_page_localized'] = $locale !== null;
    }
}

add_filter('router_path', function ($path) {
    $path = (string)$path;
    $pdo = $GLOBALS['pdo'] ?? null;
    if (!$pdo instanceof PDO || $path === '') return $path;

    $first = (string)strtok($path, '/');
    if (!in_array($first, ct_enabled_locales($pdo), true)) return $path;
    $rest = ltrim(substr($path, strlen($first)), '/');
    if ($rest === '' && trim((string)($_GET['s'] ?? '')) !== '') return $path;
    $page = ct_find_directory_page($pdo, $rest);
    if (!$page || !ct_directory_page_route_is_available($pdo, $page, $first)) return $path;

    $translation = ct_get_published_directory_page_translation($pdo, $page, $first);
    if (!$translation) ct_render_not_found();
    ct_set_directory_page_context($page, $translation, $first);
    return (string)$page['path'];
}, 5);

add_action('init', function (): void {
    $pdo = $GLOBALS['pdo'] ?? null;
    if (!$pdo instanceof PDO || ct_current_directory_page() !== null) return;
    $path = trim(rawurldecode((string)(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/')), '/');
    $first = $path === '' ? '' : (string)strtok($path, '/');
    if ($first !== '' && in_array($first, ct_enabled_locales($pdo), true)) return;
    if ($path === '' && trim((string)($_GET['s'] ?? '')) !== '') return;
    $page = ct_find_directory_page($pdo, $path);
    if ($page && ct_directory_page_route_is_available($pdo, $page)) ct_set_directory_page_context($page, null, null);
}, 5);

add_filter('document_title', function ($title, $pdo) {
    $page = ct_current_directory_page();
    if (!$page || !$pdo instanceof PDO) return $title;
    $translation = ct_current_directory_page_translation();
    return $translation && trim((string)($translation['seo_title'] ?? '')) !== ''
        ? $translation['seo_title']
        : ((string)$page['source_title'] ?: $title);
}, 5, 2);

add_filter('document_meta_description', function ($description, $post, $pdo) {
    $page = ct_current_directory_page();
    if (!$page || !$pdo instanceof PDO) return $description;
    $translation = ct_current_directory_page_translation();
    return $translation && trim((string)($translation['meta_description'] ?? '')) !== ''
        ? $translation['meta_description']
        : ((string)$page['source_meta_description'] ?: $description);
}, 5, 3);

add_filter('canonical_url', function ($url) {
    $page = ct_current_directory_page();
    if (!$page) return $url;
    $locale = ct_is_localized_directory_page() ? (string)($GLOBALS['ct_request_locale'] ?? '') : null;
    return ct_base_url() . ct_directory_page_url($page, $locale);
}, 5);

add_action('jy_head', function (): void {
    $page = ct_current_directory_page();
    $pdo = $GLOBALS['pdo'] ?? null;
    if (!$page || !$pdo instanceof PDO) return;
    $base = ct_base_url();
    $defaultLocale = content_default_locale();
    if (ct_directory_page_route_is_available($pdo, $page)) {
        echo '<link rel="alternate" hreflang="' . htmlspecialchars($defaultLocale, ENT_QUOTES) . '" href="' . htmlspecialchars($base . ct_directory_page_url($page), ENT_QUOTES) . '">' . "\n";
        echo '<link rel="alternate" hreflang="x-default" href="' . htmlspecialchars($base . ct_directory_page_url($page), ENT_QUOTES) . '">' . "\n";
    }
    foreach (ct_enabled_locales($pdo) as $locale) {
        if (!ct_directory_page_route_is_available($pdo, $page, $locale)
            || !ct_get_published_directory_page_translation($pdo, $page, $locale)) continue;
        echo '<link rel="alternate" hreflang="' . htmlspecialchars($locale, ENT_QUOTES) . '" href="' . htmlspecialchars($base . ct_directory_page_url($page, $locale), ENT_QUOTES) . '">' . "\n";
    }
}, 5);
