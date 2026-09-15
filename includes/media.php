<?php
declare(strict_types=1);

function ct_localized_media_supported(): bool {
    foreach (['media_extension_context', 'media_extension_input', 'media_picker_query', 'media_load_live', 'media_client_url',
        'media_filter_data', 'media_public_extensions', 'media_create_before_publication', 'media_mutation_response',
        'media_admin_list_badges', 'media_public_file_descriptor', 'media_serve_public_file', 'media_post_image_alt'] as $function) {
        if (!function_exists($function)) return false;
    }
    return class_exists('ResourceLifecycleDatabase');
}

function ct_localized_media_state_exists(PDO $pdo): bool {
    $count = $pdo->query('SELECT (SELECT COUNT(*) FROM ct_media_profiles) + (SELECT COUNT(*) FROM ct_media_translations) + (SELECT COUNT(*) FROM ct_media_aliases) + (SELECT COUNT(*) FROM ct_post_featured_media)')->fetchColumn();
    return (int)$count > 0;
}

function ct_ensure_media_schema(PDO $pdo): void {
    static $done = [];
    $key = spl_object_id($pdo);
    if (isset($done[$key])) return;
    $driver = (string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    if ($driver === 'sqlite') {
        $probe = $pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name IN ('ct_media_profiles','ct_media_available_locales','ct_media_translations','ct_media_aliases','ct_post_featured_media')");
    } elseif ($driver === 'mysql') {
        $probe = $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name IN ('ct_media_profiles','ct_media_available_locales','ct_media_translations','ct_media_aliases','ct_post_featured_media')");
    } else {
        throw new RuntimeException('Localized media schema is unavailable.');
    }
    if ((int)$probe->fetchColumn() !== 5) throw new RuntimeException('Localized media schema is unavailable.');
    $done[$key] = true;
}

function ct_media_locale_is_valid(PDO $pdo, string $locale): bool {
    return preg_match('/\A[a-z]{2,3}(?:-[A-Za-z0-9]{2,8})?\z/D', $locale) === 1
        && in_array($locale, ct_content_locales($pdo), true);
}

function ct_media_locale_label(string $locale): string {
    $presets = function_exists('content_locale_presets') ? content_locale_presets() : [];
    $base = strtolower(strtok($locale, '-') ?: $locale);
    $name = trim((string)($presets[$locale] ?? $presets[$base] ?? ''));
    return $name !== '' ? __($name) . ' (' . $locale . ')' : strtoupper($locale);
}

function ct_media_translation_context(PDO $pdo, array $context): ?string {
    if (($context['surface'] ?? '') !== 'admin.content.translation') return null;
    $locale = trim((string)($context['content_locale'] ?? ''));
    return ct_media_locale_is_valid($pdo, $locale) ? $locale : null;
}

function ct_media_source_fingerprint(array $row): string {
    $state = [];
    foreach (['id', 'title', 'alt', 'caption', 'credit'] as $key) $state[$key] = $row[$key] ?? null;
    return hash('sha256', json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
}

function &ct_media_list_cache(PDO $pdo): array {
    static $cache = [];
    $key = spl_object_id($pdo);
    if (!isset($cache[$key])) $cache[$key] = ['profiles' => [], 'availability' => [], 'translations' => [], 'aliases' => []];
    return $cache[$key];
}

function ct_prime_media_list_cache(PDO $pdo, array $rows, string $locale): void {
    $ids = array_values(array_unique(array_filter(array_map(static fn(array $row): int => (int)($row['id'] ?? 0), $rows))));
    if ($ids === [] || !ct_media_locale_is_valid($pdo, $locale)) return;
    ct_ensure_media_schema($pdo);
    $cache = &ct_media_list_cache($pdo);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    foreach ($ids as $id) {
        $cache['profiles'][$id] = null;
        $cache['availability'][$id . ':' . $locale] = false;
        $cache['translations'][$id . ':' . $locale] = null;
        $cache['aliases'][$id . ':' . $locale] = null;
    }
    $stmt = $pdo->prepare("SELECT * FROM ct_media_profiles WHERE media_id IN ({$placeholders})");
    $stmt->execute($ids);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) $cache['profiles'][(int)$row['media_id']] = $row;
    $stmt = $pdo->prepare("SELECT media_id FROM ct_media_available_locales WHERE locale = ? AND media_id IN ({$placeholders})");
    $stmt->execute(array_merge([$locale], $ids));
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) $cache['availability'][(int)$id . ':' . $locale] = true;
    foreach (['translations' => 'ct_media_translations', 'aliases' => 'ct_media_aliases'] as $bucket => $table) {
        $stmt = $pdo->prepare("SELECT * FROM {$table} WHERE locale = ? AND media_id IN ({$placeholders})");
        $stmt->execute(array_merge([$locale], $ids));
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) $cache[$bucket][(int)$row['media_id'] . ':' . $locale] = $row;
    }
}

function ct_featured_source_fingerprint(array $post): string {
    return hash('sha256', json_encode([
        'post_id' => (int)($post['id'] ?? 0),
        'thumbnail_media_id' => isset($post['thumbnail_media_id']) ? (int)$post['thumbnail_media_id'] : null,
        'thumbnail' => $post['thumbnail'] ?? null,
        'youtube' => $post['youtube'] ?? null,
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
}

function ct_media_profile(PDO $pdo, int $mediaId): ?array {
    if ($mediaId <= 0) return null;
    $cache = &ct_media_list_cache($pdo);
    if (array_key_exists($mediaId, $cache['profiles'])) return $cache['profiles'][$mediaId];
    ct_ensure_media_schema($pdo);
    $stmt = $pdo->prepare('SELECT * FROM ct_media_profiles WHERE media_id = ? LIMIT 1');
    $stmt->execute([$mediaId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function ct_media_available_locales(PDO $pdo, int $mediaId): array {
    ct_ensure_media_schema($pdo);
    $stmt = $pdo->prepare('SELECT locale FROM ct_media_available_locales WHERE media_id = ? ORDER BY locale');
    $stmt->execute([$mediaId]);
    return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

function ct_media_is_available(PDO $pdo, int $mediaId, string $locale): bool {
    $profile = ct_media_profile($pdo, $mediaId);
    if (!$profile || (string)$profile['availability_policy'] === 'all') return true;
    $cache = &ct_media_list_cache($pdo);
    $key = $mediaId . ':' . $locale;
    if (array_key_exists($key, $cache['availability'])) return $cache['availability'][$key];
    $stmt = $pdo->prepare('SELECT 1 FROM ct_media_available_locales WHERE media_id = ? AND locale = ? LIMIT 1');
    $stmt->execute([$mediaId, $locale]);
    return (bool)$stmt->fetchColumn();
}

function ct_media_translation(PDO $pdo, int $mediaId, string $locale): ?array {
    $cache = &ct_media_list_cache($pdo);
    $key = $mediaId . ':' . $locale;
    if (array_key_exists($key, $cache['translations'])) return $cache['translations'][$key];
    ct_ensure_media_schema($pdo);
    $stmt = $pdo->prepare('SELECT * FROM ct_media_translations WHERE media_id = ? AND locale = ? LIMIT 1');
    $stmt->execute([$mediaId, $locale]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function ct_media_alias(PDO $pdo, int $mediaId, string $locale): ?array {
    $cache = &ct_media_list_cache($pdo);
    $key = $mediaId . ':' . $locale;
    if (array_key_exists($key, $cache['aliases'])) return $cache['aliases'][$key];
    ct_ensure_media_schema($pdo);
    $stmt = $pdo->prepare('SELECT * FROM ct_media_aliases WHERE media_id = ? AND locale = ? LIMIT 1');
    $stmt->execute([$mediaId, $locale]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function ct_media_alias_state(?array $alias): string {
    $state = $alias === null ? ['missing' => true] : array_intersect_key($alias, array_flip([
        'media_id', 'locale', 'slug', 'created_by', 'updated_by', 'created_at', 'updated_at',
    ]));
    return hash('sha256', json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
}

function ct_media_alias_slug(string $value): string {
    $value = strtolower(trim($value));
    $value = preg_replace('/[^a-z0-9_-]+/', '-', $value) ?? '';
    return trim(preg_replace('/-+/', '-', $value) ?? '', '-_');
}

function ct_media_alias_url(string $locale, string $slug): string {
    $prefix = $locale === content_default_locale() ? '' : '/' . rawurlencode($locale);
    return $prefix . '/media/' . rawurlencode($slug) . '/';
}

function ct_media_legacy_alias_redirect_url(array $row): ?string {
    $url = media_client_url($row, false);
    $scheme = is_string($url) ? strtolower((string)parse_url($url, PHP_URL_SCHEME)) : '';
    return in_array($scheme, ['http', 'https'], true) ? $url : null;
}

function ct_media_profile_state(?array $profile, array $availableLocales): string {
    sort($availableLocales);
    $state = $profile === null ? ['missing' => true] : array_intersect_key($profile, array_flip([
        'media_id', 'metadata_source_locale', 'availability_policy', 'source_fingerprint',
        'created_by', 'updated_by', 'created_at', 'updated_at',
    ]));
    $state['available_locales'] = array_values($availableLocales);
    return hash('sha256', json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
}

function ct_media_translation_state(?array $translation): string {
    $state = $translation === null ? ['missing' => true] : array_intersect_key($translation, array_flip([
        'media_id', 'locale', 'title', 'alt', 'alt_mode', 'caption', 'credit', 'status',
        'source_fingerprint', 'created_by', 'updated_by', 'created_at', 'updated_at',
    ]));
    return hash('sha256', json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
}

function ct_lock_media_extension_state(PDO $pdo, int $mediaId, array $translationLocales): array {
    $suffix = $pdo->inTransaction() && in_array((string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME), ['mysql', 'pgsql'], true) ? ' FOR UPDATE' : '';
    $profileStmt = $pdo->prepare('SELECT * FROM ct_media_profiles WHERE media_id = ? LIMIT 1' . $suffix);
    $profileStmt->execute([$mediaId]);
    $profile = $profileStmt->fetch(PDO::FETCH_ASSOC) ?: null;
    $availableStmt = $pdo->prepare('SELECT locale FROM ct_media_available_locales WHERE media_id = ? ORDER BY locale' . $suffix);
    $availableStmt->execute([$mediaId]);
    $available = array_map('strval', $availableStmt->fetchAll(PDO::FETCH_COLUMN));
    $translations = [];
    $translationLocales = array_values(array_unique(array_filter(array_map('strval', $translationLocales))));
    sort($translationLocales);
    if ($translationLocales !== []) {
        $placeholders = implode(',', array_fill(0, count($translationLocales), '?'));
        $stmt = $pdo->prepare("SELECT * FROM ct_media_translations WHERE media_id = ? AND locale IN ({$placeholders}) ORDER BY locale" . $suffix);
        $stmt->execute(array_merge([$mediaId], $translationLocales));
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) $translations[(string)$row['locale']] = $row;
    }
    $aliases = [];
    if ($translationLocales !== []) {
        $placeholders = implode(',', array_fill(0, count($translationLocales), '?'));
        $stmt = $pdo->prepare("SELECT * FROM ct_media_aliases WHERE media_id = ? AND locale IN ({$placeholders}) ORDER BY locale" . $suffix);
        $stmt->execute(array_merge([$mediaId], $translationLocales));
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) $aliases[(string)$row['locale']] = $row;
    }
    return ['profile' => $profile, 'available_locales' => $available, 'translations' => $translations, 'aliases' => $aliases];
}

function ct_featured_selection(PDO $pdo, int $postId, string $locale, string $role = 'featured', bool $lock = false): ?array {
    ct_ensure_media_schema($pdo);
    $suffix = $lock && $pdo->inTransaction() && in_array((string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME), ['mysql', 'pgsql'], true) ? ' FOR UPDATE' : '';
    $stmt = $pdo->prepare('SELECT * FROM ct_post_featured_media WHERE post_id = ? AND locale = ? AND role = ? LIMIT 1' . $suffix);
    $stmt->execute([$postId, $locale, $role]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function ct_featured_selection_state(?array $row): string {
    $state = $row === null ? ['missing' => true] : array_intersect_key($row, array_flip([
        'post_id', 'locale', 'role', 'mode', 'media_id', 'alt_override', 'caption_override',
        'source_fingerprint', 'updated_by', 'created_at', 'updated_at',
    ]));
    return hash('sha256', json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
}

function ct_translation_editor_state(?array $translation, ?array $selection): string {
    return hash('sha256', ct_translation_row_state_token($translation) . ':' . ct_featured_selection_state($selection));
}

function ct_media_public_row(PDO $pdo, int $mediaId, string $locale, bool $lock = false): ?array {
    if (!function_exists('media_load_live') || !function_exists('media_client_url')) return null;
    $row = media_load_live($pdo, $mediaId, $lock);
    if (!$row || media_client_url($row, false) === null || !ct_media_is_available($pdo, $mediaId, $locale)) return null;
    return $row;
}

function ct_featured_candidate_from_input(PDO $pdo, array $input, string $locale, array $post, string $status): array {
    $mode = trim((string)($input['featured_mode'] ?? 'inherit'));
    if (!in_array($mode, ['inherit', 'media', 'none'], true)) throw new InvalidArgumentException('Invalid localized featured media mode.');
    $mediaId = $mode === 'media' ? (int)($input['featured_media_id'] ?? 0) : null;
    if ($mode === 'media' && $mediaId <= 0) throw new InvalidArgumentException('Choose media for the localized thumbnail.');
    $alt = array_key_exists('featured_alt_override', $input) && !empty($input['featured_alt_override_enabled'])
        ? (string)$input['featured_alt_override'] : null;
    $caption = array_key_exists('featured_caption_override', $input) && !empty($input['featured_caption_override_enabled'])
        ? (string)$input['featured_caption_override'] : null;
    if (strlen((string)$alt) > 4096 || strlen((string)$caption) > 65536) throw new InvalidArgumentException('Localized media overrides are too long.');
    if ($status === 'published' && $mode === 'media' && ct_media_public_row($pdo, (int)$mediaId, $locale) === null) {
        throw new InvalidArgumentException('Published translations require available public media.');
    }
    return compact('mode', 'mediaId', 'alt', 'caption') + ['source_fingerprint' => ct_featured_source_fingerprint($post)];
}

function ct_save_featured_selection(PDO $pdo, int $postId, string $locale, array $candidate, int $actorId): void {
    $stmt = $pdo->prepare("INSERT INTO ct_post_featured_media
        (post_id, locale, role, mode, media_id, alt_override, caption_override, source_fingerprint, created_by, updated_by)
        VALUES (?, ?, 'featured', ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE mode = VALUES(mode), media_id = VALUES(media_id), alt_override = VALUES(alt_override),
            caption_override = VALUES(caption_override), source_fingerprint = VALUES(source_fingerprint), updated_by = VALUES(updated_by)");
    if (!$stmt->execute([$postId, $locale, $candidate['mode'], $candidate['mediaId'], $candidate['alt'], $candidate['caption'], $candidate['source_fingerprint'], $actorId, $actorId])) {
        throw new RuntimeException('Localized featured media save failed.');
    }
}

function ct_media_mutation_payload(PDO $pdo, array $fields, array $context, array $row, ?array $lockedState = null): array {
    $actorId = ct_current_user_id();
    $currentProfile = $lockedState !== null ? ($lockedState['profile'] ?? null) : ct_media_profile($pdo, (int)($row['id'] ?? 0));
    $hasProfileInput = array_key_exists('metadata_source_locale', $fields);
    $locale = trim((string)($hasProfileInput ? $fields['metadata_source_locale'] : ($currentProfile['metadata_source_locale'] ?? $context['content_locale'] ?? '')));
    $policy = trim((string)($fields['availability_policy'] ?? 'all'));
    $selected = $fields['available_locales'] ?? [];
    $selected = is_array($selected) ? array_values(array_unique(array_map('strval', $selected))) : [$selected];
    $selected = array_values(array_filter($selected, fn(string $item): bool => ct_media_locale_is_valid($pdo, $item)));
    if (!ct_media_locale_is_valid($pdo, $locale)) throw new InvalidArgumentException('Choose a valid source metadata language.');
    if ($hasProfileInput && (!in_array($policy, ['all', 'selected'], true) || ($policy === 'selected' && $selected === []))) {
        throw new InvalidArgumentException('Selected availability requires at least one locale.');
    }
    if (!$hasProfileInput && !$currentProfile) throw new InvalidArgumentException('Localized media profile is unavailable.');
    if ($hasProfileInput) {
        $profileState = trim((string)($fields['profile_state'] ?? ''));
        $expected = ct_media_profile_state($currentProfile, (array)($lockedState['available_locales'] ?? []));
        if (preg_match('/\A[a-f0-9]{64}\z/D', $profileState) !== 1 || !hash_equals($expected, $profileState)) {
            throw new RuntimeException('This media profile was changed by another editor. Reload before saving.');
        }
    }
    $payload = ['actor_id' => $actorId, 'old_source_locale' => $currentProfile['metadata_source_locale'] ?? null,
        'profile' => $hasProfileInput ? ['metadata_source_locale' => $locale, 'availability_policy' => $policy, 'available_locales' => $selected] : null];
    $translationLocale = trim((string)($fields['translation_locale'] ?? ''));
    if ($translationLocale !== '') {
        if (!ct_media_locale_is_valid($pdo, $translationLocale) || $translationLocale === $locale) throw new InvalidArgumentException('Choose a valid alternate metadata language.');
        $altMode = trim((string)($fields['alt_mode'] ?? 'inherit'));
        $status = trim((string)($fields['translation_status'] ?? 'draft'));
        $translationOperation = trim((string)($fields['translation_operation'] ?? 'save'));
        if (!in_array($altMode, ['inherit', 'text', 'decorative'], true) || !in_array($status, ['draft', 'published'], true)) throw new InvalidArgumentException('Invalid media translation state.');
        if (!in_array($translationOperation, ['save', 'delete'], true)) throw new InvalidArgumentException('Invalid media translation operation.');
        $currentTranslation = $lockedState['translations'][$translationLocale] ?? null;
        $translationState = trim((string)($fields['translation_state'] ?? ''));
        if (preg_match('/\A[a-f0-9]{64}\z/D', $translationState) !== 1
            || !hash_equals(ct_media_translation_state($currentTranslation), $translationState)) {
            throw new RuntimeException('This media translation was changed by another editor. Reload before saving.');
        }
        if ($translationOperation === 'delete') {
            $payload['translation'] = ['locale' => $translationLocale, 'operation' => 'delete'];
        } else {
            $nullable = static fn(string $key): ?string => !empty($fields[$key . '_set']) ? (string)($fields[$key] ?? '') : null;
            $alt = $altMode === 'text' ? (string)($fields['alt'] ?? '') : null;
            if ($status === 'published' && $altMode === 'text' && trim($alt) === '') throw new InvalidArgumentException('Translated alt text cannot be empty; use decorative for an intentional empty alt.');
            $payload['translation'] = [
                'locale' => $translationLocale, 'title' => $nullable('title'), 'alt' => $alt, 'alt_mode' => $altMode,
                'caption' => $nullable('caption'), 'credit' => $nullable('credit'), 'status' => $status, 'operation' => 'save',
            ];
        }
    }
    $aliasLocale = trim((string)($fields['media_alias_locale'] ?? ''));
    if ($aliasLocale !== '') {
        if (!ct_media_locale_is_valid($pdo, $aliasLocale)) throw new InvalidArgumentException('Choose a valid media URL language.');
        $currentAlias = $lockedState['aliases'][$aliasLocale] ?? null;
        $aliasState = trim((string)($fields['media_alias_state'] ?? ''));
        if (preg_match('/\A[a-f0-9]{64}\z/D', $aliasState) !== 1 || !hash_equals(ct_media_alias_state($currentAlias), $aliasState)) {
            throw new RuntimeException('This media URL was changed by another editor. Reload before saving.');
        }
        $slug = ct_media_alias_slug((string)($fields['media_alias_slug'] ?? ''));
        if (strlen($slug) > 191) throw new InvalidArgumentException('Media URL slug is too long.');
        $availableForAlias = $hasProfileInput
            ? ($policy === 'all' || in_array($aliasLocale, $selected, true))
            : ((string)($currentProfile['availability_policy'] ?? '') === 'all'
                || in_array($aliasLocale, (array)($lockedState['available_locales'] ?? []), true));
        if ($slug !== '' && !$availableForAlias) throw new InvalidArgumentException('Media URL aliases require media available in that language.');
        $sameLegacyAlias = is_array($currentAlias) && hash_equals((string)$currentAlias['slug'], $slug);
        if ($slug !== '' && !$sameLegacyAlias && media_public_file_descriptor($row) === null) {
            throw new InvalidArgumentException('Media URL aliases require a locally managed public image.');
        }
        $payload['alias'] = ['locale' => $aliasLocale, 'slug' => $slug, 'operation' => $slug === '' ? 'delete' : 'save'];
    }
    return $payload;
}

if (ct_localized_media_supported()) {
add_filter('media_admin_list_rows', function (array $rows, array $context, PDO $pdo): array {
    $locale = ct_media_translation_context($pdo, $context);
    if ($locale !== null) ct_prime_media_list_cache($pdo, $rows, $locale);
    return $rows;
}, 10, 3);

add_filter('media_mutation_metadata', function (array $metadata, string $operation, array $row, array $input, PDO $pdo): array {
    $context = (array)($metadata['context'] ?? []);
    $contextLocale = ct_media_translation_context($pdo, $context);
    $fields = $metadata['extension_input']['content-translation'] ?? null;
    if (!is_array($fields)) {
        if ($contextLocale === null) return $metadata;
        $fields = [];
    }
    $actorId = ct_current_user_id();
    $permission = $operation === 'create' ? 'core.media.upload' : 'core.media.update';
    if (!ct_user_can_workspace($pdo, $actorId) || !user_can($pdo, $actorId, $permission, ['owner_id' => (int)($row['user_id'] ?? 0)])) {
        throw new RuntimeException('Localized media permission denied.');
    }
    if ($contextLocale !== null) {
        $contextProfile = $operation === 'create' ? null : ct_media_profile($pdo, (int)($row['id'] ?? 0));
        if ($operation === 'create' || $contextProfile === null) {
            $fields['metadata_source_locale'] = $contextLocale;
            $fields['availability_policy'] = 'all';
            $fields['available_locales'] = [];
            $fields['profile_state'] = ct_media_profile_state(null, []);
        } else {
            foreach (['metadata_source_locale', 'availability_policy', 'available_locales', 'profile_state'] as $key) unset($fields[$key]);
            if ((string)$contextProfile['metadata_source_locale'] !== $contextLocale) {
                $metadata['core_fields'] = [];
                foreach (['title', 'alt', 'caption', 'credit'] as $field) $metadata['core_fields'][$field] = (string)($row[$field] ?? '');
            }
        }
    }
    $lockedState = null;
    if ($operation === 'update') {
        $targetLocale = trim((string)($fields['translation_locale'] ?? ''));
        $newSourceLocale = trim((string)($fields['metadata_source_locale'] ?? ''));
        $aliasLocale = trim((string)($fields['media_alias_locale'] ?? ''));
        $lockedState = ct_lock_media_extension_state($pdo, (int)$row['id'], [$targetLocale, $newSourceLocale, $aliasLocale]);
    }
    $payload = ct_media_mutation_payload($pdo, $fields, $context, $row, $lockedState);
    $grantLocales = is_array($payload['profile']) ? [$payload['profile']['metadata_source_locale']] : [];
    if ($contextLocale !== null) $grantLocales[] = $contextLocale;
    if (is_array($payload['profile']) && is_string($payload['old_source_locale']) && $payload['old_source_locale'] !== '') $grantLocales[] = $payload['old_source_locale'];
    if (is_array($payload['translation'] ?? null)) $grantLocales[] = $payload['translation']['locale'];
    if (is_array($payload['alias'] ?? null)) $grantLocales[] = $payload['alias']['locale'];
    foreach (array_unique($grantLocales) as $locale) {
        if (!ct_user_has_locale_edit_grant($pdo, $actorId, $locale, $pdo->inTransaction())) {
            throw new RuntimeException('Localized media language permission denied.');
        }
    }
    if (is_array($payload['profile']) && $payload['profile']['metadata_source_locale'] !== ($payload['old_source_locale'] ?? null)
        && isset($lockedState['translations'][$payload['profile']['metadata_source_locale']])) {
        throw new RuntimeException('The new source metadata language already has a media translation. Delete that translation first.');
    }
    if (($payload['alias']['operation'] ?? null) === 'save') {
        $suffix = $pdo->inTransaction() && in_array((string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME), ['mysql', 'pgsql'], true) ? ' FOR UPDATE' : '';
        $owner = $pdo->prepare('SELECT media_id FROM ct_media_aliases WHERE locale = ? AND slug = ? AND media_id <> ? LIMIT 1' . $suffix);
        $owner->execute([$payload['alias']['locale'], $payload['alias']['slug'], (int)($row['id'] ?? 0)]);
        if ($owner->fetchColumn()) throw new RuntimeException('This media URL slug is already in use for that language.');
    }
    $metadata['content_translation'] = $payload;
    return $metadata;
}, 10, 6);

add_action('resource_lifecycle_before_commit', function (array $event, ResourceLifecycleDatabase $database): void {
    if (($event['resource'] ?? '') !== 'media' || !in_array($event['operation'] ?? '', ['create', 'update'], true)) return;
    $payload = $event['metadata']['content_translation'] ?? null;
    if (!is_array($event['items'][0]['after'] ?? null)) return;
    $row = $event['items'][0]['after'];
    $mediaId = (int)($row['id'] ?? 0);
    $actorId = (int)($event['actor_id'] ?? 0);
    if (!is_array($payload)) {
        if (($event['operation'] ?? '') === 'update' && $mediaId > 0 && $actorId > 0) {
            $database->prepare('UPDATE ct_media_profiles SET source_fingerprint = ?, updated_by = ? WHERE media_id = ?')
                ->execute([ct_media_source_fingerprint($row), $actorId, $mediaId]);
        }
        return;
    }
    if ($mediaId <= 0 || $actorId <= 0 || $actorId !== (int)($payload['actor_id'] ?? 0)) throw new RuntimeException('Localized media actor state changed.');
    $fingerprint = ct_media_source_fingerprint($row);
    if (is_array($payload['profile'])) {
        $profile = $payload['profile'];
        $stmt = $database->prepare("INSERT INTO ct_media_profiles (media_id, metadata_source_locale, availability_policy, source_fingerprint, created_by, updated_by)
            VALUES (?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE metadata_source_locale = VALUES(metadata_source_locale), availability_policy = VALUES(availability_policy), source_fingerprint = VALUES(source_fingerprint), updated_by = VALUES(updated_by)");
        $stmt->execute([$mediaId, $profile['metadata_source_locale'], $profile['availability_policy'], $fingerprint, $actorId, $actorId]);
        $database->prepare('DELETE FROM ct_media_available_locales WHERE media_id = ?')->execute([$mediaId]);
        if ($profile['availability_policy'] === 'selected') {
            $insert = $database->prepare('INSERT INTO ct_media_available_locales (media_id, locale) VALUES (?, ?)');
            foreach ($profile['available_locales'] as $locale) $insert->execute([$mediaId, $locale]);
        }
    } else {
        $database->prepare('UPDATE ct_media_profiles SET source_fingerprint = ?, updated_by = ? WHERE media_id = ?')->execute([$fingerprint, $actorId, $mediaId]);
    }
    if (is_array($payload['translation'] ?? null) && ($payload['translation']['operation'] ?? 'save') === 'delete') {
        $database->prepare('DELETE FROM ct_media_translations WHERE media_id = ? AND locale = ?')->execute([$mediaId, $payload['translation']['locale']]);
    } elseif (is_array($payload['translation'] ?? null)) {
        $translation = $payload['translation'];
        $stmt = $database->prepare("INSERT INTO ct_media_translations
            (media_id, locale, title, alt, alt_mode, caption, credit, status, source_fingerprint, created_by, updated_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE title = VALUES(title), alt = VALUES(alt), alt_mode = VALUES(alt_mode), caption = VALUES(caption), credit = VALUES(credit), status = VALUES(status), source_fingerprint = VALUES(source_fingerprint), updated_by = VALUES(updated_by)");
        $stmt->execute([$mediaId, $translation['locale'], $translation['title'], $translation['alt'], $translation['alt_mode'], $translation['caption'], $translation['credit'], $translation['status'], $fingerprint, $actorId, $actorId]);
    }
    if (is_array($payload['alias'] ?? null) && $payload['alias']['operation'] === 'delete') {
        $database->prepare('DELETE FROM ct_media_aliases WHERE media_id = ? AND locale = ?')->execute([$mediaId, $payload['alias']['locale']]);
    } elseif (is_array($payload['alias'] ?? null)) {
        $aliasSql = (string)$database->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite'
            ? "INSERT INTO ct_media_aliases (media_id, locale, slug, created_by, updated_by) VALUES (?, ?, ?, ?, ?)
                ON CONFLICT(media_id, locale) DO UPDATE SET slug = excluded.slug, updated_by = excluded.updated_by"
            : "INSERT INTO ct_media_aliases (media_id, locale, slug, created_by, updated_by) VALUES (?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE slug = VALUES(slug), updated_by = VALUES(updated_by)";
        $database->prepare($aliasSql)
            ->execute([$mediaId, $payload['alias']['locale'], $payload['alias']['slug'], $actorId, $actorId]);
    }
}, 10, 2);

add_filter('media_mutation_response', function (array $response, string $operation, array $row, array $context, PDO $pdo, array $metadata): array {
    $mediaId = (int)($row['id'] ?? $response['id'] ?? 0);
    if ($mediaId <= 0) return $response;

    $profile = ct_media_profile($pdo, $mediaId);
    $available = ct_media_available_locales($pdo, $mediaId);
    $translationLocale = trim((string)($metadata['content_translation']['translation']['locale'] ?? $context['content_locale'] ?? ''));
    if ($profile && $translationLocale === (string)$profile['metadata_source_locale']) $translationLocale = '';
    $tokens = [
        'profile_state' => ct_media_profile_state($profile, $available),
        'metadata_source_locale' => $profile['metadata_source_locale'] ?? null,
        'metadata_source_language' => $profile ? ct_media_locale_label((string)$profile['metadata_source_locale']) : null,
        'translation_locale' => $translationLocale !== '' ? $translationLocale : null,
        'translation_state' => $translationLocale !== '' ? ct_media_translation_state(ct_media_translation($pdo, $mediaId, $translationLocale)) : null,
        'media_alias_locale' => $context['content_locale'] ?? null,
        'media_alias_state' => isset($context['content_locale']) ? ct_media_alias_state(ct_media_alias($pdo, $mediaId, (string)$context['content_locale'])) : null,
    ];
    $response['extensions']['content_translation'] = $tokens;
    return $response;
}, 10, 6);

add_filter('media_admin_list_badges', function (array $badges, array $data, array $row, array $context, PDO $pdo): array {
    if (ct_media_translation_context($pdo, $context) === null) return $badges;
    $state = (string)($data['extensions']['content_translation']['state'] ?? '');
    $label = (string)($data['extensions']['content_translation']['state_label'] ?? '');
    if ($state !== '' && $label !== '') $badges[] = ['label' => $label, 'tone' => $state];
    return $badges;
}, 10, 5);

add_filter('media_data', function (array $data, array $row, array $context, PDO $pdo): array {
    $locale = trim((string)($context['content_locale'] ?? ''));
    $mediaId = (int)($row['id'] ?? 0);
    if ($locale === '' || $mediaId <= 0) return $data;
    $available = ct_media_is_available($pdo, $mediaId, $locale);
    $profile = ct_media_profile($pdo, $mediaId);
    $translation = $profile && !hash_equals((string)$profile['metadata_source_locale'], $locale)
        ? ct_media_translation($pdo, $mediaId, $locale) : null;
    $state = 'source_fallback';
    if (!$available) $state = 'unavailable';
    elseif ($profile && hash_equals((string)$profile['metadata_source_locale'], $locale)) $state = 'ready';
    elseif ($translation && !hash_equals((string)$translation['source_fingerprint'], ct_media_source_fingerprint($row))) $state = 'stale';
    elseif ($translation && (string)$translation['status'] === 'draft') $state = 'draft';
    elseif ($translation && (string)$translation['status'] === 'published') $state = 'ready';
    $labels = [
        'ready' => __('Ready'),
        'source_fallback' => __('Original metadata fallback'),
        'draft' => __('Draft metadata'),
        'stale' => __('Stale metadata'),
        'unavailable' => __('Unavailable'),
    ];
    $data['extensions']['content_translation'] = [
        'locale' => $locale,
        'locale_name' => ct_media_locale_label($locale),
        'available' => $available,
        'state' => $state,
        'state_label' => $labels[$state],
        'metadata_source_locale' => $profile['metadata_source_locale'] ?? null,
        'metadata_source_language' => $profile ? ct_media_locale_label((string)$profile['metadata_source_locale']) : null,
    ];
    $alias = ct_media_alias($pdo, $mediaId, $locale);
    if ($available && $alias && (media_public_file_descriptor($row) !== null || ct_media_legacy_alias_redirect_url($row) !== null)) {
        $data['url'] = ct_media_alias_url($locale, (string)$alias['slug']);
        $data['extensions']['content_translation']['slug'] = (string)$alias['slug'];
        $data['extensions']['content_translation']['permalink'] = $data['url'];
    }
    if (!$available) return $data;
    if ($profile && hash_equals((string)$profile['metadata_source_locale'], $locale)) return $data;
    if (!$translation || (string)$translation['status'] !== 'published') return $data;
    if (!hash_equals((string)$translation['source_fingerprint'], ct_media_source_fingerprint($row))) return $data;
    foreach (['title', 'caption', 'credit'] as $field) if ($translation[$field] !== null) $data[$field] = (string)$translation[$field];
    $data['alt'] = match ((string)$translation['alt_mode']) {
        'decorative' => '',
        'text' => (string)($translation['alt'] ?? ''),
        default => $data['alt'],
    };
    return $data;
}, 10, 4);

add_filter('featured_media', function ($featured, array $post, array $context, PDO $pdo) {
    $locale = trim((string)($context['content_locale'] ?? ''));
    $sourceLocale = function_exists('ct_post_source_locale') ? ct_post_source_locale($pdo, $post) : content_default_locale();
    if ($locale === '' || $locale === $sourceLocale) return $featured;
    $selection = ct_featured_selection($pdo, (int)($post['id'] ?? 0), $locale);
    if (!$selection || (string)$selection['mode'] === 'inherit') {
        $inheritedId = is_array($featured) ? (int)($featured['id'] ?? 0) : 0;
        return $inheritedId > 0 && !ct_media_is_available($pdo, $inheritedId, $locale) ? null : $featured;
    }
    if ((string)$selection['mode'] === 'none') return null;
    $row = ct_media_public_row($pdo, (int)($selection['media_id'] ?? 0), $locale);
    if (!$row) return null;
    $resolved = media_filter_data($pdo, $row, $context, false);
    if ($selection['alt_override'] !== null) $resolved['alt'] = (string)$selection['alt_override'];
    if ($selection['caption_override'] !== null) $resolved['caption'] = (string)$selection['caption_override'];
    return $resolved;
}, 10, 4);

add_action('resource_lifecycle_before_mutation', function (array $event, ResourceLifecycleDatabase $database): void {
    if (($event['resource'] ?? '') !== 'media' || ($event['operation'] ?? '') !== 'purge') return;
    foreach ($event['items'] as $item) {
        $mediaId = (int)($item['id'] ?? 0);
        $block = $database->prepare("SELECT 1 FROM ct_post_featured_media f
            INNER JOIN post_translations pt ON pt.post_id = f.post_id AND pt.locale = f.locale AND pt.status = 'published'
            INNER JOIN posts p ON p.id = f.post_id AND p.is_deleted = 0
            WHERE f.media_id = ? AND f.mode = 'media' LIMIT 1");
        $block->execute([$mediaId]);
        if ($block->fetchColumn()) throw new RuntimeException('Media is selected by a published localized representation.');
        $database->prepare("DELETE FROM ct_post_featured_media WHERE media_id = ? AND (
            NOT EXISTS (SELECT 1 FROM posts p WHERE p.id = ct_post_featured_media.post_id AND p.is_deleted = 0)
            OR NOT EXISTS (SELECT 1 FROM post_translations pt WHERE pt.post_id = ct_post_featured_media.post_id AND pt.locale = ct_post_featured_media.locale AND pt.status = 'published')
        )")->execute([$mediaId]);
        $database->prepare("DELETE FROM ct_post_featured_media WHERE mode = 'media' AND media_id IS NULL")->execute();
        $database->prepare('DELETE FROM ct_media_profiles WHERE media_id = ?')->execute([$mediaId]);
    }
}, 10, 2);

function ct_render_media_profile_fields(array $context, PDO $pdo, ?array $row = null): void {
    if (!ct_user_can_workspace($pdo)) return;
    $mediaId = (int)($row['id'] ?? 0);
    $profile = $mediaId > 0 ? ct_media_profile($pdo, $mediaId) : null;
    $contextLocale = ct_media_translation_context($pdo, $context);
    $sourceLocale = (string)($profile['metadata_source_locale'] ?? $contextLocale ?? content_default_locale());
    $requiredLocale = $contextLocale ?? $sourceLocale;
    if (!ct_user_has_locale_edit_grant($pdo, ct_current_user_id(), $requiredLocale)) return;
    $editableProfileLocales = array_values(array_filter(ct_content_locales($pdo), fn(string $locale): bool => ct_user_has_locale_edit_grant($pdo, ct_current_user_id(), $locale)));
    $policy = (string)($profile['availability_policy'] ?? 'all');
    $selected = $mediaId > 0 ? ct_media_available_locales($pdo, $mediaId) : [];
    $controlId = 'ct-media-profile-' . $mediaId;
    echo '<fieldset class="ct-media-fields ct-media-localized-editor" id="' . $controlId . '"><legend>' . htmlspecialchars(__('Localized media'), ENT_QUOTES) . '</legend>';
    if ($contextLocale !== null) {
        $alias = $mediaId > 0 ? ct_media_alias($pdo, $mediaId, $contextLocale) : null;
        if ($profile === null) {
            echo '<input type="hidden" name="media_extension[content-translation][profile_state]" value="' . ct_media_profile_state(null, []) . '">';
            echo '<input type="hidden" name="media_extension[content-translation][metadata_source_locale]" value="' . htmlspecialchars($contextLocale, ENT_QUOTES) . '">';
            echo '<input type="hidden" name="media_extension[content-translation][availability_policy]" value="all">';
        }
        echo '<div class="ct-media-profile-grid"><div><strong>' . htmlspecialchars(__('Metadata language'), ENT_QUOTES) . '</strong><div class="ct-readonly">' . htmlspecialchars(ct_media_locale_label($contextLocale), ENT_QUOTES) . '</div></div>';
        echo '<div><strong>' . htmlspecialchars(__('Original metadata language'), ENT_QUOTES) . '</strong><div class="ct-readonly">' . htmlspecialchars(ct_media_locale_label($sourceLocale), ENT_QUOTES) . '</div></div></div>';
        echo '<p class="muted">' . htmlspecialchars($mediaId > 0
            ? __('This pane is locked to the content language. One media file can serve every language; translate only its metadata here.')
            : __('Add the original metadata in this content language. One uploaded file can serve every language.'), ENT_QUOTES) . '</p>';
        echo '<input type="hidden" name="media_extension[content-translation][media_alias_locale]" value="' . htmlspecialchars($contextLocale, ENT_QUOTES) . '">';
        echo '<input type="hidden" name="media_extension[content-translation][media_alias_state]" value="' . ct_media_alias_state($alias) . '">';
        echo '<label>' . htmlspecialchars(__('Image URL slug'), ENT_QUOTES) . '<input type="text" name="media_extension[content-translation][media_alias_slug]" value="' . htmlspecialchars((string)($alias['slug'] ?? ''), ENT_QUOTES) . '" maxlength="191" pattern="[a-z0-9_-]*" placeholder="campus-library"></label>';
        echo '<p class="muted">' . htmlspecialchars(__('Optional. This creates a language-specific URL for the same media file; it does not upload or rename the image.'), ENT_QUOTES) . '</p>';
        if ($mediaId > 0) echo '<div class="ct-media-metadata-slot" id="ct-media-translation-' . $mediaId . '-slot"></div>';
        echo '</fieldset>';
        return;
    }
    echo '<input type="hidden" name="media_extension[content-translation][profile_state]" value="' . ct_media_profile_state($profile, $selected) . '">';
    echo '<div class="ct-media-profile-grid"><label>' . htmlspecialchars(__('Original metadata language'), ENT_QUOTES) . '<select id="' . $controlId . '-source-locale" name="media_extension[content-translation][metadata_source_locale]">';
    foreach ($editableProfileLocales as $locale) echo '<option value="' . htmlspecialchars($locale, ENT_QUOTES) . '"' . ($sourceLocale === $locale ? ' selected' : '') . '>' . htmlspecialchars(ct_media_locale_label($locale), ENT_QUOTES) . '</option>';
    echo '</select></label>';
    echo '<label>' . htmlspecialchars(__('Availability'), ENT_QUOTES) . '<select id="' . $controlId . '-policy" name="media_extension[content-translation][availability_policy]"><option value="all"' . ($policy === 'all' ? ' selected' : '') . '>' . htmlspecialchars(__('All locales'), ENT_QUOTES) . '</option><option value="selected"' . ($policy === 'selected' ? ' selected' : '') . '>' . htmlspecialchars(__('Selected locales'), ENT_QUOTES) . '</option></select></label></div>';
    echo '<p class="muted">' . htmlspecialchars(__('All locales keeps every language selected. Choose Selected locales to customize availability.'), ENT_QUOTES) . '</p>';
    echo '<div class="ct-media-locales" id="' . $controlId . '-locales">';
    foreach (ct_content_locales($pdo) as $locale) echo '<label><input type="checkbox" name="media_extension[content-translation][available_locales][]" value="' . htmlspecialchars($locale, ENT_QUOTES) . '"' . ($policy === 'all' || in_array($locale, $selected, true) ? ' checked' : '') . ($policy === 'all' ? ' disabled' : '') . '> ' . htmlspecialchars(ct_media_locale_label($locale), ENT_QUOTES) . '</label>';
    echo '</div>';
    if ($mediaId > 0) {
        $editorLocales = $editableProfileLocales;
        $target = trim((string)($context['content_locale'] ?? ''));
        if (!in_array($target, $editorLocales, true)) $target = $sourceLocale;
        echo '<div class="ct-media-language-editor"><label>' . htmlspecialchars(__('Metadata language'), ENT_QUOTES) . '<select id="ct-media-translation-' . $mediaId . '-locale">';
        foreach ($editorLocales as $locale) {
            $label = ct_media_locale_label($locale) . ($locale === $sourceLocale ? ' (' . __('Original') . ')' : '');
            echo '<option value="' . htmlspecialchars($locale, ENT_QUOTES) . '"' . ($target === $locale ? ' selected' : '') . '>' . htmlspecialchars($label, ENT_QUOTES) . '</option>';
        }
        echo '</select></label><p class="muted">' . htmlspecialchars(__('The fields below show the selected language. Save before editing another translation.'), ENT_QUOTES) . '</p></div>';
        echo '<div class="ct-media-metadata-slot" id="ct-media-translation-' . $mediaId . '-slot"></div>';
    }
    echo '</fieldset>';
    echo '<script>(function(){var policy=document.getElementById(' . json_encode($controlId . '-policy') . '),box=document.getElementById(' . json_encode($controlId . '-locales') . ');if(!policy||!box)return;var inputs=Array.from(box.querySelectorAll("input[type=checkbox]")),selected=new Set(inputs.filter(function(input){return input.checked}).map(function(input){return input.value}));function render(){var all=policy.value==="all";inputs.forEach(function(input){input.disabled=all;input.checked=all||selected.has(input.value)});box.classList.toggle("is-muted",all)}box.addEventListener("change",function(event){if(event.target&&event.target.matches("input[type=checkbox]")){if(event.target.checked)selected.add(event.target.value);else selected.delete(event.target.value)}});policy.addEventListener("change",function(){if(policy.value==="all")selected=new Set(inputs.filter(function(input){return input.checked}).map(function(input){return input.value}));render()});render()})()</script>';
}

add_action('media_admin_upload_fields', function (array $context, PDO $pdo): void { ct_render_media_profile_fields($context, $pdo); }, 10, 2);
add_action('media_admin_detail_before_fields', function (array $row, array $data, array $context, PDO $pdo): void { ct_render_media_profile_fields($context, $pdo, $row); }, 10, 4);
add_action('media_admin_detail_after_fields', function (array $row, array $data, array $context, PDO $pdo): void {
    if (!ct_user_can_workspace($pdo)) return;
    $profile = ct_media_profile($pdo, (int)$row['id']);
    $sourceLocale = (string)($profile['metadata_source_locale'] ?? content_default_locale());
    $contextLocale = ct_media_translation_context($pdo, $context);
    if ($contextLocale !== null) {
        if (!ct_user_has_locale_edit_grant($pdo, ct_current_user_id(), $contextLocale)) return;
        $translation = $contextLocale !== $sourceLocale ? (ct_media_translation($pdo, (int)$row['id'], $contextLocale) ?? []) : [];
        $controlId = 'ct-media-translation-' . (int)$row['id'];
        echo '<div class="ct-media-translation-controls" id="' . $controlId . '-controls"' . ($contextLocale === $sourceLocale ? ' hidden' : '') . '>';
        foreach (['title' => __('Use translated title'), 'caption' => __('Use translated caption'), 'credit' => __('Use translated credit')] as $field => $label) {
            echo '<label class="ct-check"><input id="' . $controlId . '-' . $field . '-set" type="checkbox"> ' . htmlspecialchars($label, ENT_QUOTES) . '</label>';
        }
        echo '<label>' . htmlspecialchars(__('Alt text mode'), ENT_QUOTES) . '<select id="' . $controlId . '-alt-mode">';
        foreach (['inherit' => __('Inherit original'), 'text' => __('Translated text'), 'decorative' => __('Decorative (empty alt)')] as $value => $label) echo '<option value="' . $value . '">' . htmlspecialchars($label, ENT_QUOTES) . '</option>';
        echo '</select></label><label>' . htmlspecialchars(__('Translation status'), ENT_QUOTES) . '<select id="' . $controlId . '-status"><option value="draft">' . htmlspecialchars(__('Draft'), ENT_QUOTES) . '</option><option value="published">' . htmlspecialchars(__('Published'), ENT_QUOTES) . '</option></select></label>';
        echo '<button type="button" class="btn btn-danger" id="' . $controlId . '-delete"' . ($translation === [] ? ' hidden' : '') . '>' . htmlspecialchars(__('Delete selected translation'), ENT_QUOTES) . '</button><p class="muted" id="' . $controlId . '-delete-note">' . htmlspecialchars(__('This affects only the selected translation; original metadata is retained.'), ENT_QUOTES) . '</p></div>';
        $original = ['title' => (string)($row['title'] ?? ''), 'alt' => (string)($row['alt'] ?? ''), 'caption' => (string)($row['caption'] ?? ''), 'credit' => (string)($row['credit'] ?? '')];
        $translationData = [
            'title' => $translation['title'] ?? null, 'alt' => $translation['alt'] ?? null,
            'caption' => $translation['caption'] ?? null, 'credit' => $translation['credit'] ?? null,
            'alt_mode' => $translation['alt_mode'] ?? 'inherit', 'status' => $translation['status'] ?? 'draft',
            'state' => ct_media_translation_state($translation ?: null),
        ];
        echo '<script>(function(){var id=' . json_encode($controlId) . ',mediaId=' . (int)$row['id'] . ',locale=' . json_encode($contextLocale) . ',sourceLocale=' . json_encode($sourceLocale) . ',original=' . json_encode($original, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . ',row=' . json_encode($translationData, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . ',slot=document.getElementById(id+"-slot"),controls=document.getElementById(id+"-controls"),form=document.getElementById("mdlib-media-edit-form");if(!slot||!controls||!form)return;var fields={title:document.getElementById("mdlib-field-title"),alt:document.getElementById("mdlib-field-alt"),caption:document.getElementById("mdlib-field-caption"),credit:document.getElementById("mdlib-field-credit")},operation="save",isSource=locale===sourceLocale;Object.keys(fields).forEach(function(field){var wrap=fields[field]&&fields[field].closest(".mdlib-field");if(wrap)slot.appendChild(wrap)});slot.appendChild(controls);if(!isSource){["title","caption","credit"].forEach(function(field){var set=document.getElementById(id+"-"+field+"-set"),has=row[field]!==null;if(set)set.checked=has;if(fields[field])fields[field].value=has?String(row[field]):String(original[field]||"")});var altMode=document.getElementById(id+"-alt-mode"),status=document.getElementById(id+"-status");altMode.value=row.alt_mode||"inherit";status.value=row.status||"draft";fields.alt.value=altMode.value==="text"?String(row.alt||""):String(original.alt||"");altMode.addEventListener("change",function(){fields.alt.value=altMode.value==="text"?String(row.alt||""):String(original.alt||"")});document.getElementById(id+"-delete").addEventListener("click",function(){operation="delete";this.disabled=true;document.getElementById(id+"-delete-note").textContent=' . json_encode(__('This media translation will be deleted when you save.')) . '})}form.addEventListener("formdata",function(event){if(isSource)return;var data=event.formData;Object.keys(original).forEach(function(field){data.set(field,String(original[field]||""))});data.set("media_extension[content-translation][translation_locale]",locale);data.set("media_extension[content-translation][translation_state]",String(row.state));data.set("media_extension[content-translation][translation_operation]",operation);if(operation==="delete")return;["title","caption","credit"].forEach(function(field){var set=document.getElementById(id+"-"+field+"-set");if(set&&set.checked){data.set("media_extension[content-translation]["+field+"_set]","1");data.set("media_extension[content-translation]["+field+"]",fields[field].value)}});data.set("media_extension[content-translation][alt_mode]",document.getElementById(id+"-alt-mode").value);data.set("media_extension[content-translation][alt]",fields.alt.value);data.set("media_extension[content-translation][translation_status]",document.getElementById(id+"-status").value)});document.addEventListener("media:updated",function(event){var detail=event.detail||{},media=detail.media||{},tokens=(detail.extensions&&detail.extensions.content_translation)||(media.extensions&&media.extensions.content_translation);if(!tokens||Number(media.id||detail.id||0)!==mediaId)return;var profileInput=form.querySelector("[name=\"media_extension[content-translation][profile_state]\"]");if(profileInput&&tokens.profile_state)profileInput.value=tokens.profile_state;if(tokens.translation_locale===locale&&tokens.translation_state)row.state=tokens.translation_state;operation="save"})})()</script>';
        echo '<script>(function(){var id=' . json_encode($controlId) . ',mediaId=' . (int)$row['id'] . ',source=' . json_encode($original, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . ',isSource=' . json_encode($contextLocale === $sourceLocale) . ',form=document.getElementById("mdlib-media-edit-form");if(!form)return;if(!isSource){["title","caption","credit"].forEach(function(field){var input=document.getElementById("mdlib-field-"+field),toggle=document.getElementById(id+"-"+field+"-set");if(!input||!toggle)return;function sync(){input.disabled=!toggle.checked;if(!toggle.checked)input.value=String(source[field]||"")}toggle.addEventListener("change",function(){sync();if(!input.disabled)input.focus()});sync()});var alt=document.getElementById("mdlib-field-alt"),mode=document.getElementById(id+"-alt-mode");if(alt&&mode){function syncAlt(){alt.disabled=mode.value!=="text";if(mode.value==="inherit")alt.value=String(source.alt||"");else if(mode.value==="decorative")alt.value=""}mode.addEventListener("change",syncAlt);syncAlt()}}document.addEventListener("media:updated",function(event){var detail=event.detail||{},media=detail.media||{},tokens=detail.extensions&&detail.extensions.content_translation;if(!tokens||Number(media.id||detail.id||0)!==mediaId)return;var aliasState=form.querySelector("[name=\"media_extension[content-translation][media_alias_state]\"]");if(aliasState&&tokens.media_alias_state)aliasState.value=tokens.media_alias_state})})()</script>';
        return;
    }
    $editableLocales = array_values(array_filter(ct_content_locales($pdo), fn(string $locale): bool => $locale !== $sourceLocale && ct_user_has_locale_edit_grant($pdo, ct_current_user_id(), $locale)));
    $translationMap = [$sourceLocale => [
        'title' => (string)($row['title'] ?? ''), 'caption' => (string)($row['caption'] ?? ''),
        'credit' => (string)($row['credit'] ?? ''), 'alt' => (string)($row['alt'] ?? ''),
        'source' => true,
    ]];
    foreach ($editableLocales as $candidateLocale) {
        $candidate = ct_media_translation($pdo, (int)$row['id'], $candidateLocale) ?? [];
        $translationMap[$candidateLocale] = [
            'title' => $candidate['title'] ?? null, 'caption' => $candidate['caption'] ?? null,
            'credit' => $candidate['credit'] ?? null, 'alt' => $candidate['alt'] ?? null,
            'alt_mode' => $candidate['alt_mode'] ?? 'inherit', 'status' => $candidate['status'] ?? 'draft',
            'state' => ct_media_translation_state($candidate ?: null),
        ];
    }
    $controlId = 'ct-media-translation-' . (int)$row['id'];
    echo '<div class="ct-media-translation-controls" id="' . $controlId . '-controls" hidden>';
    foreach (['title' => __('Use translated title'), 'caption' => __('Use translated caption'), 'credit' => __('Use translated credit')] as $field => $label) {
        echo '<label class="ct-check"><input id="' . $controlId . '-' . $field . '-set" type="checkbox"> ' . htmlspecialchars($label, ENT_QUOTES) . '</label>';
    }
    echo '<label>' . htmlspecialchars(__('Alt text mode'), ENT_QUOTES) . '<select id="' . $controlId . '-alt-mode">';
    foreach (['inherit' => __('Inherit original'), 'text' => __('Translated text'), 'decorative' => __('Decorative (empty alt)')] as $value => $label) echo '<option value="' . $value . '">' . htmlspecialchars($label, ENT_QUOTES) . '</option>';
    echo '</select></label><label>' . htmlspecialchars(__('Translation status'), ENT_QUOTES) . '<select id="' . $controlId . '-status"><option value="draft">' . htmlspecialchars(__('Draft'), ENT_QUOTES) . '</option><option value="published">' . htmlspecialchars(__('Published'), ENT_QUOTES) . '</option></select></label>';
    echo '<button type="button" class="btn btn-danger" id="' . $controlId . '-delete">' . htmlspecialchars(__('Delete selected translation'), ENT_QUOTES) . '</button><p class="muted" id="' . $controlId . '-delete-note">' . htmlspecialchars(__('This affects only the selected translation; original metadata is retained.'), ENT_QUOTES) . '</p></div>';
    echo '<script>(function(){var id=' . json_encode($controlId) . ',sourceLocale=' . json_encode($sourceLocale) . ',originalLabel=' . json_encode(__('Original')) . ',emptyState=' . json_encode(ct_media_translation_state(null)) . ',rows=' . json_encode($translationMap, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . ',sourceSelect=document.getElementById(' . json_encode('ct-media-profile-' . (int)$row['id'] . '-source-locale') . '),select=document.getElementById(id+"-locale"),slot=document.getElementById(id+"-slot"),controls=document.getElementById(id+"-controls"),form=document.getElementById("mdlib-media-edit-form");if(!sourceSelect||!select||!slot||!controls||!form)return;var sourceRow=rows[sourceLocale]||{},fields={title:document.getElementById("mdlib-field-title"),alt:document.getElementById("mdlib-field-alt"),caption:document.getElementById("mdlib-field-caption"),credit:document.getElementById("mdlib-field-credit")},current=select.value,operation="save";rows[sourceLocale]={alt_mode:"inherit",status:"draft",state:emptyState};Object.keys(fields).forEach(function(field){var wrap=fields[field]&&fields[field].closest(".mdlib-field");if(wrap)slot.appendChild(wrap)});slot.appendChild(controls);function capture(){if(current===sourceLocale){Object.keys(fields).forEach(function(field){sourceRow[field]=fields[field]?fields[field].value:""});return;}var row=rows[current]||(rows[current]={alt_mode:"inherit",status:"draft",state:emptyState});["title","caption","credit"].forEach(function(field){var set=document.getElementById(id+"-"+field+"-set");row[field]=set&&set.checked?fields[field].value:null});row.alt_mode=document.getElementById(id+"-alt-mode").value;row.alt=row.alt_mode==="text"?fields.alt.value:null;row.status=document.getElementById(id+"-status").value}function render(){var isSource=current===sourceLocale,row=isSource?sourceRow:(rows[current]||{});controls.hidden=isSource;["title","caption","credit"].forEach(function(field){var set=document.getElementById(id+"-"+field+"-set"),has=row[field]!=null;if(set)set.checked=has;if(fields[field]){fields[field].value=isSource?String(sourceRow[field]||""):String(has?row[field]:(sourceRow[field]||""));fields[field].disabled=!isSource&&!has}});var altMode=isSource?"text":(row.alt_mode||"inherit");document.getElementById(id+"-alt-mode").value=altMode;if(fields.alt){fields.alt.value=isSource?String(sourceRow.alt||""):String(altMode==="text"?(row.alt||""):(altMode==="inherit"?(sourceRow.alt||""):""));fields.alt.disabled=!isSource&&altMode!=="text"}document.getElementById(id+"-status").value=row.status||"draft";operation="save";document.getElementById(id+"-delete-note").textContent=' . json_encode(__('This affects only the selected translation; original metadata is retained.')) . '}function renderSource(){Array.from(select.options).forEach(function(option){option.textContent=option.value.toUpperCase()+(option.value===sourceLocale?" ("+originalLabel+")":"")});current=sourceLocale;select.value=current;render()}sourceSelect.addEventListener("change",function(){capture();sourceLocale=sourceSelect.value;renderSource()});select.addEventListener("change",function(){capture();current=select.value;render()});["title","caption","credit"].forEach(function(field){var set=document.getElementById(id+"-"+field+"-set");if(set)set.addEventListener("change",function(){fields[field].disabled=!set.checked;if(set.checked&&fields[field].disabled===false)fields[field].focus()})});document.getElementById(id+"-alt-mode").addEventListener("change",function(){var mode=this.value;fields.alt.disabled=mode!=="text";fields.alt.value=mode==="inherit"?String(sourceRow.alt||""):(mode==="decorative"?"":String((rows[current]||{}).alt||""));if(!fields.alt.disabled)fields.alt.focus()});document.getElementById(id+"-delete").addEventListener("click",function(){operation="delete";document.getElementById(id+"-delete-note").textContent=' . json_encode(__('This media translation will be deleted when you save.')) . '});form.addEventListener("formdata",function(event){capture();var data=event.formData;Object.keys(fields).forEach(function(field){data.set(field,String(sourceRow[field]||""))});["translation_locale","translation_state","translation_operation","title_set","title","caption_set","caption","credit_set","credit","alt_mode","alt","translation_status"].forEach(function(field){data.delete("media_extension[content-translation]["+field+"]")});if(current===sourceLocale)return;var row=rows[current]||{};data.set("media_extension[content-translation][translation_locale]",current);data.set("media_extension[content-translation][translation_state]",String(row.state||emptyState));data.set("media_extension[content-translation][translation_operation]",operation);if(operation==="delete")return;["title","caption","credit"].forEach(function(field){if(row[field]!=null){data.set("media_extension[content-translation]["+field+"_set]","1");data.set("media_extension[content-translation]["+field+"]",String(row[field]))}});data.set("media_extension[content-translation][alt_mode]",String(row.alt_mode||"inherit"));if(row.alt_mode==="text")data.set("media_extension[content-translation][alt]",String(row.alt||""));data.set("media_extension[content-translation][translation_status]",String(row.status||"draft"))});render()})()</script>';
}, 10, 4);

add_action('media_admin_detail_after_fields', function (array $row, array $data, array $context, PDO $pdo): void {
    if (ct_media_translation_context($pdo, $context) !== null) return;
    $mediaId = (int)($row['id'] ?? 0);
    $profileId = 'ct-media-profile-' . $mediaId;
    $translationId = 'ct-media-translation-' . $mediaId;
    $labels = [];
    foreach (ct_content_locales($pdo) as $locale) $labels[$locale] = ct_media_locale_label($locale);
    echo '<script>(function(){var mediaId=' . $mediaId . ',labels=' . json_encode($labels, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . ',original=' . json_encode(__('Original')) . ',form=document.getElementById("mdlib-media-edit-form"),source=document.getElementById(' . json_encode($profileId . '-source-locale') . '),select=document.getElementById(' . json_encode($translationId . '-locale') . '),states={};if(!form||!source||!select)return;function names(){Array.from(select.options).forEach(function(option){option.textContent=(labels[option.value]||option.value)+(option.value===source.value?" ("+original+")":"")})}source.addEventListener("change",function(){setTimeout(names,0)});document.addEventListener("media:updated",function(event){var detail=event.detail||{},media=detail.media||{},tokens=(detail.extensions&&detail.extensions.content_translation)||(media.extensions&&media.extensions.content_translation);if(!tokens||Number(media.id||detail.id||0)!==mediaId)return;var profile=form.querySelector("[name=\"media_extension[content-translation][profile_state]\"]");if(profile&&tokens.profile_state)profile.value=tokens.profile_state;if(tokens.translation_locale&&tokens.translation_state)states[tokens.translation_locale]=tokens.translation_state});form.addEventListener("formdata",function(event){if(states[select.value])event.formData.set("media_extension[content-translation][translation_state]",states[select.value])});names()})()</script>';
}, 20, 4);

add_action('media_admin_detail_after_fields', function (): void {
    echo '<script>(function(){var form=document.getElementById("mdlib-media-edit-form");if(!form)return;var rebase=function(){var guard=window.ADIWIRA&&window.ADIWIRA.unsavedGuard;if(!guard||typeof guard.register!=="function"||typeof guard.markSaved!=="function")return;guard.register(form);guard.markSaved(null,null,form)};if(typeof queueMicrotask==="function")queueMicrotask(rebase);else Promise.resolve().then(rebase)})()</script>';
}, 100, 4);
}
