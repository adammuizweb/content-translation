<?php
declare(strict_types=1);

// Content Translation — shared helpers

require_once __DIR__ . '/workflow-migration.php';

function ct_current_user_id(): int {
    return function_exists('current_user_id') ? (int)current_user_id() : (int)($_SESSION['user_id'] ?? 0);
}

function ct_user_can_workspace(PDO $pdo, ?int $userId = null): bool {
    $userId ??= ct_current_user_id();
    return $userId > 0 && function_exists('user_can')
        && user_can($pdo, $userId, 'plugin.content-translation.workspace.access');
}

function ct_user_can_integration(PDO $pdo, string $corePermission, ?int $userId = null): bool {
    $userId ??= ct_current_user_id();
    return ct_user_can_workspace($pdo, $userId) && user_can($pdo, $userId, $corePermission);
}

function ct_user_is_site_owner(PDO $pdo, ?int $userId = null): bool {
    $userId ??= ct_current_user_id();
    $actor = $userId > 0 && function_exists('authorization_actor') ? authorization_actor($pdo, $userId) : null;
    return $actor !== null && $actor['is_site_owner'] === true;
}

function ct_post_permission_key(array $post, string $action): ?string {
    return match ((string)($post['type'] ?? '')) {
        'article' => 'core.posts.' . $action,
        'page' => 'core.pages.' . $action,
        'theme' => 'core.theme_content.' . $action,
        default => null,
    };
}

function ct_user_can_translate_post(PDO $pdo, array $post, string $action = 'update', ?int $userId = null): bool {
    $userId ??= ct_current_user_id();
    if (!ct_user_can_workspace($pdo, $userId)) return false;
    if (($post['type'] ?? '') === 'theme' && !ct_user_is_site_owner($pdo, $userId)) return false;
    $permission = ct_post_permission_key($post, $action);
    return $permission !== null && user_can($pdo, $userId, $permission, ['owner_id' => (int)($post['created_by'] ?? 0)]);
}

function ct_user_can_publish_post_translation(PDO $pdo, array $post, ?int $userId = null): bool {
    if (($post['type'] ?? '') === 'theme') return ct_user_is_site_owner($pdo, $userId);
    return ct_user_can_translate_post($pdo, $post, 'publish', $userId);
}

function ct_sanitize_translation_content(PDO $pdo, array $post, string $content, ?int $userId = null): string {
    $userId ??= ct_current_user_id();
    $type = (string)($post['type'] ?? '');
    $permission = $type === 'article' ? 'core.posts.unfiltered_html' : ($type === 'page' ? 'core.pages.unfiltered_html' : '');
    if ($permission === '' || user_can($pdo, $userId, $permission)) return $content;
    return function_exists('cms_sanitize_restricted_html') ? cms_sanitize_restricted_html($content) : strip_tags($content);
}

if (!function_exists('ct_ensure_schema')) {

    function ct_schema_error(PDO $pdo): ?string {
        $error = $GLOBALS['_ct_schema_errors'][spl_object_id($pdo)] ?? null;
        return is_string($error) && $error !== '' ? $error : null;
    }

    function ct_report_schema_error(PDO $pdo, Throwable $error): void {
        $GLOBALS['_ct_schema_errors'][spl_object_id($pdo)] = $error->getMessage();
        error_log('[content-translation] storage error: ' . $error->getMessage());
    }

    function ct_ensure_schema(PDO $pdo): bool {
        static $done = [];
        $connectionId = spl_object_id($pdo);
        if (isset($done[$connectionId])) return true;
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS post_translations (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                post_id INT UNSIGNED NOT NULL,
                locale VARCHAR(16) NOT NULL,
                title VARCHAR(255) NOT NULL DEFAULT '',
                slug VARCHAR(255) NOT NULL DEFAULT '',
                content MEDIUMTEXT NULL,
                status ENUM('draft','published') NOT NULL DEFAULT 'published',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_post_locale (post_id, locale),
                KEY idx_locale_slug (locale, slug)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            ct_add_column_if_missing($pdo, 'post_translations', 'status', "ENUM('draft','published') NOT NULL DEFAULT 'published'");
            ct_add_column_if_missing($pdo, 'post_translations', 'meta_description', "VARCHAR(320) NOT NULL DEFAULT ''");
            $pdo->exec("CREATE TABLE IF NOT EXISTS ct_post_workflows (
                post_id INT UNSIGNED NOT NULL PRIMARY KEY,
                source_locale VARCHAR(16) NOT NULL,
                author_locale VARCHAR(16) NOT NULL,
                source_status ENUM('draft','published','private') NOT NULL DEFAULT 'draft',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                KEY idx_ct_workflow_author_locale (author_locale),
                KEY idx_ct_workflow_source_status (source_status),
                CONSTRAINT fk_ct_workflow_post FOREIGN KEY (post_id) REFERENCES posts(id) ON DELETE CASCADE ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            $pdo->exec("CREATE TABLE IF NOT EXISTS site_translations (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                locale VARCHAR(16) NOT NULL,
                title VARCHAR(255) NOT NULL DEFAULT '',
                description TEXT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_site_locale (locale)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            $pdo->exec("CREATE TABLE IF NOT EXISTS category_translations (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                category_id INT UNSIGNED NOT NULL,
                locale VARCHAR(16) NOT NULL,
                name VARCHAR(255) NOT NULL DEFAULT '',
                slug VARCHAR(255) NOT NULL DEFAULT '',
                description TEXT NULL,
                status ENUM('draft','published') NOT NULL DEFAULT 'published',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_category_locale (category_id, locale),
                KEY idx_category_locale_slug (locale, slug)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            $pdo->exec("CREATE TABLE IF NOT EXISTS menu_item_translations (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                menu_item_id INT UNSIGNED NOT NULL,
                locale VARCHAR(16) NOT NULL,
                label VARCHAR(255) NOT NULL DEFAULT '',
                url VARCHAR(2048) NOT NULL DEFAULT '',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_menu_item_locale (menu_item_id, locale)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            $pdo->exec("CREATE TABLE IF NOT EXISTS sidebar_item_translations (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                sidebar_item_id INT UNSIGNED NOT NULL,
                locale VARCHAR(16) NOT NULL,
                title VARCHAR(255) NOT NULL DEFAULT '',
                config MEDIUMTEXT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_sidebar_item_locale (sidebar_item_id, locale)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            $pdo->exec("CREATE TABLE IF NOT EXISTS author_profile_translations (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                user_id INT UNSIGNED NOT NULL,
                locale VARCHAR(16) NOT NULL,
                bio TEXT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_author_profile_locale (user_id, locale)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            $pdo->exec("CREATE TABLE IF NOT EXISTS theme_file_translations (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                theme_folder VARCHAR(100) NOT NULL,
                slot_key VARCHAR(150) NOT NULL,
                locale VARCHAR(16) NOT NULL,
                values_json MEDIUMTEXT NOT NULL,
                seo_title VARCHAR(255) NOT NULL DEFAULT '',
                meta_description VARCHAR(320) NOT NULL DEFAULT '',
                status ENUM('draft','published') NOT NULL DEFAULT 'draft',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_theme_file_locale (theme_folder, slot_key, locale)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            $pdo->exec("CREATE TABLE IF NOT EXISTS ct_theme_zone_item_translations (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                theme_zone_item_id INT UNSIGNED NOT NULL,
                locale VARCHAR(16) NOT NULL,
                values_json MEDIUMTEXT NOT NULL,
                source_fingerprint CHAR(64) NOT NULL,
                status ENUM('draft','published') NOT NULL DEFAULT 'draft',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_ct_theme_zone_locale (theme_zone_item_id, locale),
                KEY idx_ct_theme_zone_status (locale, status),
                KEY idx_ct_theme_zone_source (source_fingerprint)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            $pdo->exec("CREATE TABLE IF NOT EXISTS ct_theme_string_translations (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                theme_folder VARCHAR(100) NOT NULL,
                scope VARCHAR(50) NOT NULL DEFAULT 'default',
                source_hash CHAR(64) NOT NULL,
                source TEXT NOT NULL,
                locale VARCHAR(16) NOT NULL,
                value TEXT NOT NULL,
                status ENUM('draft','published') NOT NULL DEFAULT 'draft',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_ct_theme_string (theme_folder, scope, source_hash, locale),
                KEY idx_ct_theme_string_status (theme_folder, locale, status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            $pdo->exec("CREATE TABLE IF NOT EXISTS ct_theme_section_translation_meta (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                post_id INT UNSIGNED NOT NULL,
                locale VARCHAR(16) NOT NULL,
                source_fingerprint CHAR(64) NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_ct_section_meta (post_id, locale),
                KEY idx_ct_section_source (source_fingerprint)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            $pdo->exec("CREATE TABLE IF NOT EXISTS shortcode_preset_translations (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                preset_id INT UNSIGNED NOT NULL,
                locale VARCHAR(16) NOT NULL,
                title VARCHAR(191) NOT NULL DEFAULT '',
                overrides_json TEXT NOT NULL,
                status ENUM('draft','published') NOT NULL DEFAULT 'draft',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_shortcode_preset_locale (preset_id, locale),
                KEY idx_shortcode_preset_locale_status (locale, status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            $pdo->exec("CREATE TABLE IF NOT EXISTS ct_ui_translation_seeds (
                scope VARCHAR(50) NOT NULL,
                source_hash CHAR(64) NOT NULL,
                source TEXT NOT NULL,
                locale VARCHAR(16) NOT NULL,
                value TEXT NOT NULL,
                PRIMARY KEY (scope, source_hash, locale)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            foreach ([
                'post_translations',
                'site_translations',
                'category_translations',
                'menu_item_translations',
                'sidebar_item_translations',
                'author_profile_translations',
                'theme_file_translations',
                'ct_theme_zone_item_translations',
                'ct_theme_string_translations',
                'ct_theme_section_translation_meta',
                'shortcode_preset_translations',
            ] as $table) {
                ct_expand_locale_column($pdo, $table);
            }
            ct_130_migrate_legacy_authored_posts(
                $pdo,
                function_exists('content_default_locale') ? content_default_locale() : 'en'
            );
            $done[$connectionId] = true;
            unset($GLOBALS['_ct_schema_errors'][$connectionId]);
            return true;
        } catch (Throwable $e) {
            ct_report_schema_error($pdo, $e);
            return false;
        }
    }

    function ct_add_column_if_missing(PDO $pdo, string $table, string $column, string $definition): void {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
        $stmt->execute([$table, $column]);
        if ((int)$stmt->fetchColumn() === 0) {
            $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
        }
    }

    function ct_expand_locale_column(PDO $pdo, string $table): void {
        if (!preg_match('/^[a-z0-9_]+$/', $table)) throw new InvalidArgumentException('Invalid translation table.');
        $stmt = $pdo->prepare('SELECT CHARACTER_MAXIMUM_LENGTH FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
        $stmt->execute([$table, 'locale']);
        $length = (int)$stmt->fetchColumn();
        if ($length > 0 && $length < 16) {
            $pdo->exec("ALTER TABLE `{$table}` MODIFY COLUMN `locale` VARCHAR(16) NOT NULL");
        }
    }

    function ct_enabled_locales(PDO $pdo): array {
        $raw = function_exists('settings_get') ? settings_get($pdo, 'content_translation_locales', '') : '';
        $locales = [];
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $locales = array_values(array_filter(array_map('strval', $decoded), fn($l) => $l !== ''));
            }
        }
        if (empty($locales)) {
            $supported = function_exists('get_supported_locales') ? get_supported_locales() : ['en'];
            $default = function_exists('content_default_locale') ? content_default_locale() : (function_exists('default_locale') ? default_locale() : 'en');
            $locales = array_values(array_diff($supported, [$default]));
        }
        return $locales;
    }

    function ct_set_enabled_locales(PDO $pdo, array $locales): bool {
        $supported = function_exists('get_supported_locales') ? get_supported_locales() : [];
        $default = function_exists('content_default_locale') ? content_default_locale() : (function_exists('default_locale') ? default_locale() : 'en');
        $clean = [];
        foreach ($locales as $l) {
            $l = trim((string)$l);
            if ($l === '' || $l === $default) continue;
            if (!empty($supported) && !in_array($l, $supported, true)) continue;
            $clean[] = $l;
        }
        $clean = array_values(array_unique($clean));
        if (!function_exists('settings_set')) return false;
        return settings_set($pdo, 'content_translation_locales', json_encode($clean));
    }

    function ct_author_locale_preferences(PDO $pdo): array {
        $raw = function_exists('settings_get') ? settings_get($pdo, 'content_translation_author_locales', '') : '';
        $stored = is_string($raw) ? json_decode($raw, true) : [];
        if (!is_array($stored)) return [];

        $default = function_exists('content_default_locale') ? content_default_locale() : 'en';
        $enabled = ct_enabled_locales($pdo);
        $preferences = [];
        foreach ($stored as $userId => $locale) {
            $userId = (int)$userId;
            $locale = trim((string)$locale);
            if ($userId <= 0 || $locale === '' || $locale === $default || !in_array($locale, $enabled, true)) continue;
            $preferences[$userId] = $locale;
        }
        return $preferences;
    }

    function ct_author_default_locale(PDO $pdo, int $userId): string {
        $default = function_exists('content_default_locale') ? content_default_locale() : 'en';
        if ($userId <= 0 || !ct_user_can_workspace($pdo, $userId)) return $default;
        $preferences = ct_author_locale_preferences($pdo);
        return $preferences[$userId] ?? $default;
    }

    function ct_set_author_locale_preferences(PDO $pdo, array $preferences): bool {
        $default = function_exists('content_default_locale') ? content_default_locale() : 'en';
        $enabled = ct_enabled_locales($pdo);
        $clean = [];
        foreach ($preferences as $userId => $locale) {
            $userId = (int)$userId;
            $locale = trim((string)$locale);
            if ($userId <= 0 || !ct_user_can_workspace($pdo, $userId)
                || $locale === '' || $locale === $default || !in_array($locale, $enabled, true)) continue;
            $clean[(string)$userId] = $locale;
        }
        return function_exists('settings_set')
            && settings_set($pdo, 'content_translation_author_locales', json_encode($clean, JSON_UNESCAPED_SLASHES));
    }

    function ct_post_workflow(PDO $pdo, int $postId): ?array {
        if ($postId <= 0) return null;
        $stmt = $pdo->prepare('SELECT * FROM ct_post_workflows WHERE post_id = ? LIMIT 1');
        $stmt->execute([$postId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    function ct_post_authoring_locale(PDO $pdo, array $post): ?string {
        $workflow = ct_post_workflow($pdo, (int)($post['id'] ?? 0));
        if ($workflow) {
            $locale = trim((string)($workflow['author_locale'] ?? ''));
            $default = function_exists('content_default_locale') ? content_default_locale() : 'en';
            return $locale !== '' && $locale !== $default ? $locale : null;
        }

        static $cache = [];
        $postId = (int)($post['id'] ?? 0);
        if (!array_key_exists('meta', $post) && $postId > 0) {
            if (!array_key_exists($postId, $cache)) {
                $stmt = $pdo->prepare('SELECT meta FROM posts WHERE id = ? LIMIT 1');
                $stmt->execute([$postId]);
                $cache[$postId] = $stmt->fetchColumn();
            }
            $post['meta'] = $cache[$postId];
        }

        $meta = $post['meta'] ?? null;
        if (is_string($meta) && $meta !== '') $meta = json_decode($meta, true);
        if (!is_array($meta)) return null;
        $locale = trim((string)($meta['content_translation']['authoring_locale'] ?? ''));
        $default = function_exists('content_default_locale') ? content_default_locale() : 'en';
        return $locale !== '' && $locale !== $default ? $locale : null;
    }

    function ct_content_locales(PDO $pdo): array {
        $default = function_exists('content_default_locale') ? content_default_locale() : 'en';
        return array_values(array_unique(array_merge([$default], ct_enabled_locales($pdo))));
    }

    function ct_post_source_locale(PDO $pdo, array $post): string {
        return function_exists('content_default_locale') ? content_default_locale() : 'en';
    }

    function ct_post_translation_locales(PDO $pdo, array $post): array {
        $sourceLocale = ct_post_source_locale($pdo, $post);
        return array_values(array_filter(
            ct_content_locales($pdo),
            static fn(string $locale): bool => $locale !== $sourceLocale
        ));
    }

    function ct_translation_slug_lock_name(string $locale, string $slug): string {
        return 'ct_slug_' . substr(hash('sha256', $locale . ':' . $slug), 0, 56);
    }

    function ct_translation_slug_conflict(PDO $pdo, int $postId, string $locale, string $slug): ?string {
        $stmt = $pdo->prepare('SELECT post_id FROM post_translations WHERE locale = ? AND slug = ? AND post_id != ? LIMIT 1');
        $stmt->execute([$locale, $slug, $postId]);
        if ($stmt->fetchColumn()) return 'translation';

        if (function_exists('content_route_path_conflict')) {
            $routeLocale = $locale === (function_exists('content_default_locale') ? content_default_locale() : 'en') ? '' : $locale;
            if (content_route_path_conflict($pdo, $slug, $routeLocale, $postId) !== null) return 'route';

            // An alias owned by this post would redirect to its canonical route,
            // while post_data redirects back to the translated slug.
            $route = $pdo->prepare('SELECT is_canonical FROM content_routes WHERE post_id = ? AND locale = ? AND path = ? LIMIT 1');
            $route->execute([$postId, $routeLocale, $slug]);
            $isCanonical = $route->fetchColumn();
            if ($isCanonical !== false && (int)$isCanonical !== 1) return 'route';
        }

        return null;
    }

    function ct_post_authoring_locales_in_use(PDO $pdo): array {
        $stmt = $pdo->query('SELECT DISTINCT author_locale FROM ct_post_workflows');
        $locales = $stmt->fetchAll(PDO::FETCH_COLUMN);
        $stmt = $pdo->query("SELECT DISTINCT JSON_UNQUOTE(JSON_EXTRACT(meta, '$.content_translation.authoring_locale')) AS locale FROM posts WHERE type = 'article' AND is_deleted = 0 AND JSON_VALID(meta) AND JSON_EXTRACT(meta, '$.content_translation.authoring_locale') IS NOT NULL");
        $locales = array_merge($locales, $stmt->fetchAll(PDO::FETCH_COLUMN));
        return array_values(array_unique(array_filter(array_map(
            static fn(mixed $locale): string => trim((string)$locale),
            $locales
        ))));
    }

    function ct_post_source_status(PDO $pdo, array|int $post): string {
        $postId = is_array($post) ? (int)($post['id'] ?? 0) : $post;
        $workflow = ct_post_workflow($pdo, $postId);
        if ($workflow) return (string)$workflow['source_status'];
        if (is_array($post) && isset($post['status'])) return (string)$post['status'];
        $stmt = $pdo->prepare('SELECT status FROM posts WHERE id = ? LIMIT 1');
        $stmt->execute([$postId]);
        return (string)($stmt->fetchColumn() ?: 'draft');
    }

    function ct_post_effective_status(PDO $pdo, int $postId, ?string $sourceStatus = null): string {
        $sourceStatus ??= ct_post_source_status($pdo, $postId);
        if ($sourceStatus === 'published') return 'published';

        $stmt = $pdo->prepare("SELECT 1 FROM post_translations
            WHERE post_id = ? AND status = 'published' AND TRIM(title) <> '' AND TRIM(slug) <> '' LIMIT 1");
        $stmt->execute([$postId]);
        if ($stmt->fetchColumn()) return 'published';
        return $sourceStatus === 'private' ? 'private' : 'draft';
    }

    function ct_recompute_post_effective_status(PDO $pdo, int $postId): bool {
        if (ct_post_workflow($pdo, $postId) === null) return true;
        $stmt = $pdo->prepare('UPDATE posts SET status = ? WHERE id = ?');
        return $stmt->execute([ct_post_effective_status($pdo, $postId), $postId]);
    }

    function ct_save_post_workflow(PDO $pdo, int $postId, string $sourceLocale, string $authorLocale, string $sourceStatus): bool {
        if (!in_array($sourceStatus, ['draft', 'published', 'private'], true)) return false;
        $stmt = $pdo->prepare("INSERT INTO ct_post_workflows (post_id, source_locale, author_locale, source_status)
            VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE source_locale = VALUES(source_locale), author_locale = VALUES(author_locale), source_status = VALUES(source_status)");
        return $stmt->execute([$postId, $sourceLocale, $authorLocale, $sourceStatus]);
    }

    function ct_update_post_source_status(PDO $pdo, int $postId, string $sourceStatus): bool {
        if (!in_array($sourceStatus, ['draft', 'published', 'private'], true)) return false;
        $stmt = $pdo->prepare('UPDATE ct_post_workflows SET source_status = ? WHERE post_id = ?');
        if (!$stmt->execute([$sourceStatus, $postId]) || ct_post_workflow($pdo, $postId) === null) return false;
        return ct_recompute_post_effective_status($pdo, $postId);
    }

    function ct_source_post_is_public(PDO $pdo, array $post): bool {
        return ct_post_source_status($pdo, $post) === 'published';
    }

    function ct_post_workflow_is_pending(PDO $pdo, int $postId): bool {
        $workflow = ct_post_workflow($pdo, $postId);
        return $workflow !== null && (string)$workflow['source_status'] !== 'published';
    }

    function ct_pending_source_slug(PDO $pdo, int $postId, string $locale): string {
        $base = 'ct-pending-' . strtolower($locale) . '-' . $postId;
        $postConflict = $pdo->prepare('SELECT id FROM posts WHERE slug = ? AND id != ? AND is_deleted = 0 LIMIT 1');
        for ($suffix = 0; $suffix < 100; $suffix++) {
            $slug = $suffix === 0 ? $base : $base . '-' . $suffix;
            $postConflict->execute([$slug, $postId]);
            if (!$postConflict->fetchColumn() && ct_translation_slug_conflict($pdo, $postId, $locale, $slug) === null) {
                return $slug;
            }
        }
        throw new RuntimeException('Canonical source placeholder route is unavailable.');
    }

    function ct_post_locale_is_published(PDO $pdo, array $post, string $locale): bool {
        $locale = trim($locale);
        if (!in_array($locale, ct_content_locales($pdo), true)) return false;

        $sourceLocale = ct_post_source_locale($pdo, $post);
        if ($locale !== $sourceLocale) {
            return ct_get_public_post_translation($pdo, (int)($post['id'] ?? 0), $locale) !== null;
        }
        return ct_source_post_is_public($pdo, $post);
    }

    function ct_post_meta_with_authoring_locale(array $post, string $locale): string {
        $meta = $post['meta'] ?? null;
        if (is_string($meta) && $meta !== '') $meta = json_decode($meta, true);
        if (!is_array($meta)) $meta = [];
        if (!is_array($meta['content_translation'] ?? null)) $meta['content_translation'] = [];
        $meta['content_translation']['authoring_locale'] = $locale;
        return json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    function ct_default_locale_direction(string $locale): string {
        $language = strtolower((string)strtok($locale, '-'));
        return in_array($language, ['ar', 'fa', 'he', 'ps', 'ur', 'yi'], true) ? 'rtl' : 'ltr';
    }

    function ct_locale_directions(PDO $pdo): array {
        $raw = function_exists('settings_get') ? settings_get($pdo, 'content_translation_locale_directions', '') : '';
        $stored = is_string($raw) ? json_decode($raw, true) : [];
        $directions = [];
        $default = function_exists('content_default_locale') ? content_default_locale() : (function_exists('default_locale') ? default_locale() : 'en');
        foreach (array_values(array_unique(array_merge([$default], ct_enabled_locales($pdo)))) as $locale) {
            $direction = is_array($stored) ? ($stored[$locale] ?? null) : null;
            $directions[$locale] = in_array($direction, ['ltr', 'rtl'], true)
                ? $direction
                : ct_default_locale_direction($locale);
        }
        return $directions;
    }

    function ct_locale_direction(PDO $pdo, string $locale): string {
        $raw = function_exists('settings_get') ? settings_get($pdo, 'content_translation_locale_directions', '') : '';
        $stored = is_string($raw) ? json_decode($raw, true) : [];
        $direction = is_array($stored) ? ($stored[$locale] ?? null) : null;
        return in_array($direction, ['ltr', 'rtl'], true) ? $direction : ct_default_locale_direction($locale);
    }

    function ct_set_locale_directions(PDO $pdo, array $directions): bool {
        $clean = [];
        $default = function_exists('content_default_locale') ? content_default_locale() : (function_exists('default_locale') ? default_locale() : 'en');
        foreach (array_values(array_unique(array_merge([$default], ct_enabled_locales($pdo)))) as $locale) {
            $direction = $directions[$locale] ?? ct_default_locale_direction($locale);
            $clean[$locale] = $direction === 'rtl' ? 'rtl' : 'ltr';
        }
        return function_exists('settings_set') && settings_set($pdo, 'content_translation_locale_directions', json_encode($clean));
    }

    function ct_sitemap_locales(PDO $pdo): array {
        $raw = function_exists('settings_get') ? settings_get($pdo, 'content_translation_sitemap_locales', '') : '';
        $selected = is_string($raw) ? json_decode($raw, true) : [];
        return array_values(array_intersect(ct_enabled_locales($pdo), is_array($selected) ? array_map('strval', $selected) : []));
    }

    function ct_set_sitemap_locales(PDO $pdo, array $locales): bool {
        $clean = array_values(array_intersect(ct_enabled_locales($pdo), array_map('strval', $locales)));
        return function_exists('settings_set') && settings_set($pdo, 'content_translation_sitemap_locales', json_encode($clean));
    }

    function ct_site_translation(PDO $pdo, string $locale): ?array {
        ct_ensure_schema($pdo);
        $stmt = $pdo->prepare('SELECT * FROM site_translations WHERE locale = ? LIMIT 1');
        $stmt->execute([$locale]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    function ct_save_site_translation(PDO $pdo, string $locale, string $title, string $description): bool {
        if (!in_array($locale, ct_enabled_locales($pdo), true)) return false;
        ct_ensure_schema($pdo);
        $stmt = $pdo->prepare('INSERT INTO site_translations (locale, title, description) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE title = VALUES(title), description = VALUES(description)');
        return $stmt->execute([$locale, $title, $description]);
    }

    function ct_export_data(PDO $pdo): array {
        ct_ensure_schema($pdo);
        $tables = [
            'post_translations' => 'SELECT pt.*, p.type AS source_type, p.slug AS source_slug, p.title AS source_title FROM post_translations pt INNER JOIN posts p ON p.id = pt.post_id ORDER BY pt.post_id, pt.locale',
            'post_workflows' => 'SELECT * FROM ct_post_workflows ORDER BY post_id',
            'category_translations' => 'SELECT ct.*, c.slug AS source_slug, c.name AS source_name FROM category_translations ct INNER JOIN categories c ON c.id = ct.category_id ORDER BY ct.category_id, ct.locale',
            'menu_item_translations' => 'SELECT mit.*, mi.menu_id FROM menu_item_translations mit INNER JOIN menu_items mi ON mi.id = mit.menu_item_id ORDER BY mit.menu_item_id, mit.locale',
            'sidebar_item_translations' => 'SELECT * FROM sidebar_item_translations ORDER BY sidebar_item_id, locale',
            'author_profile_translations' => 'SELECT apt.*, u.email AS source_email FROM author_profile_translations apt INNER JOIN users u ON u.id = apt.user_id ORDER BY apt.user_id, apt.locale',
            'site_translations' => 'SELECT * FROM site_translations ORDER BY locale',
            'theme_file_translations' => 'SELECT * FROM theme_file_translations ORDER BY theme_folder, slot_key, locale',
            'theme_zone_item_translations' => 'SELECT * FROM ct_theme_zone_item_translations ORDER BY theme_zone_item_id, locale',
            'theme_string_translations' => 'SELECT * FROM ct_theme_string_translations ORDER BY theme_folder, scope, source_hash, locale',
            'theme_section_translation_metadata' => 'SELECT post_id, locale, source_fingerprint, created_at, updated_at FROM ct_theme_section_translation_meta ORDER BY post_id, locale',
            'shortcode_preset_translations' => "SELECT spt.*, p.slug AS source_slug, p.title AS source_title FROM shortcode_preset_translations spt INNER JOIN posts p ON p.id = spt.preset_id AND p.type = 'sc_preset' AND p.is_deleted = 0 ORDER BY spt.preset_id, spt.locale",
        ];
        $translations = [];
        foreach ($tables as $name => $sql) {
            $translations[$name] = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        }

        return [
            'format' => 'jyavani-content-translation-export',
            'version' => 5,
            'exported_at' => gmdate('c'),
            'settings' => [
                'enabled_locales' => ct_enabled_locales($pdo),
                'locale_directions' => ct_locale_directions($pdo),
                'sitemap_locales' => ct_sitemap_locales($pdo),
            ],
            'translations' => $translations,
        ];
    }

    function ct_get_translation(PDO $pdo, int $postId, string $locale): ?array {
        static $cache = [];
        $key = $postId . ':' . $locale;
        if (array_key_exists($key, $cache)) return $cache[$key];
        ct_ensure_schema($pdo);
        try {
            $stmt = $pdo->prepare("SELECT * FROM post_translations WHERE post_id = ? AND locale = ? LIMIT 1");
            $stmt->execute([$postId, $locale]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $cache[$key] = $row ?: null;
            return $cache[$key];
        } catch (Throwable $e) {
            error_log('[content-translation] get error: ' . $e->getMessage());
            return null;
        }
    }

    function ct_post_translation_is_complete(PDO $pdo, array $translation): bool {
        $complete = ($translation['status'] ?? 'published') === 'published'
            && trim((string)($translation['title'] ?? '')) !== ''
            && trim((string)($translation['slug'] ?? '')) !== '';
        if (function_exists('apply_filters')) {
            $complete = (bool)apply_filters(
                'content_translation_post_translation_is_complete',
                $complete,
                $translation,
                $pdo
            );
        }
        return $complete;
    }

    function ct_save_translation(PDO $pdo, int $postId, string $locale, array $data): bool {
        ct_ensure_schema($pdo);
        try {
            $status = (string)($data['status'] ?? 'published');
            $stmt = $pdo->prepare("INSERT INTO post_translations (post_id, locale, title, slug, content, meta_description, status)
                VALUES (?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE title = VALUES(title), slug = VALUES(slug), content = VALUES(content), meta_description = VALUES(meta_description), status = VALUES(status)");
            $saved = $stmt->execute([
                $postId,
                $locale,
                (string)($data['title'] ?? ''),
                (string)($data['slug'] ?? ''),
                (string)($data['content'] ?? ''),
                trim((string)($data['meta_description'] ?? '')),
                in_array($status, ['draft', 'published'], true) ? $status : 'published',
            ]);
            return $saved && ct_recompute_post_effective_status($pdo, $postId);
        } catch (Throwable $e) {
            error_log('[content-translation] save error: ' . $e->getMessage());
            return false;
        }
    }

    function ct_save_translation_locked(PDO $pdo, int $postId, string $locale, string $loadedState, array $data, int $actorId): array {
        if ($postId <= 0 || $actorId <= 0 || preg_match('/\A[a-f0-9]{64}\z/', $loadedState) !== 1
            || !function_exists('ct_translation_row_state_token')) {
            throw new InvalidArgumentException('Editor lock state is invalid. Reload the editor.');
        }
        if (!ct_ensure_schema($pdo)) throw new RuntimeException('Translation storage is unavailable.');
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) $pdo->beginTransaction();
        try {
            if (!authorization_lock_actor_permissions($pdo, $actorId)) throw new RuntimeException('Authorization state is unavailable.');
            $source = $pdo->prepare('SELECT id, type, meta, status, created_by FROM posts WHERE id = ? AND is_deleted = 0 LIMIT 1 FOR UPDATE');
            $source->execute([$postId]);
            $sourcePost = $source->fetch(PDO::FETCH_ASSOC);
            if (!$sourcePost) throw new RuntimeException('Source post no longer exists.');
            if (!in_array($locale, ct_post_translation_locales($pdo, $sourcePost), true)) {
                throw new RuntimeException('That locale is not available for this source post.');
            }
            if (!authorization_lock_owner_contexts($pdo, [(int)$sourcePost['created_by']])
                || !ct_user_can_translate_post($pdo, $sourcePost, 'update', $actorId)) {
                throw new RuntimeException('Content translation permission denied.');
            }

            $currentStmt = $pdo->prepare('SELECT * FROM post_translations WHERE post_id = ? AND locale = ? LIMIT 1 FOR UPDATE');
            $currentStmt->execute([$postId, $locale]);
            $current = $currentStmt->fetch(PDO::FETCH_ASSOC) ?: null;
            if (!hash_equals($loadedState, ct_translation_row_state_token($current))) {
                throw new RuntimeException('This translation was changed by another editor. Reload before saving.');
            }
            if (((string)($data['status'] ?? 'published') === 'published' || (string)($current['status'] ?? '') === 'published')
                && !ct_user_can_publish_post_translation($pdo, $sourcePost, $actorId)) {
                throw new RuntimeException('Publishing translation permission denied.');
            }
            if (!ct_save_translation($pdo, $postId, $locale, $data)) throw new RuntimeException('Translation save failed.');

            $savedStmt = $pdo->prepare('SELECT * FROM post_translations WHERE post_id = ? AND locale = ? LIMIT 1');
            $savedStmt->execute([$postId, $locale]);
            $saved = $savedStmt->fetch(PDO::FETCH_ASSOC) ?: null;
            if ($saved === null) throw new RuntimeException('Saved translation could not be loaded.');
            if ($ownsTransaction) $pdo->commit();
            return $saved;
        } catch (Throwable $error) {
            if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack();
            throw $error;
        }
    }

    function ct_get_published_translation(PDO $pdo, int $postId, string $locale): ?array {
        $translation = ct_get_translation($pdo, $postId, $locale);
        return $translation && ct_post_translation_is_complete($pdo, $translation) ? $translation : null;
    }

    function ct_get_public_post_translation(PDO $pdo, int $postId, string $locale): ?array {
        static $cache = [];
        $key = spl_object_id($pdo) . ':' . $postId . ':' . $locale;
        if (array_key_exists($key, $cache)) return $cache[$key];

        $translation = ct_get_published_translation($pdo, $postId, $locale);
        if (!$translation) return $cache[$key] = null;
        $slug = trim((string)($translation['slug'] ?? ''));
        if ($slug === '') return $cache[$key] = $translation;
        return $cache[$key] = (ct_translation_slug_conflict($pdo, $postId, $locale, $slug) === null ? $translation : null);
    }

    function ct_delete_translation(PDO $pdo, int $postId, string $locale, string $loadedState, int $actorId): bool {
        ct_ensure_schema($pdo);
        $ownsTransaction = !$pdo->inTransaction();
        try {
            if ($ownsTransaction) $pdo->beginTransaction();
            if ($actorId <= 0 || !authorization_lock_actor_permissions($pdo, $actorId)) throw new RuntimeException('Authorization state is unavailable.');
            $sourceStmt = $pdo->prepare('SELECT id, type, meta, status, created_by FROM posts WHERE id = ? AND is_deleted = 0 LIMIT 1 FOR UPDATE');
            $sourceStmt->execute([$postId]);
            $sourcePost = $sourceStmt->fetch(PDO::FETCH_ASSOC);
            if ($sourcePost && !in_array($locale, ct_post_translation_locales($pdo, $sourcePost), true)) {
                throw new RuntimeException('That locale is not available for this source post.');
            }
            if (!$sourcePost || !authorization_lock_owner_contexts($pdo, [(int)$sourcePost['created_by']])
                || !ct_user_can_translate_post($pdo, $sourcePost, 'update', $actorId)) {
                throw new RuntimeException('Content translation permission denied.');
            }
            $currentStmt = $pdo->prepare("SELECT * FROM post_translations WHERE post_id = ? AND locale = ? LIMIT 1 FOR UPDATE");
            $currentStmt->execute([$postId, $locale]);
            $current = $currentStmt->fetch(PDO::FETCH_ASSOC) ?: null;
            if (!function_exists('ct_translation_row_state_token')
                || preg_match('/\A[a-f0-9]{64}\z/', $loadedState) !== 1
                || !hash_equals($loadedState, ct_translation_row_state_token($current))) {
                throw new RuntimeException('This translation was changed by another editor. Reload before deleting.');
            }
            if ((string)($current['status'] ?? '') === 'published'
                && !ct_user_can_publish_post_translation($pdo, $sourcePost, $actorId)) {
                throw new RuntimeException('Publishing translation permission denied.');
            }
            $stmt = $pdo->prepare("DELETE FROM post_translations WHERE post_id = ? AND locale = ?");
            $ok = $stmt->execute([$postId, $locale]);
            $meta = $pdo->prepare("DELETE FROM ct_theme_section_translation_meta WHERE post_id = ? AND locale = ?");
            $meta->execute([$postId, $locale]);
            if ($ok && !ct_recompute_post_effective_status($pdo, $postId)) {
                throw new RuntimeException('Post visibility could not be updated.');
            }
            if ($ownsTransaction) $pdo->commit();
            return $ok;
        } catch (Throwable $e) {
            if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack();
            error_log('[content-translation] delete error: ' . $e->getMessage());
            return false;
        }
    }

    function ct_translations_for_post(PDO $pdo, int $postId): array {
        ct_ensure_schema($pdo);
        try {
            $stmt = $pdo->prepare("SELECT * FROM post_translations WHERE post_id = ?");
            $stmt->execute([$postId]);
            $out = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $out[(string)$row['locale']] = $row;
            }
            return $out;
        } catch (Throwable $e) {
            error_log('[content-translation] list error: ' . $e->getMessage());
            return [];
        }
    }

    // Returns [post_id => [locale => status, ...], ...] for posts that have a translation row.
    function ct_translation_statuses(PDO $pdo, array $postIds): array {
        ct_ensure_schema($pdo);
        $postIds = array_values(array_filter(array_map('intval', $postIds), fn($i) => $i > 0));
        if (empty($postIds)) return [];
        $placeholders = implode(',', array_fill(0, count($postIds), '?'));
        try {
            $stmt = $pdo->prepare("SELECT post_id, locale, status FROM post_translations WHERE post_id IN ($placeholders)");
            $stmt->execute($postIds);
            $out = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $out[(int)$row['post_id']][(string)$row['locale']] = (string)($row['status'] ?? 'published');
            }
            return $out;
        } catch (Throwable $e) {
            error_log('[content-translation] statuses error: ' . $e->getMessage());
            return [];
        }
    }

    function ct_find_translation_by_slug(PDO $pdo, string $locale, string $slug): ?array {
        ct_ensure_schema($pdo);
        try {
            $stmt = $pdo->prepare("SELECT * FROM post_translations WHERE locale = ? AND slug = ? AND status = 'published' LIMIT 1");
            $stmt->execute([$locale, $slug]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row && ct_post_translation_is_complete($pdo, $row) ? $row : null;
        } catch (Throwable $e) {
            error_log('[content-translation] slug lookup error: ' . $e->getMessage());
            return null;
        }
    }

    function ct_original_slug(PDO $pdo, int $postId): ?string {
        try {
            $stmt = $pdo->prepare("SELECT slug FROM posts WHERE id = ? AND is_deleted = 0 LIMIT 1");
            $stmt->execute([$postId]);
            $slug = $stmt->fetchColumn();
            return $slug !== false ? (string)$slug : null;
        } catch (Throwable $e) {
            return null;
        }
    }

    function ct_overlay_published_translation(array $post, PDO $pdo, ?string $locale = null): array {
        $locale ??= $GLOBALS['ct_request_locale'] ?? null;
        if (!$locale) return $post;

        $translation = ct_get_public_post_translation($pdo, (int)($post['id'] ?? 0), $locale);
        if (!$translation) return $post;

        foreach (['title', 'content'] as $field) {
            if (isset($translation[$field]) && $translation[$field] !== '' && $translation[$field] !== null) {
                $post[$field] = $translation[$field];
            }
        }
        if (($translation['meta_description'] ?? '') !== '') {
            $meta = !empty($post['meta']) ? (is_string($post['meta']) ? json_decode($post['meta'], true) : $post['meta']) : [];
            $meta = is_array($meta) ? $meta : [];
            $meta['meta_tags']['description'] = $translation['meta_description'];
            $post['meta'] = $meta;
        }
        $post['ct_locale'] = $locale;
        $post['ct_translated_slug'] = (string)($translation['slug'] ?? '') !== '' ? (string)$translation['slug'] : (string)($post['slug'] ?? '');
        return $post;
    }

    function ct_public_post_url(PDO $pdo, array $post, ?string $locale = null): string {
        $hadLocale = array_key_exists('ct_request_locale', $GLOBALS);
        $previousLocale = $GLOBALS['ct_request_locale'] ?? null;
        if ($locale === null || $locale === '' || $locale === content_default_locale()) {
            unset($GLOBALS['ct_request_locale']);
        } else {
            $GLOBALS['ct_request_locale'] = $locale;
        }

        try {
            if (($post['type'] ?? '') === 'page' && function_exists('get_page_permalink')) {
                return get_page_permalink($post);
            }
            if (function_exists('get_post_permalink')) return get_post_permalink($post);
            $slug = (string)($post['slug'] ?? '');
            return ct_post_url($slug, $locale);
        } finally {
            if ($hadLocale) {
                $GLOBALS['ct_request_locale'] = $previousLocale;
            } else {
                unset($GLOBALS['ct_request_locale']);
            }
        }
    }

    function ct_homepage_theme_post(PDO $pdo): ?array {
        try {
            $stmt = $pdo->query("SELECT p.* FROM assignments a INNER JOIN posts p ON p.id = a.custom_post_id WHERE a.slot_key = 'main.homepage' AND p.type = 'theme' AND p.is_deleted = 0 LIMIT 1");
            $post = $stmt->fetch(PDO::FETCH_ASSOC);
            return $post ?: null;
        } catch (Throwable $e) {
            error_log('[content-translation] homepage lookup error: ' . $e->getMessage());
            return null;
        }
    }

    function ct_theme_file_resource_id(string $themeFolder, string $slotKey): string {
        return $themeFolder . ':' . $slotKey;
    }

    /**
     * Discover translatable file resources declared by the active theme.
     * Sections that target the same slot form one atomic translation resource.
     */
    function ct_theme_file_resources(PDO $pdo, ?string $themeFolder = null): array {
        if (!function_exists('get_active_theme_folder') || !function_exists('theme_customizer_fields')) return [];

        $folder = trim((string)($themeFolder ?? get_active_theme_folder($pdo)));
        if ($folder === '' || strlen($folder) > 100) return [];

        $resources = [];
        foreach (theme_customizer_fields($folder) as $sectionKey => $section) {
            if (!is_array($section)) continue;
            $slotKey = trim((string)($section['slot'] ?? ''));
            if ($slotKey === '' || strlen($slotKey) > 150) continue;

            $translatable = [];
            foreach ((array)($section['fields'] ?? []) as $fieldKey => $field) {
                if (!is_array($field) || ($field['translatable'] ?? false) !== true) continue;
                $fieldKey = (string)($field['key'] ?? $fieldKey);
                $fieldType = (string)($field['type'] ?? 'text');
                if (!preg_match('/^[A-Za-z0-9_-]+$/', $fieldKey) || ctype_digit($fieldKey) || !in_array($fieldType, ['text', 'textarea'], true)) continue;
                $translatable[$fieldKey] = [
                    'key' => $fieldKey,
                    'type' => $fieldType,
                    'label' => (string)($field['label'] ?? ucfirst(str_replace(['_', '-'], ' ', $fieldKey))),
                    'format' => ($field['format'] ?? '') === 'json' ? 'json' : '',
                ];
            }
            if ($translatable === []) continue;

            $id = ct_theme_file_resource_id($folder, $slotKey);
            if (!isset($resources[$id])) {
                $resources[$id] = [
                    'id' => $id,
                    'theme_folder' => $folder,
                    'slot_key' => $slotKey,
                    'label' => '',
                    'section_labels' => [],
                    'fields' => [],
                ];
            }
            $sectionLabel = trim((string)($section['label'] ?? ucfirst(str_replace(['_', '-'], ' ', (string)$sectionKey))));
            if ($sectionLabel !== '' && !in_array($sectionLabel, $resources[$id]['section_labels'], true)) {
                $resources[$id]['section_labels'][] = $sectionLabel;
            }
            foreach ($translatable as $fieldKey => $field) {
                if (!isset($resources[$id]['fields'][$fieldKey])) $resources[$id]['fields'][$fieldKey] = $field;
            }
        }

        foreach ($resources as &$resource) {
            $sectionLabel = implode(' + ', $resource['section_labels']);
            $resource['label'] = $sectionLabel !== ''
                ? $sectionLabel . ' (' . $resource['slot_key'] . ')'
                : $resource['slot_key'];
        }
        unset($resource);
        return $resources;
    }

    function ct_theme_file_resource(PDO $pdo, string $themeFolder, string $slotKey): ?array {
        $id = ct_theme_file_resource_id($themeFolder, $slotKey);
        $resources = ct_theme_file_resources($pdo, $themeFolder);
        return $resources[$id] ?? null;
    }

    function ct_homepage_theme_file_resource(PDO $pdo): ?array {
        if (!function_exists('resolve_template')) return null;
        try {
            $resolved = resolve_template($pdo, 'main.homepage');
        } catch (Throwable $e) {
            error_log('[content-translation] homepage theme file resolution error: ' . $e->getMessage());
            return null;
        }
        if (($resolved['type'] ?? '') !== 'theme_file') return null;
        $folder = (string)($resolved['theme_folder'] ?? $resolved['folder'] ?? '');
        return ct_theme_file_resource($pdo, $folder, 'main.homepage');
    }

    function ct_theme_file_decode_values(string $json): ?array {
        try {
            $values = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($values)) return null;
            foreach ($values as $key => $value) {
                if (!is_string($key) || (!is_scalar($value) && $value !== null)) return null;
            }
            return $values;
        } catch (JsonException $e) {
            return null;
        }
    }

    function ct_get_theme_file_translation(PDO $pdo, string $themeFolder, string $slotKey, string $locale): ?array {
        ct_ensure_schema($pdo);
        try {
            $stmt = $pdo->prepare('SELECT * FROM theme_file_translations WHERE theme_folder = ? AND slot_key = ? AND locale = ? LIMIT 1');
            $stmt->execute([$themeFolder, $slotKey, $locale]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) return null;
            $values = ct_theme_file_decode_values((string)$row['values_json']);
            $row['values'] = $values ?? [];
            $row['values_valid'] = $values !== null;
            return $row;
        } catch (Throwable $e) {
            error_log('[content-translation] theme file get error: ' . $e->getMessage());
            return null;
        }
    }

    function ct_theme_file_translation_is_complete(array $translation, array $resource): bool {
        if (($translation['values_valid'] ?? true) !== true) return false;
        $values = $translation['values'] ?? null;
        if (!is_array($values)) return false;
        foreach ($values as $fieldKey => $value) {
            if (!is_string($fieldKey) || !isset($resource['fields'][$fieldKey]) || !is_scalar($value)) return false;
        }
        foreach ($resource['fields'] as $fieldKey => $_field) {
            if (!array_key_exists($fieldKey, $values) || !is_scalar($values[$fieldKey])) return false;
            if (trim((string)$values[$fieldKey]) === '') return false;
            if (($_field['format'] ?? '') === 'json') {
                try {
                    $decoded = json_decode((string)$values[$fieldKey], true, 512, JSON_THROW_ON_ERROR);
                } catch (JsonException $e) {
                    return false;
                }
                if (!is_array($decoded) || !array_is_list($decoded)) return false;
            }
        }
        return true;
    }

    function ct_get_published_theme_file_translation(PDO $pdo, string $themeFolder, string $slotKey, string $locale): ?array {
        $resource = ct_theme_file_resource($pdo, $themeFolder, $slotKey);
        if (!$resource) return null;
        $translation = ct_get_theme_file_translation($pdo, $themeFolder, $slotKey, $locale);
        if (!$translation || ($translation['status'] ?? '') !== 'published') return null;
        return ct_theme_file_translation_is_complete($translation, $resource) ? $translation : null;
    }

    function ct_theme_file_translation_status(PDO $pdo, string $themeFolder, string $slotKey, string $locale): ?string {
        $translation = ct_get_theme_file_translation($pdo, $themeFolder, $slotKey, $locale);
        $status = $translation['status'] ?? null;
        return in_array($status, ['draft', 'published'], true) ? $status : null;
    }

    function ct_save_theme_file_translation(PDO $pdo, string $themeFolder, string $slotKey, string $locale, array $data): bool {
        if (!in_array($locale, ct_enabled_locales($pdo), true)) {
            throw new InvalidArgumentException('Locale not enabled.');
        }
        $resource = ct_theme_file_resource($pdo, $themeFolder, $slotKey);
        if (!$resource) throw new InvalidArgumentException('Theme file resource is not declared by the active theme.');

        $status = (string)($data['status'] ?? '');
        if (!in_array($status, ['draft', 'published'], true)) throw new InvalidArgumentException('Invalid translation status.');

        $seoTitle = trim((string)($data['seo_title'] ?? ''));
        $metaDescription = trim((string)($data['meta_description'] ?? ''));
        $length = static fn(string $value): int => function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
        if ($length($seoTitle) > 255) throw new InvalidArgumentException('SEO title must not exceed 255 characters.');
        if ($length($metaDescription) > 320) throw new InvalidArgumentException('Meta description must not exceed 320 characters.');

        $input = $data['values'] ?? ($data['values_json'] ?? []);
        if (is_string($input)) {
            try {
                $input = json_decode($input, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException $e) {
                throw new InvalidArgumentException('Translation values contain invalid JSON.');
            }
        }
        if (!is_array($input)) throw new InvalidArgumentException('Translation values must be an object.');

        foreach ($input as $fieldKey => $value) {
            if (!is_string($fieldKey) || !isset($resource['fields'][$fieldKey])) {
                throw new InvalidArgumentException('Translation contains an unknown theme field.');
            }
            if (!is_scalar($value)) throw new InvalidArgumentException('Theme field translations must be scalar values.');
        }

        $values = [];
        foreach ($resource['fields'] as $fieldKey => $_field) {
            $value = $input[$fieldKey] ?? '';
            if (!is_scalar($value)) throw new InvalidArgumentException('Theme field translations must be scalar values.');
            $values[$fieldKey] = (string)$value;
        }
        $candidate = ['values' => $values, 'values_valid' => true];
        if ($status === 'published' && !ct_theme_file_translation_is_complete($candidate, $resource)) {
            throw new InvalidArgumentException('Every translatable theme field must be completed before publishing.');
        }

        try {
            $valuesJson = json_encode($values, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new InvalidArgumentException('Translation values could not be encoded as JSON.');
        }

        ct_ensure_schema($pdo);
        $stmt = $pdo->prepare("INSERT INTO theme_file_translations (theme_folder, slot_key, locale, values_json, seo_title, meta_description, status)
            VALUES (?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE values_json = VALUES(values_json), seo_title = VALUES(seo_title), meta_description = VALUES(meta_description), status = VALUES(status)");
        return $stmt->execute([$themeFolder, $slotKey, $locale, $valuesJson, $seoTitle, $metaDescription, $status]);
    }

    function ct_delete_theme_file_translation(PDO $pdo, string $themeFolder, string $slotKey, string $locale): bool {
        if (!in_array($locale, ct_enabled_locales($pdo), true)) throw new InvalidArgumentException('Locale not enabled.');
        if (!ct_theme_file_resource($pdo, $themeFolder, $slotKey)) {
            throw new InvalidArgumentException('Theme file resource is not declared by the active theme.');
        }
        ct_ensure_schema($pdo);
        $stmt = $pdo->prepare('DELETE FROM theme_file_translations WHERE theme_folder = ? AND slot_key = ? AND locale = ?');
        return $stmt->execute([$themeFolder, $slotKey, $locale]);
    }

    function ct_theme_file_translation_statuses(PDO $pdo, array $resources): array {
        if ($resources === []) return [];
        ct_ensure_schema($pdo);
        $out = [];
        foreach ($resources as $resource) {
            $stmt = $pdo->prepare('SELECT locale, status, values_json FROM theme_file_translations WHERE theme_folder = ? AND slot_key = ?');
            $stmt->execute([(string)$resource['theme_folder'], (string)$resource['slot_key']]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $status = (string)$row['status'];
                if ($status === 'published') {
                    $values = ct_theme_file_decode_values((string)$row['values_json']);
                    if ($values === null || !ct_theme_file_translation_is_complete(['values' => $values, 'values_valid' => true], $resource)) {
                        $status = 'incomplete';
                    }
                }
                $out[(string)$resource['id']][(string)$row['locale']] = $status;
            }
        }
        return $out;
    }

    function ct_theme_file_source_values(PDO $pdo, array $resource): array {
        $mods = function_exists('theme_mods_all') ? theme_mods_all($pdo, (string)$resource['theme_folder']) : [];
        $values = [];
        foreach ($resource['fields'] as $fieldKey => $_field) {
            $values[$fieldKey] = array_key_exists($fieldKey, $mods) ? $mods[$fieldKey] : '';
        }
        return $values;
    }

    function ct_theme_zone_resource_from_row(array $row): ?array {
        $itemId = (int)($row['id'] ?? 0);
        $folder = is_string($row['theme_folder'] ?? null) ? trim($row['theme_folder']) : '';
        $type = is_string($row['type'] ?? null) ? trim($row['type']) : '';
        if ($itemId <= 0 || $folder === '' || strlen($folder) > 100 || $type === ''
            || !function_exists('theme_zone_translatable_config')) return null;
        $schema = theme_zone_translatable_config($type);
        if (!is_array($schema) || $schema === []) return null;
        $config = json_decode((string)($row['config'] ?? '{}'), true);
        if (!is_array($config)) return null;
        $sourceValues = [];
        foreach ($schema as $key => $field) {
            $value = $config[$key] ?? '';
            if (!is_scalar($value) && $value !== null) return null;
            $sourceValues[$key] = (string)$value;
        }
        $fingerprintSchema = [];
        foreach ($schema as $key => $field) {
            $fingerprintSchema[$key] = ['control' => $field['control'], 'format' => $field['format']];
        }
        $fingerprintPayload = [
            'version' => 1,
            'id' => $itemId,
            'theme_folder' => $folder,
            'type' => $type,
            'schema' => $fingerprintSchema,
            'source_values' => $sourceValues,
        ];
        return [
            'id' => $itemId,
            'theme_folder' => $folder,
            'zone_slug' => (string)($row['zone_slug'] ?? ''),
            'position' => (string)($row['position'] ?? ''),
            'type' => $type,
            'title' => (string)($row['title'] ?? ''),
            'schema' => $schema,
            'source_values' => $sourceValues,
            'source_fingerprint' => hash('sha256', json_encode($fingerprintPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)),
        ];
    }

    function ct_theme_zone_resource(PDO $pdo, int $itemId): ?array {
        if ($itemId <= 0) return null;
        try {
            $stmt = $pdo->prepare('SELECT * FROM theme_zone_items WHERE id = ? LIMIT 1');
            $stmt->execute([$itemId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row ? ct_theme_zone_resource_from_row($row) : null;
        } catch (Throwable $error) {
            error_log('[content-translation] Theme Zone resource error: ' . $error->getMessage());
            return null;
        }
    }

    function ct_theme_zone_translation_state(?array $translation): string {
        return hash('sha256', json_encode($translation ? [
            'id' => (int)($translation['id'] ?? 0),
            'values_json' => (string)($translation['values_json'] ?? ''),
            'source_fingerprint' => (string)($translation['source_fingerprint'] ?? ''),
            'status' => (string)($translation['status'] ?? ''),
            'updated_at' => (string)($translation['updated_at'] ?? ''),
        ] : ['missing' => true], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    function ct_get_theme_zone_translation(PDO $pdo, int $itemId, string $locale): ?array {
        ct_ensure_schema($pdo);
        $stmt = $pdo->prepare('SELECT * FROM ct_theme_zone_item_translations WHERE theme_zone_item_id = ? AND locale = ? LIMIT 1');
        $stmt->execute([$itemId, $locale]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) return null;
        $values = ct_theme_file_decode_values((string)$row['values_json']);
        $row['values'] = $values ?? [];
        $row['values_valid'] = $values !== null;
        return $row;
    }

    function ct_theme_zone_translation_is_complete(array $translation, array $resource): bool {
        if (($translation['values_valid'] ?? true) !== true || !is_array($translation['values'] ?? null)) return false;
        $values = $translation['values'];
        foreach ($values as $key => $value) {
            if (!is_string($key) || !isset($resource['schema'][$key]) || !is_scalar($value)) return false;
        }
        foreach ($resource['schema'] as $key => $_field) {
            if (!array_key_exists($key, $values) || !is_scalar($values[$key])) return false;
            if (trim((string)($resource['source_values'][$key] ?? '')) !== '' && trim((string)$values[$key]) === '') return false;
        }
        return true;
    }

    function ct_theme_zone_translation_statuses(PDO $pdo, int $itemId): array {
        ct_ensure_schema($pdo);
        $resource = ct_theme_zone_resource($pdo, $itemId);
        if (!$resource) return [];
        $stmt = $pdo->prepare('SELECT * FROM ct_theme_zone_item_translations WHERE theme_zone_item_id = ?');
        $stmt->execute([$itemId]);
        $statuses = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $values = ct_theme_file_decode_values((string)$row['values_json']);
            $current = hash_equals((string)$resource['source_fingerprint'], (string)($row['source_fingerprint'] ?? ''));
            $complete = $values !== null && ct_theme_zone_translation_is_complete(['values' => $values, 'values_valid' => true], $resource);
            $statuses[(string)$row['locale']] = !$current ? 'stale' : (!$complete ? 'incomplete' : (string)$row['status']);
        }
        return $statuses;
    }

    function ct_save_theme_zone_translation(PDO $pdo, int $itemId, string $locale, array $data): array {
        if (!in_array($locale, ct_enabled_locales($pdo), true)) throw new InvalidArgumentException('Locale not enabled.');
        $loadedSource = (string)($data['source_fingerprint'] ?? '');
        $loadedState = (string)($data['translation_state'] ?? '');
        if (preg_match('/\A[a-f0-9]{64}\z/D', $loadedSource) !== 1 || preg_match('/\A[a-f0-9]{64}\z/D', $loadedState) !== 1) {
            throw new InvalidArgumentException('Editor state is invalid. Reload the editor.');
        }
        $status = (string)($data['status'] ?? '');
        if (!in_array($status, ['draft', 'published'], true)) throw new InvalidArgumentException('Invalid translation status.');
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) $pdo->beginTransaction();
        try {
            $sourceStmt = $pdo->prepare('SELECT * FROM theme_zone_items WHERE id = ? LIMIT 1 FOR UPDATE');
            $sourceStmt->execute([$itemId]);
            $sourceRow = $sourceStmt->fetch(PDO::FETCH_ASSOC);
            $resource = $sourceRow ? ct_theme_zone_resource_from_row($sourceRow) : null;
            if (!$resource || !hash_equals((string)$resource['source_fingerprint'], $loadedSource)) {
                throw new RuntimeException('Theme Zone source changed. Reload the editor.');
            }
            $currentStmt = $pdo->prepare('SELECT * FROM ct_theme_zone_item_translations WHERE theme_zone_item_id = ? AND locale = ? LIMIT 1 FOR UPDATE');
            $currentStmt->execute([$itemId, $locale]);
            $current = $currentStmt->fetch(PDO::FETCH_ASSOC) ?: null;
            if (!hash_equals($loadedState, ct_theme_zone_translation_state($current))) {
                throw new RuntimeException('This translation changed in another editor. Reload before saving.');
            }
            $input = is_array($data['values'] ?? null) ? $data['values'] : [];
            foreach ($input as $key => $value) {
                if (!is_string($key) || !isset($resource['schema'][$key]) || !is_scalar($value)) {
                    throw new InvalidArgumentException('Translation contains an unknown Theme Zone field.');
                }
            }
            $values = [];
            foreach ($resource['schema'] as $key => $_field) $values[$key] = (string)($input[$key] ?? '');
            $candidate = ['values' => $values, 'values_valid' => true];
            if ($status === 'published' && !ct_theme_zone_translation_is_complete($candidate, $resource)) {
                throw new InvalidArgumentException('Every source Theme Zone text must be translated before publishing.');
            }
            $json = json_encode($values, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $save = $pdo->prepare("INSERT INTO ct_theme_zone_item_translations (theme_zone_item_id, locale, values_json, source_fingerprint, status)
                VALUES (?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE values_json = VALUES(values_json), source_fingerprint = VALUES(source_fingerprint), status = VALUES(status)");
            $save->execute([$itemId, $locale, $json, $resource['source_fingerprint'], $status]);
            if ($ownsTransaction) $pdo->commit();
            return ct_get_theme_zone_translation($pdo, $itemId, $locale) ?? [];
        } catch (Throwable $error) {
            if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack();
            throw $error;
        }
    }

    function ct_delete_theme_zone_translation(PDO $pdo, int $itemId, string $locale, string $loadedState): bool {
        if (preg_match('/\A[a-f0-9]{64}\z/D', $loadedState) !== 1) throw new InvalidArgumentException('Editor state is invalid.');
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) $pdo->beginTransaction();
        try {
            $currentStmt = $pdo->prepare('SELECT * FROM ct_theme_zone_item_translations WHERE theme_zone_item_id = ? AND locale = ? LIMIT 1 FOR UPDATE');
            $currentStmt->execute([$itemId, $locale]);
            $current = $currentStmt->fetch(PDO::FETCH_ASSOC) ?: null;
            if (!hash_equals($loadedState, ct_theme_zone_translation_state($current))) {
                throw new RuntimeException('This translation changed in another editor. Reload before deleting.');
            }
            $stmt = $pdo->prepare('DELETE FROM ct_theme_zone_item_translations WHERE theme_zone_item_id = ? AND locale = ?');
            $ok = $stmt->execute([$itemId, $locale]);
            if ($ownsTransaction) $pdo->commit();
            return $ok;
        } catch (Throwable $error) {
            if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack();
            throw $error;
        }
    }

    function ct_theme_string_decode_literal(string $literal): ?string {
        if (strlen($literal) < 2) return null;
        $quote = $literal[0];
        if (($quote !== "'" && $quote !== '"') || $literal[strlen($literal) - 1] !== $quote) return null;
        $value = substr($literal, 1, -1);
        if ($quote === "'") return str_replace(["\\\\", "\\'"], ["\\", "'"], $value);
        $decoded = '';
        for ($index = 0, $length = strlen($value); $index < $length; $index++) {
            if ($value[$index] !== '\\' || $index + 1 >= $length) { $decoded .= $value[$index]; continue; }
            $escaped = $value[++$index];
            $simple = ['n' => "\n", 'r' => "\r", 't' => "\t", 'v' => "\v", 'e' => "\e", 'f' => "\f", '\\' => '\\', '$' => '$', '"' => '"'];
            if (isset($simple[$escaped])) { $decoded .= $simple[$escaped]; continue; }
            if ($escaped === 'x') {
                $hex = '';
                while ($index + 1 < $length && strlen($hex) < 2 && ctype_xdigit($value[$index + 1])) $hex .= $value[++$index];
                $decoded .= $hex !== '' ? chr(hexdec($hex)) : '\\x';
                continue;
            }
            if ($escaped >= '0' && $escaped <= '7') {
                $octal = $escaped;
                while ($index + 1 < $length && strlen($octal) < 3 && $value[$index + 1] >= '0' && $value[$index + 1] <= '7') $octal .= $value[++$index];
                $decoded .= chr(octdec($octal) & 0xff);
                continue;
            }
            if ($escaped === 'u' && ($value[$index + 1] ?? '') === '{') {
                $end = strpos($value, '}', $index + 2);
                $hex = $end !== false ? substr($value, $index + 2, $end - $index - 2) : '';
                if ($hex !== '' && strlen($hex) <= 6 && ctype_xdigit($hex)) {
                    $codepoint = hexdec($hex);
                    if ($codepoint <= 0x10ffff && !($codepoint >= 0xd800 && $codepoint <= 0xdfff)) {
                        $decoded .= $codepoint <= 0x7f ? chr($codepoint)
                            : ($codepoint <= 0x7ff ? chr(0xc0 | ($codepoint >> 6)) . chr(0x80 | ($codepoint & 0x3f))
                            : ($codepoint <= 0xffff ? chr(0xe0 | ($codepoint >> 12)) . chr(0x80 | (($codepoint >> 6) & 0x3f)) . chr(0x80 | ($codepoint & 0x3f))
                            : chr(0xf0 | ($codepoint >> 18)) . chr(0x80 | (($codepoint >> 12) & 0x3f)) . chr(0x80 | (($codepoint >> 6) & 0x3f)) . chr(0x80 | ($codepoint & 0x3f))));
                        $index = $end;
                        continue;
                    }
                }
            }
            $decoded .= '\\' . $escaped;
        }
        return $decoded;
    }

    function ct_theme_string_literals(string $php): array {
        $tokens = token_get_all($php);
        $next = static function (array $tokens, int $index): int {
            while (isset($tokens[$index]) && is_array($tokens[$index])
                && in_array($tokens[$index][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) $index++;
            return $index;
        };
        $previous = static function (array $tokens, int $index): int {
            while ($index >= 0 && is_array($tokens[$index])
                && in_array($tokens[$index][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) $index--;
            return $index;
        };
        $strings = [];
        for ($index = 0, $count = count($tokens); $index < $count; $index++) {
            $token = $tokens[$index];
            if (!is_array($token)) continue;
            $isPlainCall = $token[0] === T_STRING && in_array(strtolower($token[1]), ['__', '_e'], true);
            $isGlobalCall = defined('T_NAME_FULLY_QUALIFIED') && $token[0] === T_NAME_FULLY_QUALIFIED
                && in_array(strtolower($token[1]), ['\\__', '\\_e'], true);
            if (!$isPlainCall && !$isGlobalCall) continue;
            $before = $tokens[$previous($tokens, $index - 1)] ?? null;
            $memberTokens = [T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_NEW];
            if (defined('T_NULLSAFE_OBJECT_OPERATOR')) $memberTokens[] = T_NULLSAFE_OBJECT_OPERATOR;
            if (is_array($before) && in_array($before[0], $memberTokens, true)) continue;
            $cursor = $next($tokens, $index + 1);
            if (($tokens[$cursor] ?? null) !== '(') continue;
            $cursor = $next($tokens, $cursor + 1);
            if (!is_array($tokens[$cursor] ?? null) || $tokens[$cursor][0] !== T_CONSTANT_ENCAPSED_STRING) continue;
            $source = ct_theme_string_decode_literal((string)$tokens[$cursor][1]);
            if ($source === null || $source === '' || strlen($source) > 1000) continue;
            $scope = 'default';
            $cursor = $next($tokens, $cursor + 1);
            if (($tokens[$cursor] ?? null) === ',') {
                $cursor = $next($tokens, $cursor + 1);
                if (!is_array($tokens[$cursor] ?? null) || $tokens[$cursor][0] !== T_CONSTANT_ENCAPSED_STRING) continue;
                $candidateScope = ct_theme_string_decode_literal((string)$tokens[$cursor][1]);
                if (!is_string($candidateScope) || preg_match('/\A[A-Za-z0-9_.-]{1,50}\z/D', $candidateScope) !== 1) continue;
                $scope = $candidateScope;
                $cursor = $next($tokens, $cursor + 1);
            }
            if (($tokens[$cursor] ?? null) !== ')') continue;
            $strings[$scope . "\0" . $source] = ['scope' => $scope, 'source' => $source];
        }
        return array_values($strings);
    }

    function ct_theme_root(PDO $pdo, string $folder): ?string {
        if (strlen($folder) > 100 || preg_match('/\A[A-Za-z0-9_-][A-Za-z0-9._-]*\z/D', $folder) !== 1
            || in_array($folder, ['.', '..'], true) || !defined('VIEWS_BASE')) return null;
        $stmt = $pdo->prepare('SELECT folder_name FROM themes WHERE folder_name = ? LIMIT 1');
        $stmt->execute([$folder]);
        $registered = $stmt->fetchColumn();
        if (!is_string($registered) || !hash_equals($folder, $registered)) return null;
        $base = realpath((string)VIEWS_BASE);
        $candidate = $base !== false ? $base . DIRECTORY_SEPARATOR . $folder : '';
        if ($base === false || $candidate === '' || is_link($candidate)) return null;
        $root = realpath($candidate);
        return $root !== false && is_dir($root) && dirname($root) === $base ? $root : null;
    }

    function ct_theme_string_resources(PDO $pdo, string $folder): array {
        $root = ct_theme_root($pdo, $folder);
        if ($root === null) return [];
        $resources = [];
        $entries = 0;
        $phpFiles = 0;
        $totalBytes = 0;
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $entry) {
            if (++$entries > 10000) throw new RuntimeException('Theme string discovery tree is too large.');
            $path = $entry->getPathname();
            if (is_link($path)) throw new RuntimeException('Theme string discovery rejects symlinks.');
            if (!$entry->isFile() || strtolower($entry->getExtension()) !== 'php') continue;
            if (++$phpFiles > 500 || $entry->getSize() > 524288 || ($totalBytes += $entry->getSize()) > 16777216) {
                throw new RuntimeException('Theme string discovery limit exceeded.');
            }
            $real = realpath($path);
            if ($real === false || !str_starts_with($real, $root . DIRECTORY_SEPARATOR)) {
                throw new RuntimeException('Theme string source escapes its root.');
            }
            $source = file_get_contents($real);
            if (!is_string($source)) throw new RuntimeException('Theme string source could not be read.');
            foreach (ct_theme_string_literals($source) as $literal) {
                $hash = hash('sha256', $literal['scope'] . "\0" . $literal['source']);
                $resources[$hash] = [
                    'id' => $folder . ':' . $hash,
                    'theme_folder' => $folder,
                    'source_hash' => $hash,
                    'scope' => $literal['scope'],
                    'source' => $literal['source'],
                ];
            }
        }
        uasort($resources, static fn(array $a, array $b): int => [$a['scope'], $a['source']] <=> [$b['scope'], $b['source']]);
        return $resources;
    }

    function ct_theme_string_resource(PDO $pdo, string $folder, string $sourceHash): ?array {
        if (preg_match('/\A[a-f0-9]{64}\z/D', $sourceHash) !== 1) return null;
        return ct_theme_string_resources($pdo, $folder)[$sourceHash] ?? null;
    }

    function ct_theme_string_translation_state(?array $translation): string {
        return hash('sha256', json_encode($translation ? [
            'id' => (int)($translation['id'] ?? 0),
            'source' => (string)($translation['source'] ?? ''),
            'value' => (string)($translation['value'] ?? ''),
            'status' => (string)($translation['status'] ?? ''),
            'updated_at' => (string)($translation['updated_at'] ?? ''),
        ] : ['missing' => true], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    function ct_get_theme_string_translation(PDO $pdo, string $folder, string $scope, string $sourceHash, string $locale): ?array {
        ct_ensure_schema($pdo);
        $stmt = $pdo->prepare('SELECT * FROM ct_theme_string_translations WHERE theme_folder = ? AND scope = ? AND source_hash = ? AND locale = ? LIMIT 1');
        $stmt->execute([$folder, $scope, $sourceHash, $locale]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    function ct_theme_string_placeholders(string $value): array {
        preg_match_all('/%(?:\d+\$)?[bcdeEufFgGosxX]/', str_replace('%%', '', $value), $matches);
        $tokens = $matches[0] ?? [];
        sort($tokens);
        return $tokens;
    }

    function ct_save_theme_string_translation(PDO $pdo, array $resource, string $locale, string $value, string $status, string $loadedState): array {
        if (!in_array($locale, ct_enabled_locales($pdo), true)) throw new InvalidArgumentException('Locale not enabled.');
        if (!in_array($status, ['draft', 'published'], true)) throw new InvalidArgumentException('Invalid translation status.');
        $currentResource = ct_theme_string_resource($pdo, (string)$resource['theme_folder'], (string)$resource['source_hash']);
        if (!$currentResource || !hash_equals((string)$resource['source'], (string)$currentResource['source'])
            || !hash_equals((string)$resource['scope'], (string)$currentResource['scope'])) {
            throw new RuntimeException('Theme string source changed. Reload the editor.');
        }
        if (preg_match('/\A[a-f0-9]{64}\z/D', $loadedState) !== 1) throw new InvalidArgumentException('Editor state is invalid.');
        $value = trim($value);
        if ($status === 'published' && $value === '') throw new InvalidArgumentException('Published theme strings cannot be empty.');
        if (ct_theme_string_placeholders((string)$resource['source']) !== ct_theme_string_placeholders($value)) {
            throw new InvalidArgumentException('Translation must preserve the source format placeholders.');
        }
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) $pdo->beginTransaction();
        try {
            $currentStmt = $pdo->prepare('SELECT * FROM ct_theme_string_translations WHERE theme_folder = ? AND scope = ? AND source_hash = ? AND locale = ? LIMIT 1 FOR UPDATE');
            $currentStmt->execute([$resource['theme_folder'], $resource['scope'], $resource['source_hash'], $locale]);
            $current = $currentStmt->fetch(PDO::FETCH_ASSOC) ?: null;
            if (!hash_equals($loadedState, ct_theme_string_translation_state($current))) {
                throw new RuntimeException('This translation changed in another editor. Reload before saving.');
            }
            $stmt = $pdo->prepare("INSERT INTO ct_theme_string_translations (theme_folder, scope, source_hash, source, locale, value, status)
                VALUES (?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE source = VALUES(source), value = VALUES(value), status = VALUES(status)");
            $stmt->execute([$resource['theme_folder'], $resource['scope'], $resource['source_hash'], $resource['source'], $locale, $value, $status]);
            if ($ownsTransaction) $pdo->commit();
            return ct_get_theme_string_translation($pdo, (string)$resource['theme_folder'], (string)$resource['scope'], (string)$resource['source_hash'], $locale) ?? [];
        } catch (Throwable $error) {
            if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack();
            throw $error;
        }
    }

    function ct_delete_theme_string_translation(PDO $pdo, array $resource, string $locale, string $loadedState): bool {
        if (preg_match('/\A[a-f0-9]{64}\z/D', $loadedState) !== 1) throw new InvalidArgumentException('Editor state is invalid.');
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) $pdo->beginTransaction();
        try {
            $currentStmt = $pdo->prepare('SELECT * FROM ct_theme_string_translations WHERE theme_folder = ? AND scope = ? AND source_hash = ? AND locale = ? LIMIT 1 FOR UPDATE');
            $currentStmt->execute([$resource['theme_folder'], $resource['scope'], $resource['source_hash'], $locale]);
            $current = $currentStmt->fetch(PDO::FETCH_ASSOC) ?: null;
            if (!hash_equals($loadedState, ct_theme_string_translation_state($current))) {
                throw new RuntimeException('This translation changed in another editor. Reload before deleting.');
            }
            $stmt = $pdo->prepare('DELETE FROM ct_theme_string_translations WHERE theme_folder = ? AND scope = ? AND source_hash = ? AND locale = ?');
            $ok = $stmt->execute([$resource['theme_folder'], $resource['scope'], $resource['source_hash'], $locale]);
            if ($ownsTransaction) $pdo->commit();
            return $ok;
        } catch (Throwable $error) {
            if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack();
            throw $error;
        }
    }

    function ct_current_content_from_request(PDO $pdo): ?array {
        $path = trim((string)(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? ''), '/');
        if ($path === '') return null;
        $slug = rawurldecode((string)basename($path));
        if ($slug === '' || $slug === 'page') return null;

        $stmt = $pdo->prepare("SELECT * FROM posts WHERE slug = ? AND is_deleted = 0 AND type IN ('article','page','theme') LIMIT 1");
        $stmt->execute([$slug]);
        $post = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($post) return $post;

        $stmt = $pdo->prepare("SELECT p.* FROM post_translations pt INNER JOIN posts p ON p.id = pt.post_id WHERE pt.slug = ? AND pt.status = 'published' AND p.is_deleted = 0 LIMIT 1");
        $stmt->execute([$slug]);
        $post = $stmt->fetch(PDO::FETCH_ASSOC);
        return $post ?: null;
    }

    function ct_get_category_translation(PDO $pdo, int $categoryId, string $locale): ?array {
        ct_ensure_schema($pdo);
        $stmt = $pdo->prepare('SELECT * FROM category_translations WHERE category_id = ? AND locale = ? LIMIT 1');
        $stmt->execute([$categoryId, $locale]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    function ct_get_published_category_translation(PDO $pdo, int $categoryId, string $locale): ?array {
        $translation = ct_get_category_translation($pdo, $categoryId, $locale);
        return $translation && ($translation['status'] ?? 'published') === 'published' ? $translation : null;
    }

    function ct_save_category_translation(PDO $pdo, int $categoryId, string $locale, array $data): bool {
        $status = in_array(($data['status'] ?? 'published'), ['draft', 'published'], true) ? $data['status'] : 'published';
        $stmt = $pdo->prepare("INSERT INTO category_translations (category_id, locale, name, slug, description, status) VALUES (?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE name = VALUES(name), slug = VALUES(slug), description = VALUES(description), status = VALUES(status)");
        return $stmt->execute([$categoryId, $locale, (string)($data['name'] ?? ''), (string)($data['slug'] ?? ''), (string)($data['description'] ?? ''), $status]);
    }

    function ct_category_translation_slug_conflict(PDO $pdo, int $categoryId, string $locale, string $slug, ?int $parentId): bool {
        $stmt = $pdo->prepare("SELECT 1
            FROM category_translations ct
            INNER JOIN categories c ON c.id = ct.category_id
            WHERE ct.category_id <> ?
              AND ct.locale = ?
              AND ct.slug = ?
              AND ct.status = 'published'
              AND c.is_deleted = 0
              AND ((c.parent_id IS NULL AND ? IS NULL) OR c.parent_id = ?)
            LIMIT 1 FOR UPDATE");
        $stmt->execute([$categoryId, $locale, $slug, $parentId, $parentId]);
        return (bool)$stmt->fetchColumn();
    }

    function ct_assert_category_translation_paths_unique(PDO $pdo, int $categoryId): void {
        $categoryStmt = $pdo->prepare('SELECT parent_id FROM categories WHERE id = ? AND is_deleted = 0 LIMIT 1');
        $categoryStmt->execute([$categoryId]);
        $parent = $categoryStmt->fetch(PDO::FETCH_ASSOC);
        if (!$parent) return;
        $parentId = $parent['parent_id'] === null ? null : (int)$parent['parent_id'];

        $translations = $pdo->prepare("SELECT locale, slug FROM category_translations WHERE category_id = ? AND status = 'published' AND TRIM(slug) <> '' ORDER BY locale FOR UPDATE");
        $translations->execute([$categoryId]);
        foreach ($translations->fetchAll(PDO::FETCH_ASSOC) ?: [] as $translation) {
            if (ct_category_translation_slug_conflict(
                $pdo,
                $categoryId,
                (string)$translation['locale'],
                (string)$translation['slug'],
                $parentId
            )) {
                throw new RuntimeException('A translated category slug conflicts with a sibling category.');
            }
        }
    }

    function ct_menu_item_translations_for_menu(PDO $pdo, int $menuId): array {
        ct_ensure_schema($pdo);
        $stmt = $pdo->prepare('SELECT mit.* FROM menu_item_translations mit INNER JOIN menu_items mi ON mi.id = mit.menu_item_id WHERE mi.menu_id = ?');
        $stmt->execute([$menuId]);
        $translations = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $translation) {
            $translations[(int)$translation['menu_item_id']][(string)$translation['locale']] = $translation;
        }
        return $translations;
    }

    function ct_save_menu_item_translation(PDO $pdo, int $itemId, string $locale, array $data): bool {
        if ($itemId <= 0 || !in_array($locale, ct_enabled_locales($pdo), true)) return false;
        ct_ensure_schema($pdo);
        $stmt = $pdo->prepare("INSERT INTO menu_item_translations (menu_item_id, locale, label, url) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE label = VALUES(label), url = VALUES(url)");
        return $stmt->execute([$itemId, $locale, (string)($data['label'] ?? ''), (string)($data['url'] ?? '')]);
    }

    function ct_sidebar_item_translation(PDO $pdo, int $itemId, string $locale): ?array {
        ct_ensure_schema($pdo);
        $stmt = $pdo->prepare('SELECT * FROM sidebar_item_translations WHERE sidebar_item_id = ? AND locale = ? LIMIT 1');
        $stmt->execute([$itemId, $locale]);
        $translation = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($translation) {
            $translation['config'] = json_decode((string)$translation['config'], true) ?: [];
        }
        return $translation;
    }

    function ct_save_sidebar_item_translation(PDO $pdo, int $itemId, string $locale, array $data): bool {
        if ($itemId <= 0 || !in_array($locale, ct_enabled_locales($pdo), true)) return false;
        ct_ensure_schema($pdo);
        $config = array_intersect_key((array)($data['config'] ?? []), array_flip(['placeholder', 'button', 'html']));
        $stmt = $pdo->prepare("INSERT INTO sidebar_item_translations (sidebar_item_id, locale, title, config) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE title = VALUES(title), config = VALUES(config)");
        return $stmt->execute([$itemId, $locale, (string)($data['title'] ?? ''), json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
    }

    function ct_get_author_profile_translation(PDO $pdo, int $userId, string $locale): ?array {
        ct_ensure_schema($pdo);
        $stmt = $pdo->prepare('SELECT * FROM author_profile_translations WHERE user_id = ? AND locale = ? LIMIT 1');
        $stmt->execute([$userId, $locale]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    function ct_save_author_profile_translation(PDO $pdo, int $userId, string $locale, string $bio): bool {
        if ($userId <= 0 || !in_array($locale, ct_enabled_locales($pdo), true)) return false;
        ct_ensure_schema($pdo);
        $stmt = $pdo->prepare("INSERT INTO author_profile_translations (user_id, locale, bio) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE bio = VALUES(bio)");
        return $stmt->execute([$userId, $locale, $bio]);
    }

    function ct_overlay_category_translation(array $category, PDO $pdo, ?string $locale = null): array {
        $locale ??= $GLOBALS['ct_request_locale'] ?? null;
        if (!$locale) return $category;
        $translation = ct_get_published_category_translation($pdo, (int)($category['id'] ?? 0), $locale);
        if (!$translation) return $category;
        foreach (['name', 'slug', 'description'] as $field) {
            if (($translation[$field] ?? '') !== '') $category[$field] = $translation[$field];
        }
        return $category;
    }

    function ct_category_source_path(PDO $pdo, array $category): string {
        $parts = [];
        $current = $category;
        $visited = [];
        if (!array_key_exists('parent_id', $current) && !empty($current['id'])) {
            $stmt = $pdo->prepare('SELECT id, parent_id, slug FROM categories WHERE id = ? AND is_deleted = 0 LIMIT 1');
            $stmt->execute([(int)$current['id']]);
            $current = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        }
        while (!empty($current['id']) && !in_array((int)$current['id'], $visited, true)) {
            $visited[] = (int)$current['id'];
            array_unshift($parts, (string)$current['slug']);
            $parentId = (int)($current['parent_id'] ?? 0);
            if ($parentId <= 0) break;
            $stmt = $pdo->prepare('SELECT id, parent_id, slug FROM categories WHERE id = ? AND is_deleted = 0 LIMIT 1');
            $stmt->execute([$parentId]);
            $current = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        }
        return implode('/', $parts);
    }

    function ct_category_translation_path(PDO $pdo, array $category, string $locale): ?string {
        $parts = [];
        $current = $category;
        $visited = [];
        if (!array_key_exists('parent_id', $current) && !empty($current['id'])) {
            $stmt = $pdo->prepare('SELECT id, parent_id, slug FROM categories WHERE id = ? AND is_deleted = 0 LIMIT 1');
            $stmt->execute([(int)$current['id']]);
            $current = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        }
        while (!empty($current['id']) && !in_array((int)$current['id'], $visited, true)) {
            $visited[] = (int)$current['id'];
            $translation = ct_get_published_category_translation($pdo, (int)$current['id'], $locale);
            if (!$translation || ($translation['slug'] ?? '') === '') return null;
            array_unshift($parts, (string)$translation['slug']);
            $parentId = (int)($current['parent_id'] ?? 0);
            if ($parentId <= 0) break;
            $stmt = $pdo->prepare('SELECT id, parent_id, slug FROM categories WHERE id = ? AND is_deleted = 0 LIMIT 1');
            $stmt->execute([$parentId]);
            $current = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        }
        return implode('/', $parts);
    }

    function ct_find_category_translation_path(PDO $pdo, string $locale, string $path): ?array {
        $parentId = null;
        $category = null;
        foreach (array_filter(explode('/', trim($path, '/'))) as $slug) {
            $sql = 'SELECT c.id, c.parent_id, c.slug FROM category_translations ct INNER JOIN categories c ON c.id = ct.category_id WHERE ct.locale = ? AND ct.slug = ? AND ct.status = \'published\' AND c.is_deleted = 0';
            $params = [$locale, rawurldecode($slug)];
            $sql .= $parentId === null ? ' AND (c.parent_id IS NULL OR c.parent_id = 0)' : ' AND c.parent_id = ?';
            if ($parentId !== null) $params[] = $parentId;
            $stmt = $pdo->prepare($sql . ' LIMIT 1');
            $stmt->execute($params);
            $category = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
            if (!$category) return null;
            $parentId = (int)$category['id'];
        }
        return $category ? ['category' => $category, 'source_path' => ct_category_source_path($pdo, $category)] : null;
    }

    function ct_base_url(): string {
        $pdo = $GLOBALS['pdo'] ?? null;
        if ($pdo instanceof PDO && function_exists('settings_get')) {
            $configured = trim((string)settings_get($pdo, 'site_url', ''));
            $parts = $configured !== '' ? parse_url($configured) : false;
            if (is_array($parts) && in_array(($parts['scheme'] ?? ''), ['http', 'https'], true) && !empty($parts['host'])) {
                $port = isset($parts['port']) ? ':' . (int)$parts['port'] : '';
                $path = isset($parts['path']) ? '/' . trim((string)$parts['path'], '/') : '';
                return $parts['scheme'] . '://' . $parts['host'] . $port . rtrim($path, '/');
            }
        }
        $host = $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? 'localhost');
        $host = preg_replace('/[^a-z0-9.\-:]/i', '', (string)$host) ?: 'localhost';
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
        return ($https ? 'https' : 'http') . '://' . $host;
    }

    function ct_content_requires_codemirror(string $content): bool {
        return (bool) preg_match(
            '/<(script|style|iframe|embed|object|form|svg|canvas|php|link|meta|table|thead|tbody|tfoot|tr|th|td)[\s>]|on[a-z]+\s*=|style\s*=/i',
            $content
        );
    }

    function ct_render_not_found(): never {
        http_response_code(404);
        $path = defined('FRONTEND_404_PATH')
            ? FRONTEND_404_PATH
            : dirname(__DIR__, 3) . '/app/frontend_404.php';
        require $path;
        exit;
    }

    // URL for a post/page in a given locale (null = default locale, no prefix)
    function ct_post_url(string $slug, ?string $locale = null): string {
        $prefix = ($locale !== null && $locale !== '' && $locale !== content_default_locale()) ? '/' . $locale : '';
        return rtrim($prefix . '/' . ltrim($slug, '/'), '/') . '/';
    }

    function ct_homepage_url(?string $locale = null): string {
        if ($locale === null || $locale === '' || $locale === content_default_locale()) return '/';
        return '/' . rawurlencode($locale) . '/';
    }

    function ct_category_url(PDO $pdo, array $category, ?string $locale = null, int $page = 1, string $query = ''): ?string {
        $isDefault = $locale === null || $locale === '' || $locale === content_default_locale();
        if ($isDefault && function_exists('get_category_permalink')) {
            $hadRequestLocale = array_key_exists('ct_request_locale', $GLOBALS);
            $requestLocale = $GLOBALS['ct_request_locale'] ?? null;
            unset($GLOBALS['ct_request_locale']);
            try {
                return get_category_permalink($pdo, $category, $page, $query);
            } finally {
                if ($hadRequestLocale) $GLOBALS['ct_request_locale'] = $requestLocale;
            }
        }

        $path = ct_category_translation_path($pdo, $category, (string)$locale);
        if ($path === null || $path === '') return null;
        $base = trim(function_exists('get_category_base') ? get_category_base($pdo) : '/category/', '/');
        $encodedPath = implode('/', array_map('rawurlencode', explode('/', $path)));
        $url = '/' . rawurlencode((string)$locale) . '/' . ($base !== '' ? $base . '/' : '') . $encodedPath . '/';
        if ($page > 1) $url .= 'p/' . $page . '/';
        return $query !== '' ? $url . '?' . http_build_query(['q' => $query]) : $url;
    }
}
