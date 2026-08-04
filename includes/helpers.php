<?php
declare(strict_types=1);

// Content Translation — shared helpers

if (!function_exists('ct_ensure_schema')) {

    function ct_ensure_schema(PDO $pdo): void {
        static $done = false;
        if ($done) return;
        $done = true;
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS post_translations (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                post_id INT UNSIGNED NOT NULL,
                locale VARCHAR(10) NOT NULL,
                title VARCHAR(255) NOT NULL DEFAULT '',
                slug VARCHAR(255) NOT NULL DEFAULT '',
                content MEDIUMTEXT NULL,
                status ENUM('draft','published') NOT NULL DEFAULT 'published',
                provider VARCHAR(32) NOT NULL DEFAULT 'manual',
                source_hash CHAR(64) NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_post_locale (post_id, locale),
                KEY idx_locale_slug (locale, slug)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            ct_add_column_if_missing($pdo, 'post_translations', 'status', "ENUM('draft','published') NOT NULL DEFAULT 'published'");
            ct_add_column_if_missing($pdo, 'post_translations', 'provider', "VARCHAR(32) NOT NULL DEFAULT 'manual'");
            ct_add_column_if_missing($pdo, 'post_translations', 'source_hash', 'CHAR(64) NULL');
        } catch (Throwable $e) {
            error_log('[content-translation] schema error: ' . $e->getMessage());
        }
    }

    function ct_add_column_if_missing(PDO $pdo, string $table, string $column, string $definition): void {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
        $stmt->execute([$table, $column]);
        if ((int)$stmt->fetchColumn() === 0) {
            $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
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

    function ct_save_translation(PDO $pdo, int $postId, string $locale, array $data): bool {
        ct_ensure_schema($pdo);
        try {
            $stmt = $pdo->prepare("INSERT INTO post_translations (post_id, locale, title, slug, content, status, provider, source_hash)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE title = VALUES(title), slug = VALUES(slug), content = VALUES(content), status = VALUES(status), provider = VALUES(provider), source_hash = VALUES(source_hash)");
            return $stmt->execute([
                $postId,
                $locale,
                (string)($data['title'] ?? ''),
                (string)($data['slug'] ?? ''),
                (string)($data['content'] ?? ''),
                in_array(($data['status'] ?? 'published'), ['draft', 'published'], true) ? $data['status'] : 'published',
                (string)($data['provider'] ?? 'manual'),
                (string)($data['source_hash'] ?? '') ?: null,
            ]);
        } catch (Throwable $e) {
            error_log('[content-translation] save error: ' . $e->getMessage());
            return false;
        }
    }

    function ct_get_published_translation(PDO $pdo, int $postId, string $locale): ?array {
        $translation = ct_get_translation($pdo, $postId, $locale);
        return $translation && ($translation['status'] ?? 'published') === 'published' ? $translation : null;
    }

    function ct_translation_source_hash(array $post): string {
        return hash('sha256', implode("\n", [
            (string)($post['title'] ?? ''),
            (string)($post['slug'] ?? ''),
            (string)($post['content'] ?? ''),
        ]));
    }

    function ct_machine_provider(PDO $pdo): string {
        $provider = function_exists('settings_get') ? (string)settings_get($pdo, 'content_translation_machine_provider', 'none') : 'none';
        return in_array($provider, ['none', 'libretranslate'], true) ? $provider : 'none';
    }

    function ct_libretranslate_request(PDO $pdo, string $text, string $sourceLocale, string $targetLocale): array {
        $url = function_exists('settings_get') ? trim((string)settings_get($pdo, 'content_translation_libretranslate_url', '')) : '';
        $apiKey = function_exists('settings_get') ? trim((string)settings_get($pdo, 'content_translation_libretranslate_api_key', '')) : '';
        if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) return ['ok' => false, 'error' => 'LibreTranslate URL is not configured'];
        if (!function_exists('curl_init')) return ['ok' => false, 'error' => 'cURL extension is not available'];

        $payload = ['q' => $text, 'source' => $sourceLocale, 'target' => $targetLocale, 'format' => 'html'];
        if ($apiKey !== '') $payload['api_key'] = $apiKey;
        $ch = curl_init(rtrim($url, '/') . '/translate');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
        ]);
        $response = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        if ($response === false || $status < 200 || $status >= 300) return ['ok' => false, 'error' => $error !== '' ? $error : 'LibreTranslate request failed'];
        $data = json_decode((string)$response, true);
        if (!is_array($data) || !isset($data['translatedText'])) return ['ok' => false, 'error' => 'LibreTranslate returned an invalid response'];
        return ['ok' => true, 'text' => (string)$data['translatedText']];
    }

    function ct_delete_translation(PDO $pdo, int $postId, string $locale): bool {
        ct_ensure_schema($pdo);
        try {
            $stmt = $pdo->prepare("DELETE FROM post_translations WHERE post_id = ? AND locale = ?");
            return $stmt->execute([$postId, $locale]);
        } catch (Throwable $e) {
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
            $stmt = $pdo->prepare("SELECT * FROM post_translations WHERE locale = ? AND slug = ? LIMIT 1");
            $stmt->execute([$locale, $slug]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row ?: null;
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

    function ct_base_url(): string {
        $host = $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? 'localhost');
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
        return ($https ? 'https' : 'http') . '://' . $host;
    }

    // URL for a post/page in a given locale (null = default locale, no prefix)
    function ct_post_url(string $slug, ?string $locale = null): string {
        $prefix = ($locale !== null && $locale !== '' && $locale !== content_default_locale()) ? '/' . $locale : '';
        return $prefix . '/' . ltrim($slug, '/');
    }
}
