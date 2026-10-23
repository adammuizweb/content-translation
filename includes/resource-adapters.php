<?php
declare(strict_types=1);

// Runtime interoperability for multilingual resources owned by other plugins.

if (!defined('CT_RESOURCE_ADAPTER_LIMIT')) define('CT_RESOURCE_ADAPTER_LIMIT', 32);
if (!defined('CT_RESOURCE_ALTERNATE_LIMIT')) define('CT_RESOURCE_ALTERNATE_LIMIT', 64);
if (!defined('CT_RESOURCE_IDENTITY_LIMIT')) define('CT_RESOURCE_IDENTITY_LIMIT', 191);
if (!defined('CT_RESOURCE_PATH_LIMIT')) define('CT_RESOURCE_PATH_LIMIT', 2048);
if (!defined('CT_RESOURCE_QUERY_LIMIT')) define('CT_RESOURCE_QUERY_LIMIT', 2048);

if (!function_exists('ct_resource_adapter_supports')) {
    function ct_resource_adapter_supports(string $capability): bool {
        return in_array($capability, ['alternate-query', 'locking-locale-grant'], true);
    }
}

if (!function_exists('ct_normalize_resource_adapters')) {
    function ct_normalize_resource_adapters(mixed $registered): array {
        if (!is_array($registered) || !array_is_list($registered) || count($registered) > CT_RESOURCE_ADAPTER_LIMIT) return [];

        $normalized = [];
        foreach ($registered as $adapter) {
            if (!is_array($adapter)
                || ($adapter['api_version'] ?? null) !== 1
                || !is_string($adapter['key'] ?? null)
                || preg_match('/^[a-z][a-z0-9._-]{0,63}$/', $adapter['key']) !== 1
                || !is_callable($adapter['resolve'] ?? null)
                || !is_callable($adapter['current'] ?? null)
                || !is_callable($adapter['alternates'] ?? null)
                || isset($normalized[$adapter['key']])) {
                return [];
            }
            $normalized[$adapter['key']] = [
                'api_version' => 1,
                'key' => $adapter['key'],
                'resolve' => $adapter['resolve'],
                'current' => $adapter['current'],
                'alternates' => $adapter['alternates'],
            ];
        }
        return $normalized;
    }
}

if (!function_exists('ct_resource_adapters')) {
    function ct_resource_adapters(): array {
        static $loaded = false;
        static $adapters = [];
        if ($loaded) return $adapters;
        $loaded = true;
        if (!function_exists('apply_filters')) return $adapters;
        try {
            $adapters = ct_normalize_resource_adapters(apply_filters('content_translation_resource_adapters', []));
        } catch (Throwable $error) {
            $adapters = [];
        }
        return $adapters;
    }
}

if (!function_exists('ct_resource_adapter_identity')) {
    function ct_resource_adapter_identity(mixed $identity): ?string {
        if (!is_scalar($identity)) return null;
        if (is_float($identity) && !is_finite($identity)) return null;
        $identity = (string)$identity;
        if ($identity === '' || strlen($identity) > CT_RESOURCE_IDENTITY_LIMIT || preg_match('/[\x00-\x1F\x7F]/', $identity)) return null;
        return $identity;
    }
}

if (!function_exists('ct_resource_adapter_path')) {
    function ct_resource_adapter_path(mixed $path, bool $rootRelative): ?string {
        if (!is_string($path) || $path === '' || strlen($path) > CT_RESOURCE_PATH_LIMIT
            || preg_match('//u', $path) !== 1 || preg_match('/[\x00-\x20\x7F\\\\?#]/', $path)) return null;
        if ($rootRelative !== str_starts_with($path, '/')) return null;
        if (str_starts_with($path, '//') || preg_match('/%(?![0-9A-Fa-f]{2})/', $path)) return null;

        $trailingSlash = $rootRelative && $path !== '/' && str_ends_with($path, '/');
        $trimmed = trim($path, '/');
        if ($trimmed === '') return $rootRelative ? '/' : null;
        $segments = explode('/', $trimmed);
        foreach ($segments as $segment) {
            $decoded = rawurldecode($segment);
            if ($segment === '' || $decoded === '.' || $decoded === '..'
                || preg_match('/[\x00-\x20\x7F\\\\\/]/', $decoded)) return null;
        }
        $normalized = implode('/', $segments);
        return $rootRelative ? '/' . $normalized . ($trailingSlash ? '/' : '') : $normalized;
    }
}

if (!function_exists('ct_resource_adapter_query')) {
    function ct_resource_adapter_query(mixed $query): ?string {
        if (!is_string($query) || strlen($query) > CT_RESOURCE_QUERY_LIMIT
            || preg_match('//u', $query) !== 1 || str_starts_with($query, '?')
            || preg_match('/[\x00-\x20\x7F\\\\#]/', $query)
            || preg_match('/%(?![0-9A-Fa-f]{2})/', $query)) return null;
        return $query;
    }
}

if (!function_exists('ct_resource_adapter_compose_path')) {
    function ct_resource_adapter_compose_path(string $path, ?string $query = null): string {
        return $path . ($query !== null && $query !== '' ? '?' . $query : '');
    }
}

if (!function_exists('ct_resource_adapter_unavailable')) {
    function ct_resource_adapter_unavailable(string $locale, ?string $adapterKey = null): array {
        return [
            'valid' => false,
            'available' => false,
            'adapter_key' => $adapterKey,
            'resource_id' => null,
            'locale' => $locale,
            'route_path' => null,
        ];
    }
}

if (!function_exists('ct_resource_adapter_resolve')) {
    function ct_resource_adapter_resolve(PDO $pdo, string $locale, string $path): ?array {
        $path = ct_resource_adapter_path($path, false);
        if ($path === null) return ct_resource_adapter_unavailable($locale);

        $matches = [];
        foreach (ct_resource_adapters() as $adapter) {
            try {
                $result = ($adapter['resolve'])($pdo, $locale, $path);
            } catch (Throwable $error) {
                return ct_resource_adapter_unavailable($locale, $adapter['key']);
            }
            if ($result === null) continue;
            if (!is_array($result) || !is_bool($result['available'] ?? null)) {
                return ct_resource_adapter_unavailable($locale, $adapter['key']);
            }
            if ($result['available'] === false) {
                $matches[] = ct_resource_adapter_unavailable($locale, $adapter['key']);
                continue;
            }
            $identity = ct_resource_adapter_identity($result['resource_id'] ?? null);
            $routePath = ct_resource_adapter_path($result['route_path'] ?? null, false);
            if ($identity === null || $routePath === null) {
                return ct_resource_adapter_unavailable($locale, $adapter['key']);
            }
            $matches[] = [
                'valid' => true,
                'available' => true,
                'adapter_key' => $adapter['key'],
                'resource_id' => $identity,
                'locale' => $locale,
                'route_path' => $routePath,
            ];
        }

        if ($matches === []) return null;
        if (count($matches) !== 1) return ct_resource_adapter_unavailable($locale);
        return $matches[0];
    }
}

if (!function_exists('ct_resource_adapter_current')) {
    function ct_resource_adapter_current(PDO $pdo): ?array {
        $locale = function_exists('content_default_locale') ? content_default_locale() : 'en';
        $matches = [];
        foreach (ct_resource_adapters() as $adapter) {
            try {
                $result = ($adapter['current'])($pdo);
            } catch (Throwable $error) {
                return ct_resource_adapter_unavailable($locale, $adapter['key']);
            }
            if ($result === null) continue;
            $identity = is_array($result) ? ct_resource_adapter_identity($result['resource_id'] ?? null) : null;
            if ($identity === null) return ct_resource_adapter_unavailable($locale, $adapter['key']);
            $matches[] = [
                'valid' => true,
                'available' => true,
                'adapter_key' => $adapter['key'],
                'resource_id' => $identity,
                'locale' => $locale,
                'route_path' => null,
            ];
        }
        if ($matches === []) return null;
        if (count($matches) !== 1) return ct_resource_adapter_unavailable($locale);
        return $matches[0];
    }
}

if (!function_exists('ct_resource_adapter_validate_alternates')) {
    function ct_resource_adapter_validate_alternates(PDO $pdo, mixed $rows, string $activeLocale): ?array {
        if (!is_array($rows) || !array_is_list($rows) || $rows === [] || count($rows) > CT_RESOURCE_ALTERNATE_LIMIT
            || !function_exists('ct_content_locales')) return null;
        try {
            $locales = ct_content_locales($pdo);
        } catch (Throwable $error) {
            return null;
        }
        if (!is_array($locales) || !in_array($activeLocale, $locales, true)) return null;

        $normalized = [];
        $seenLocales = [];
        $seenPaths = [];
        foreach ($rows as $row) {
            $locale = is_array($row) && is_string($row['locale'] ?? null) ? $row['locale'] : '';
            $path = is_array($row) ? ct_resource_adapter_path($row['path'] ?? null, true) : null;
            $query = null;
            if (is_array($row) && array_key_exists('query', $row)) {
                $query = ct_resource_adapter_query($row['query']);
                if ($query === null) return null;
            }
            $canonicalPath = rawurldecode($path === '/' ? '/' : rtrim((string)$path, '/'));
            if ($locale === '' || !in_array($locale, $locales, true) || $path === null
                || isset($seenLocales[$locale]) || isset($seenPaths[$canonicalPath])) return null;
            $seenLocales[$locale] = true;
            $seenPaths[$canonicalPath] = true;
            $normalizedRow = ['locale' => $locale, 'path' => $path];
            if ($query !== null && $query !== '') $normalizedRow['query'] = $query;
            $normalizedRow['active'] = $locale === $activeLocale;
            $normalized[] = $normalizedRow;
        }
        return isset($seenLocales[$activeLocale]) ? $normalized : null;
    }
}

if (!function_exists('ct_resource_adapter_alternates')) {
    function ct_resource_adapter_alternates(PDO $pdo, array $context): ?array {
        if (empty($context['valid']) || empty($context['available'])) return null;
        $adapter = ct_resource_adapters()[(string)($context['adapter_key'] ?? '')] ?? null;
        if (!is_array($adapter)) return null;
        try {
            $rows = ($adapter['alternates'])($pdo, [
                'adapter_key' => $adapter['key'],
                'resource_id' => (string)$context['resource_id'],
                'locale' => (string)$context['locale'],
                'route_path' => is_string($context['route_path'] ?? null) ? $context['route_path'] : null,
            ]);
        } catch (Throwable $error) {
            return null;
        }
        return ct_resource_adapter_validate_alternates($pdo, $rows, (string)$context['locale']);
    }
}

if (!function_exists('ct_resource_adapter_metadata_context')) {
    function ct_resource_adapter_metadata_context(PDO $pdo): ?array {
        if (!array_key_exists('ct_resource_adapter_context_checked', $GLOBALS)) {
            $GLOBALS['ct_resource_adapter_context_checked'] = true;
            $default = function_exists('content_default_locale') ? content_default_locale() : 'en';
            $requestLocale = $GLOBALS['ct_request_locale'] ?? $default;
            $GLOBALS['ct_resource_adapter_context'] = $requestLocale === $default
                ? ct_resource_adapter_current($pdo)
                : null;
        }
        $context = $GLOBALS['ct_resource_adapter_context'] ?? null;
        if (!is_array($context) || empty($context['valid']) || empty($context['available'])) return null;
        if (!array_key_exists('alternate_rows', $context)) {
            $context['alternate_rows'] = ct_resource_adapter_alternates($pdo, $context);
            $GLOBALS['ct_resource_adapter_context'] = $context;
        }
        if (!is_array($context['alternate_rows']) || $context['alternate_rows'] === []) return null;
        return $context;
    }
}

if (!function_exists('ct_external_resource_locale_edit_granted')) {
    /**
     * Tests CT workspace and locale authority only. Consumers must separately
     * enforce their own resource permissions and mutation security.
     */
    function ct_external_resource_locale_edit_granted(PDO $pdo, string $locale, ?int $userId = null, bool $lock = false): bool {
        $default = function_exists('content_default_locale') ? content_default_locale() : 'en';
        if ($locale === '' || $locale === $default || !function_exists('ct_enabled_locales')
            || !function_exists('ct_user_can_workspace') || !function_exists('ct_user_has_locale_edit_grant')) return false;
        try {
            if (!in_array($locale, ct_enabled_locales($pdo), true)) return false;
            $userId ??= function_exists('ct_current_user_id') ? ct_current_user_id() : 0;
            return $userId > 0
                && ct_user_can_workspace($pdo, $userId)
                && ct_user_has_locale_edit_grant($pdo, $userId, $locale, $lock);
        } catch (Throwable $error) {
            return false;
        }
    }
}
