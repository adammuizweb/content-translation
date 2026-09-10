<?php
declare(strict_types=1);

function ct_localized_media_supported(): bool {
    foreach (['media_extension_context', 'media_extension_input', 'media_picker_query', 'media_load_live', 'media_client_url',
        'media_filter_data', 'media_public_extensions', 'media_create_before_publication', 'media_post_image_alt'] as $function) {
        if (!function_exists($function)) return false;
    }
    return class_exists('ResourceLifecycleDatabase');
}

function ct_localized_media_state_exists(PDO $pdo): bool {
    $count = $pdo->query('SELECT (SELECT COUNT(*) FROM ct_media_profiles) + (SELECT COUNT(*) FROM ct_media_translations) + (SELECT COUNT(*) FROM ct_post_featured_media)')->fetchColumn();
    return (int)$count > 0;
}

function ct_ensure_media_schema(PDO $pdo): void {
    static $done = [];
    $key = spl_object_id($pdo);
    if (isset($done[$key])) return;
    if ((string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
        $probe = $pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name IN ('ct_media_profiles','ct_media_available_locales','ct_media_translations','ct_post_featured_media')");
        if ((int)$probe->fetchColumn() !== 4) throw new RuntimeException('Localized media schema is unavailable.');
        $done[$key] = true;
        return;
    }
    $migration = require dirname(__DIR__) . '/migrations/0004-localized-media.php';
    if (!$migration instanceof Closure) throw new RuntimeException('Localized media schema migration is invalid.');
    $migration($pdo);
    $done[$key] = true;
}

function ct_media_locale_is_valid(PDO $pdo, string $locale): bool {
    return preg_match('/\A[a-z]{2,3}(?:-[A-Za-z0-9]{2,8})?\z/D', $locale) === 1
        && in_array($locale, ct_content_locales($pdo), true);
}

function ct_media_source_fingerprint(array $row): string {
    $state = [];
    foreach (['id', 'title', 'alt', 'caption', 'credit'] as $key) $state[$key] = $row[$key] ?? null;
    return hash('sha256', json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
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
    $stmt = $pdo->prepare('SELECT 1 FROM ct_media_available_locales WHERE media_id = ? AND locale = ? LIMIT 1');
    $stmt->execute([$mediaId, $locale]);
    return (bool)$stmt->fetchColumn();
}

function ct_media_translation(PDO $pdo, int $mediaId, string $locale): ?array {
    ct_ensure_media_schema($pdo);
    $stmt = $pdo->prepare('SELECT * FROM ct_media_translations WHERE media_id = ? AND locale = ? LIMIT 1');
    $stmt->execute([$mediaId, $locale]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
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
    return ['profile' => $profile, 'available_locales' => $available, 'translations' => $translations];
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
            return $payload;
        }
        $nullable = static fn(string $key): ?string => !empty($fields[$key . '_set']) ? (string)($fields[$key] ?? '') : null;
        $alt = $altMode === 'text' ? (string)($fields['alt'] ?? '') : null;
        if ($status === 'published' && $altMode === 'text' && trim($alt) === '') throw new InvalidArgumentException('Translated alt text cannot be empty; use decorative for an intentional empty alt.');
        $payload['translation'] = [
            'locale' => $translationLocale, 'title' => $nullable('title'), 'alt' => $alt, 'alt_mode' => $altMode,
            'caption' => $nullable('caption'), 'credit' => $nullable('credit'), 'status' => $status, 'operation' => 'save',
        ];
    }
    return $payload;
}

if (ct_localized_media_supported()) {
add_filter('media_mutation_metadata', function (array $metadata, string $operation, array $row, array $input, PDO $pdo): array {
    $fields = $metadata['extension_input']['content-translation'] ?? null;
    if (!is_array($fields)) return $metadata;
    $actorId = ct_current_user_id();
    $permission = $operation === 'create' ? 'core.media.upload' : 'core.media.update';
    if (!ct_user_can_workspace($pdo, $actorId) || !user_can($pdo, $actorId, $permission, ['owner_id' => (int)($row['user_id'] ?? 0)])) {
        throw new RuntimeException('Localized media permission denied.');
    }
    $lockedState = null;
    if ($operation === 'update') {
        $targetLocale = trim((string)($fields['translation_locale'] ?? ''));
        $newSourceLocale = trim((string)($fields['metadata_source_locale'] ?? ''));
        $lockedState = ct_lock_media_extension_state($pdo, (int)$row['id'], [$targetLocale, $newSourceLocale]);
    }
    $payload = ct_media_mutation_payload($pdo, $fields, (array)($metadata['context'] ?? []), $row, $lockedState);
    $grantLocales = is_array($payload['profile']) ? [$payload['profile']['metadata_source_locale']] : [];
    if (is_array($payload['profile']) && is_string($payload['old_source_locale']) && $payload['old_source_locale'] !== '') $grantLocales[] = $payload['old_source_locale'];
    if (is_array($payload['translation'] ?? null)) $grantLocales[] = $payload['translation']['locale'];
    foreach (array_unique($grantLocales) as $locale) {
        if (!ct_user_has_locale_edit_grant($pdo, $actorId, $locale, $pdo->inTransaction())) {
            throw new RuntimeException('Localized media language permission denied.');
        }
    }
    if (is_array($payload['profile']) && $payload['profile']['metadata_source_locale'] !== ($payload['old_source_locale'] ?? null)
        && isset($lockedState['translations'][$payload['profile']['metadata_source_locale']])) {
        throw new RuntimeException('The new source metadata language already has a media translation. Delete that translation first.');
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
}, 10, 2);

add_filter('media_data', function (array $data, array $row, array $context, PDO $pdo): array {
    $locale = trim((string)($context['content_locale'] ?? ''));
    $mediaId = (int)($row['id'] ?? 0);
    if ($locale === '' || $mediaId <= 0) return $data;
    $available = ct_media_is_available($pdo, $mediaId, $locale);
    $data['extensions']['content_translation'] = ['locale' => $locale, 'available' => $available];
    if (!$available) return $data;
    $profile = ct_media_profile($pdo, $mediaId);
    if ($profile && hash_equals((string)$profile['metadata_source_locale'], $locale)) return $data;
    $translation = ct_media_translation($pdo, $mediaId, $locale);
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
    if ($locale === '' || $locale === content_default_locale()) return $featured;
    $selection = ct_featured_selection($pdo, (int)($post['id'] ?? 0), $locale);
    if (!$selection || (string)$selection['mode'] === 'inherit') return $featured;
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
    $sourceLocale = (string)($profile['metadata_source_locale'] ?? $context['content_locale'] ?? content_default_locale());
    if (!ct_user_has_locale_edit_grant($pdo, ct_current_user_id(), $sourceLocale)) return;
    $policy = (string)($profile['availability_policy'] ?? 'all');
    $selected = $mediaId > 0 ? ct_media_available_locales($pdo, $mediaId) : [];
    echo '<fieldset class="ct-media-fields"><legend>' . htmlspecialchars(__('Localized media'), ENT_QUOTES) . '</legend>';
    echo '<input type="hidden" name="media_extension[content-translation][profile_state]" value="' . ct_media_profile_state($profile, $selected) . '">';
    echo '<label>' . htmlspecialchars(__('Original metadata language'), ENT_QUOTES) . '<select name="media_extension[content-translation][metadata_source_locale]">';
    foreach (ct_content_locales($pdo) as $locale) echo '<option value="' . htmlspecialchars($locale, ENT_QUOTES) . '"' . ($sourceLocale === $locale ? ' selected' : '') . '>' . htmlspecialchars(strtoupper($locale), ENT_QUOTES) . '</option>';
    echo '</select></label><p class="muted">' . htmlspecialchars(__('This is the language of the asset metadata, independent of the site default language.'), ENT_QUOTES) . '</p>';
    echo '<label>' . htmlspecialchars(__('Availability'), ENT_QUOTES) . '<select name="media_extension[content-translation][availability_policy]"><option value="all"' . ($policy === 'all' ? ' selected' : '') . '>' . htmlspecialchars(__('All locales'), ENT_QUOTES) . '</option><option value="selected"' . ($policy === 'selected' ? ' selected' : '') . '>' . htmlspecialchars(__('Selected locales'), ENT_QUOTES) . '</option></select></label>';
    echo '<div class="ct-media-locales">';
    foreach (ct_content_locales($pdo) as $locale) echo '<label><input type="checkbox" name="media_extension[content-translation][available_locales][]" value="' . htmlspecialchars($locale, ENT_QUOTES) . '"' . (in_array($locale, $selected, true) ? ' checked' : '') . '> ' . htmlspecialchars(strtoupper($locale), ENT_QUOTES) . '</label>';
    echo '</div></fieldset>';
}

add_action('media_admin_upload_fields', function (array $context, PDO $pdo): void { ct_render_media_profile_fields($context, $pdo); }, 10, 2);
add_action('media_admin_detail_before_fields', function (array $row, array $data, array $context, PDO $pdo): void { ct_render_media_profile_fields($context, $pdo, $row); }, 10, 4);
add_action('media_admin_detail_after_fields', function (array $row, array $data, array $context, PDO $pdo): void {
    if (!ct_user_can_workspace($pdo)) return;
    $profile = ct_media_profile($pdo, (int)$row['id']);
    $sourceLocale = (string)($profile['metadata_source_locale'] ?? $context['content_locale'] ?? content_default_locale());
    $editableLocales = array_values(array_filter(ct_content_locales($pdo), fn(string $locale): bool => $locale !== $sourceLocale && ct_user_has_locale_edit_grant($pdo, ct_current_user_id(), $locale)));
    $target = trim((string)($context['content_locale'] ?? ''));
    if (!in_array($target, $editableLocales, true)) $target = (string)($editableLocales[0] ?? '');
    if ($target === '') return;
    $translation = ct_media_translation($pdo, (int)$row['id'], $target) ?? [];
    $translationMap = [];
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
    echo '<fieldset class="ct-media-fields"><legend>' . htmlspecialchars(__('Alternate-locale metadata'), ENT_QUOTES) . '</legend>';
    echo '<label>' . htmlspecialchars(__('Language'), ENT_QUOTES) . '<select id="' . $controlId . '-locale" name="media_extension[content-translation][translation_locale]">';
    foreach ($editableLocales as $locale) echo '<option value="' . htmlspecialchars($locale, ENT_QUOTES) . '"' . ($target === $locale ? ' selected' : '') . '>' . htmlspecialchars(strtoupper($locale), ENT_QUOTES) . '</option>';
    echo '</select></label>';
    echo '<input id="' . $controlId . '-state" type="hidden" name="media_extension[content-translation][translation_state]" value="' . ct_media_translation_state($translation ?: null) . '">';
    echo '<input id="' . $controlId . '-operation" type="hidden" name="media_extension[content-translation][translation_operation]" value="save">';
    foreach (['title' => __('Title'), 'caption' => __('Caption'), 'credit' => __('Credit')] as $field => $label) {
        $set = array_key_exists($field, $translation) && $translation[$field] !== null;
        echo '<label><input id="' . $controlId . '-' . $field . '-set" type="checkbox" name="media_extension[content-translation][' . $field . '_set]" value="1"' . ($set ? ' checked' : '') . '> ' . htmlspecialchars($label, ENT_QUOTES) . '</label>';
        $tag = $field === 'caption' ? 'textarea' : 'input';
        echo $tag === 'textarea' ? '<textarea id="' . $controlId . '-caption" name="media_extension[content-translation][caption]">' . htmlspecialchars((string)($translation[$field] ?? ''), ENT_QUOTES) . '</textarea>' : '<input id="' . $controlId . '-' . $field . '" name="media_extension[content-translation][' . $field . ']" value="' . htmlspecialchars((string)($translation[$field] ?? ''), ENT_QUOTES) . '">';
    }
    $altMode = (string)($translation['alt_mode'] ?? 'inherit');
    echo '<label>' . htmlspecialchars(__('Alt text mode'), ENT_QUOTES) . '<select id="' . $controlId . '-alt-mode" name="media_extension[content-translation][alt_mode]">';
    foreach (['inherit' => __('Inherit original'), 'text' => __('Translated text'), 'decorative' => __('Decorative (empty alt)')] as $value => $label) echo '<option value="' . $value . '"' . ($altMode === $value ? ' selected' : '') . '>' . htmlspecialchars($label, ENT_QUOTES) . '</option>';
    echo '</select></label><input id="' . $controlId . '-alt" name="media_extension[content-translation][alt]" value="' . htmlspecialchars((string)($translation['alt'] ?? ''), ENT_QUOTES) . '">';
    $status = (string)($translation['status'] ?? 'draft');
    echo '<label>' . htmlspecialchars(__('Status'), ENT_QUOTES) . '<select id="' . $controlId . '-status" name="media_extension[content-translation][translation_status]"><option value="draft"' . ($status === 'draft' ? ' selected' : '') . '>' . htmlspecialchars(__('Draft'), ENT_QUOTES) . '</option><option value="published"' . ($status === 'published' ? ' selected' : '') . '>' . htmlspecialchars(__('Published'), ENT_QUOTES) . '</option></select></label>';
    echo '<button type="button" class="btn btn-danger" id="' . $controlId . '-delete">' . htmlspecialchars(__('Mark this media translation for deletion'), ENT_QUOTES) . '</button><p class="muted" id="' . $controlId . '-delete-note">' . htmlspecialchars(__('Deletion is applied when Core metadata is saved and never deletes the original metadata.'), ENT_QUOTES) . '</p></fieldset>';
    echo '<script>(function(){var id=' . json_encode($controlId) . ', rows=' . json_encode($translationMap, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . ', select=document.getElementById(id+"-locale");if(!select)return;select.addEventListener("change",function(){var row=rows[select.value]||{};["title","caption","credit"].forEach(function(field){var value=row[field],control=document.getElementById(id+"-"+field),set=document.getElementById(id+"-"+field+"-set");if(control)control.value=value==null?"":String(value);if(set)set.checked=value!=null});document.getElementById(id+"-alt").value=row.alt==null?"":String(row.alt);document.getElementById(id+"-alt-mode").value=row.alt_mode||"inherit";document.getElementById(id+"-status").value=row.status||"draft";document.getElementById(id+"-state").value=row.state||"";document.getElementById(id+"-operation").value="save"});document.getElementById(id+"-delete").addEventListener("click",function(){document.getElementById(id+"-operation").value="delete";document.getElementById(id+"-delete-note").textContent=' . json_encode(__('This media translation will be deleted when you save Core metadata.')) . '})})()</script>';
}, 10, 4);
}
