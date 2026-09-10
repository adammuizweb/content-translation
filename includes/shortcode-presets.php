<?php
declare(strict_types=1);

// Content Translation - Shortcode Preset translation resource

if (!function_exists('ct_shortcode_preset_override_keys')) {
    function ct_shortcode_preset_override_keys(): array {
        return ['kicker'];
    }

    function ct_shortcode_preset_decode_overrides(string $json): ?array {
        if ($json === '' || strlen($json) > 4096) return null;
        try {
            $values = json_decode($json, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            return null;
        }
        if (!is_array($values) || array_is_list($values)) return null;
        foreach ($values as $key => $value) {
            if (!is_string($key) || !in_array($key, ct_shortcode_preset_override_keys(), true) || !is_string($value)) {
                return null;
            }
        }
        return $values;
    }

    function ct_shortcode_preset_translation_state_token(?array $row): string {
        if ($row === null) return hash('sha256', 'ct-shortcode-preset-translation:missing');
        $state = [];
        foreach (['id', 'preset_id', 'locale', 'title', 'overrides_json', 'status', 'created_at', 'updated_at'] as $key) {
            $state[$key] = $row[$key] ?? null;
        }
        return hash('sha256', json_encode($state, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    function ct_shortcode_preset_normalize_source_value(mixed $value): mixed {
        if (!is_array($value)) return $value;
        if (!array_is_list($value)) ksort($value, SORT_STRING);
        foreach ($value as $key => $item) $value[$key] = ct_shortcode_preset_normalize_source_value($item);
        return $value;
    }

    function ct_shortcode_preset_source_state_token(array $preset): string {
        $metaRaw = (string)($preset['meta'] ?? '');
        $meta = json_decode($metaRaw, true);
        $state = [
            'id' => (int)($preset['id'] ?? 0),
            'title' => (string)($preset['title'] ?? ''),
            'slug' => (string)($preset['slug'] ?? ''),
            'status' => (string)($preset['status'] ?? ''),
            'meta' => is_array($meta) ? ct_shortcode_preset_normalize_source_value($meta) : $metaRaw,
        ];
        return hash('sha256', json_encode($state, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    function ct_shortcode_preset_text_length(string $value): int {
        if (function_exists('mb_strlen')) return mb_strlen($value, 'UTF-8');
        $count = preg_match_all('/./us', $value, $matches);
        return $count === false ? strlen($value) : $count;
    }

    function ct_shortcode_preset_text_slice(string $value, int $length): string {
        if ($length <= 0) return '';
        if (function_exists('mb_substr')) return mb_substr($value, 0, $length, 'UTF-8');
        if (preg_match_all('/./us', $value, $matches) === false) return substr($value, 0, $length);
        return implode('', array_slice($matches[0], 0, $length));
    }

    function ct_shortcode_preset_like_pattern(string $value): string {
        return '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value) . '%';
    }

    function ct_validate_shortcode_preset_translation(array $data): array {
        foreach (['title', 'kicker', 'status'] as $field) {
            if (isset($data[$field]) && !is_scalar($data[$field])) {
                throw new InvalidArgumentException('Preset translation fields must be text.');
            }
        }
        $title = trim((string)($data['title'] ?? ''));
        $kicker = trim((string)($data['kicker'] ?? ''));
        $status = (string)($data['status'] ?? 'draft');
        if (preg_match('//u', $title) !== 1 || preg_match('//u', $kicker) !== 1) {
            throw new InvalidArgumentException('Translation text must be valid UTF-8.');
        }
        if (ct_shortcode_preset_text_length($title) > 191) {
            throw new InvalidArgumentException('Translated management title must not exceed 191 characters.');
        }
        if (ct_shortcode_preset_text_length($kicker) > 255) {
            throw new InvalidArgumentException('Localized kicker must not exceed 255 characters.');
        }
        if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $title . $kicker) === 1) {
            throw new InvalidArgumentException('Translation text contains unsupported control characters.');
        }
        if (!in_array($status, ['draft', 'published'], true)) {
            throw new InvalidArgumentException('Invalid translation status.');
        }
        if ($status === 'published' && $title === '') {
            throw new InvalidArgumentException('Published preset translations require a management title.');
        }
        return ['title' => $title, 'kicker' => $kicker, 'status' => $status];
    }

    function ct_get_shortcode_preset_translation(PDO $pdo, int $presetId, string $locale): ?array {
        if ($presetId <= 0 || $locale === '') return null;
        if (!ct_ensure_schema($pdo)) return null;
        try {
            $stmt = $pdo->prepare('SELECT * FROM shortcode_preset_translations WHERE preset_id = ? AND locale = ? LIMIT 1');
            $stmt->execute([$presetId, $locale]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) return null;
            $row['overrides'] = ct_shortcode_preset_decode_overrides((string)$row['overrides_json']);
            $row['overrides_valid'] = $row['overrides'] !== null;
            return $row;
        } catch (Throwable $e) {
            ct_report_schema_error($pdo, $e);
            return null;
        }
    }

    function ct_get_published_shortcode_preset_translation(PDO $pdo, int $presetId, string $locale): ?array {
        $translation = ct_get_shortcode_preset_translation($pdo, $presetId, $locale);
        if (!$translation
            || ($translation['status'] ?? '') !== 'published'
            || trim((string)($translation['title'] ?? '')) === ''
            || ($translation['overrides_valid'] ?? false) !== true) {
            return null;
        }
        return $translation;
    }

    function ct_published_shortcode_preset_overrides_for_locale(PDO $pdo, string $locale): array {
        static $cache = [];
        $cacheKey = spl_object_id($pdo) . ':' . $locale;
        if (array_key_exists($cacheKey, $cache)) return $cache[$cacheKey];
        if (!ct_ensure_schema($pdo)) return $cache[$cacheKey] = [];
        try {
            $stmt = $pdo->prepare("SELECT preset_id, title, overrides_json, status FROM shortcode_preset_translations WHERE locale = ? AND status = 'published'");
            $stmt->execute([$locale]);
            $out = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                if (($row['status'] ?? '') !== 'published' || trim((string)($row['title'] ?? '')) === '') continue;
                $overrides = ct_shortcode_preset_decode_overrides((string)($row['overrides_json'] ?? ''));
                if ($overrides !== null) $out[(int)$row['preset_id']] = $overrides;
            }
            return $cache[$cacheKey] = $out;
        } catch (Throwable $e) {
            ct_report_schema_error($pdo, $e);
            return $cache[$cacheKey] = [];
        }
    }

    function ct_shortcode_preset_translations(PDO $pdo, int $presetId): array {
        if ($presetId <= 0) return [];
        if (!ct_ensure_schema($pdo)) return [];
        try {
            $stmt = $pdo->prepare('SELECT * FROM shortcode_preset_translations WHERE preset_id = ? ORDER BY locale');
            $stmt->execute([$presetId]);
            $out = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $row['overrides'] = ct_shortcode_preset_decode_overrides((string)$row['overrides_json']);
                $row['overrides_valid'] = $row['overrides'] !== null;
                $out[(string)$row['locale']] = $row;
            }
            return $out;
        } catch (Throwable $e) {
            ct_report_schema_error($pdo, $e);
            return [];
        }
    }

    function ct_shortcode_preset_translation_statuses(PDO $pdo, array $presetIds): array {
        $presetIds = array_values(array_unique(array_filter(array_map('intval', $presetIds), static fn(int $id): bool => $id > 0)));
        if ($presetIds === []) return [];
        if (!ct_ensure_schema($pdo)) return [];
        $placeholders = implode(',', array_fill(0, count($presetIds), '?'));
        try {
            $stmt = $pdo->prepare("SELECT preset_id, locale, title, overrides_json, status FROM shortcode_preset_translations WHERE preset_id IN ({$placeholders})");
            $stmt->execute($presetIds);
            $out = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $status = (string)$row['status'];
                if ($status === 'published'
                    && (trim((string)$row['title']) === '' || ct_shortcode_preset_decode_overrides((string)$row['overrides_json']) === null)) {
                    $status = 'incomplete';
                }
                $out[(int)$row['preset_id']][(string)$row['locale']] = [
                    'status' => $status,
                    'title' => trim((string)$row['title']),
                ];
            }
            return $out;
        } catch (Throwable $e) {
            ct_report_schema_error($pdo, $e);
            return [];
        }
    }

    function ct_save_shortcode_preset_translation(
        PDO $pdo,
        int $presetId,
        string $locale,
        string $loadedSourceState,
        string $loadedTranslationState,
        array $data,
        int $actorId = 0
    ): array {
        if ($presetId <= 0 || !in_array($locale, ct_enabled_locales($pdo), true)) {
            throw new InvalidArgumentException('Preset or locale is not available.');
        }
        if (preg_match('/\A[a-f0-9]{64}\z/', $loadedSourceState) !== 1
            || preg_match('/\A[a-f0-9]{64}\z/', $loadedTranslationState) !== 1) {
            throw new InvalidArgumentException('Editor lock state is invalid. Reload the editor.');
        }
        $clean = ct_validate_shortcode_preset_translation($data);
        $overridesJson = json_encode(
            ['kicker' => $clean['kicker']],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );

        if (!ct_ensure_schema($pdo)) {
            throw new RuntimeException('Preset translation storage is unavailable. Check the server error log.');
        }
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) $pdo->beginTransaction();
        try {
            if ($actorId > 0 && !authorization_lock_actor_permissions($pdo, $actorId)) throw new RuntimeException('Authorization state is unavailable.');
            $presetStmt = $pdo->prepare("SELECT id, title, slug, status, meta, created_by FROM posts WHERE id = ? AND type = 'sc_preset' AND is_deleted = 0 LIMIT 1 FOR UPDATE");
            $presetStmt->execute([$presetId]);
            $preset = $presetStmt->fetch(PDO::FETCH_ASSOC);
            if (!$preset) throw new RuntimeException('Source preset no longer exists.');
            if ($actorId > 0 && (!authorization_lock_owner_contexts($pdo, [(int)$preset['created_by']])
                || !ct_user_can_workspace($pdo, $actorId)
                || !user_can($pdo, $actorId, 'core.shortcodes.update', ['owner_id' => (int)$preset['created_by']]))) {
                throw new RuntimeException('Shortcode translation permission denied.');
            }
            if (!hash_equals($loadedSourceState, ct_shortcode_preset_source_state_token($preset))) {
                throw new RuntimeException('The source preset changed after this translation was loaded. Reload before saving.');
            }

            $currentStmt = $pdo->prepare('SELECT * FROM shortcode_preset_translations WHERE preset_id = ? AND locale = ? LIMIT 1 FOR UPDATE');
            $currentStmt->execute([$presetId, $locale]);
            $current = $currentStmt->fetch(PDO::FETCH_ASSOC) ?: null;
            if (!hash_equals($loadedTranslationState, ct_shortcode_preset_translation_state_token($current))) {
                throw new RuntimeException('This preset translation was changed by another editor. Reload before saving.');
            }

            $save = $pdo->prepare("INSERT INTO shortcode_preset_translations (preset_id, locale, title, overrides_json, status)
                VALUES (?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE title = VALUES(title), overrides_json = VALUES(overrides_json), status = VALUES(status)");
            if (!$save->execute([$presetId, $locale, $clean['title'], $overridesJson, $clean['status']])) {
                throw new RuntimeException('Preset translation save failed.');
            }
            $savedStmt = $pdo->prepare('SELECT * FROM shortcode_preset_translations WHERE preset_id = ? AND locale = ? LIMIT 1');
            $savedStmt->execute([$presetId, $locale]);
            $saved = $savedStmt->fetch(PDO::FETCH_ASSOC) ?: null;
            if ($saved === null) throw new RuntimeException('Saved preset translation could not be loaded.');
            if ($ownsTransaction) $pdo->commit();
            return $saved;
        } catch (Throwable $e) {
            if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    function ct_delete_shortcode_preset_translation(
        PDO $pdo,
        int $presetId,
        string $locale,
        string $loadedSourceState,
        string $loadedTranslationState,
        int $actorId = 0
    ): bool {
        if ($presetId <= 0 || !in_array($locale, ct_enabled_locales($pdo), true)) {
            throw new InvalidArgumentException('Preset or locale is not available.');
        }
        if (preg_match('/\A[a-f0-9]{64}\z/', $loadedSourceState) !== 1
            || preg_match('/\A[a-f0-9]{64}\z/', $loadedTranslationState) !== 1) {
            throw new InvalidArgumentException('Editor lock state is invalid. Reload the editor.');
        }
        if (!ct_ensure_schema($pdo)) {
            throw new RuntimeException('Preset translation storage is unavailable. Check the server error log.');
        }
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) $pdo->beginTransaction();
        try {
            if ($actorId > 0 && !authorization_lock_actor_permissions($pdo, $actorId)) throw new RuntimeException('Authorization state is unavailable.');
            $presetStmt = $pdo->prepare("SELECT id, title, slug, status, meta, created_by FROM posts WHERE id = ? AND type = 'sc_preset' AND is_deleted = 0 LIMIT 1 FOR UPDATE");
            $presetStmt->execute([$presetId]);
            $preset = $presetStmt->fetch(PDO::FETCH_ASSOC);
            if (!$preset) throw new RuntimeException('Source preset no longer exists.');
            if ($actorId > 0 && (!authorization_lock_owner_contexts($pdo, [(int)$preset['created_by']])
                || !ct_user_can_workspace($pdo, $actorId)
                || !user_can($pdo, $actorId, 'core.shortcodes.update', ['owner_id' => (int)$preset['created_by']]))) {
                throw new RuntimeException('Shortcode translation permission denied.');
            }
            if (!hash_equals($loadedSourceState, ct_shortcode_preset_source_state_token($preset))) {
                throw new RuntimeException('The source preset changed after this translation was loaded. Reload before deleting.');
            }
            $currentStmt = $pdo->prepare('SELECT * FROM shortcode_preset_translations WHERE preset_id = ? AND locale = ? LIMIT 1 FOR UPDATE');
            $currentStmt->execute([$presetId, $locale]);
            $current = $currentStmt->fetch(PDO::FETCH_ASSOC) ?: null;
            if ($current === null) throw new RuntimeException('Preset translation no longer exists.');
            if (!hash_equals($loadedTranslationState, ct_shortcode_preset_translation_state_token($current))) {
                throw new RuntimeException('This preset translation was changed by another editor. Reload before deleting.');
            }
            $delete = $pdo->prepare('DELETE FROM shortcode_preset_translations WHERE preset_id = ? AND locale = ?');
            if (!$delete->execute([$presetId, $locale])) throw new RuntimeException('Preset translation delete failed.');
            if ($ownsTransaction) $pdo->commit();
            return true;
        } catch (Throwable $e) {
            if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    function ct_delete_shortcode_preset_translations(PDO $pdo, int $presetId): void {
        if ($presetId <= 0) return;
        if (!$pdo->inTransaction() && !ct_ensure_schema($pdo)) {
            throw new RuntimeException('Preset translation storage is unavailable. Check the server error log.');
        }
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('DELETE FROM shortcode_preset_translations WHERE preset_id = ?');
            if (!$stmt->execute([$presetId])) throw new RuntimeException('Preset translation cleanup failed.');
            if ($ownsTransaction) $pdo->commit();
        } catch (Throwable $e) {
            if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    function ct_shortcode_preset_orphan_count(PDO $pdo): int {
        if (!ct_ensure_schema($pdo)) return 0;
        try {
            $stmt = $pdo->query("SELECT COUNT(*) FROM shortcode_preset_translations spt WHERE NOT EXISTS (SELECT 1 FROM posts p WHERE p.id = spt.preset_id AND p.type = 'sc_preset' AND p.is_deleted = 0)");
            return (int)$stmt->fetchColumn();
        } catch (Throwable $e) {
            ct_report_schema_error($pdo, $e);
            return 0;
        }
    }

    function ct_repair_shortcode_preset_orphans(PDO $pdo): int {
        if (!ct_ensure_schema($pdo)) {
            throw new RuntimeException('Preset translation storage is unavailable. Check the server error log.');
        }
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) $pdo->beginTransaction();
        try {
            $lock = $pdo->query("SELECT spt.id FROM shortcode_preset_translations spt WHERE NOT EXISTS (SELECT 1 FROM posts p WHERE p.id = spt.preset_id AND p.type = 'sc_preset' AND p.is_deleted = 0) FOR UPDATE");
            $ids = array_map('intval', $lock->fetchAll(PDO::FETCH_COLUMN, 0));
            if ($ids === []) {
                if ($ownsTransaction) $pdo->commit();
                return 0;
            }
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $delete = $pdo->prepare("DELETE FROM shortcode_preset_translations WHERE id IN ({$placeholders})");
            if (!$delete->execute($ids) || $delete->rowCount() !== count($ids)) {
                throw new RuntimeException('Orphan preset translation cleanup failed.');
            }
            if ($ownsTransaction) $pdo->commit();
            return count($ids);
        } catch (Throwable $e) {
            if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    function ct_shortcode_preset_persisted_config(PDO $pdo, int $presetId): array {
        if ($presetId <= 0) return [];
        $stmt = $pdo->prepare("SELECT meta FROM posts WHERE id = ? AND type = 'sc_preset' AND is_deleted = 0 LIMIT 1");
        if (!$stmt->execute([$presetId])) throw new RuntimeException('Source preset state could not be verified.');
        $meta = $stmt->fetchColumn();
        if (!is_string($meta)) throw new RuntimeException('Source preset state could not be verified.');
        $config = json_decode($meta, true);
        if (!is_array($config)) throw new RuntimeException('Source preset configuration is invalid.');
        return $config;
    }

    function ct_shortcode_preset_kicker_mode(array $config): string {
        if (!array_key_exists('kicker', $config)) return 'automatic';
        $value = $config['kicker'];
        return (is_scalar($value) || $value === null) && trim((string)$value) === '' ? 'hidden' : 'custom';
    }

    function ct_shortcode_preset_ui_translations(): array {
        return [
            'Preset heading & translations' => ['Judul preset & terjemahan', 'Preset-Überschrift & Übersetzungen'],
            'Translations' => ['Terjemahan', 'Übersetzungen'],
            'Source heading behavior' => ['Perilaku judul sumber', 'Verhalten der Quellüberschrift'],
            'Automatic category heading' => ['Judul kategori otomatis', 'Automatische Kategorieüberschrift'],
            'Hidden' => ['Disembunyikan', 'Ausgeblendet'],
            'Custom heading' => ['Judul khusus', 'Benutzerdefinierte Überschrift'],
            'Core automatically uses the selected category name when available.' => ['Core otomatis menggunakan nama kategori yang dipilih jika tersedia.', 'Core verwendet automatisch den Namen der ausgewählten Kategorie, wenn verfügbar.'],
            'No heading is shown above the preset results.' => ['Tidak ada judul yang ditampilkan di atas hasil preset.', 'Über den Preset-Ergebnissen wird keine Überschrift angezeigt.'],
            'Custom source kicker / heading' => ['Kicker / judul sumber khusus', 'Benutzerdefinierter Quell-Kicker / Überschrift'],
            'Heading shown above preset results' => ['Judul yang ditampilkan di atas hasil preset', 'Überschrift über den Preset-Ergebnissen'],
            'Choose translation language…' => ['Pilih bahasa terjemahan…', 'Übersetzungssprache auswählen…'],
            'Open translation' => ['Buka terjemahan', 'Übersetzung öffnen'],
            'Save the preset before adding locale translations.' => ['Simpan preset sebelum menambahkan terjemahan bahasa.', 'Speichern Sie das Preset, bevor Sie Übersetzungen hinzufügen.'],
            'Invalid source heading behavior.' => ['Perilaku judul sumber tidak valid.', 'Ungültiges Verhalten der Quellüberschrift.'],
            'Custom source heading requires text.' => ['Judul sumber khusus memerlukan teks.', 'Eine benutzerdefinierte Quellüberschrift erfordert Text.'],
            'Preset kicker must be text.' => ['Kicker preset harus berupa teks.', 'Der Preset-Kicker muss Text sein.'],
            'Preset kicker must be valid UTF-8 text.' => ['Kicker preset harus berupa teks UTF-8 yang valid.', 'Der Preset-Kicker muss gültiger UTF-8-Text sein.'],
            'Preset kicker must not exceed 255 characters.' => ['Kicker preset tidak boleh melebihi 255 karakter.', 'Der Preset-Kicker darf 255 Zeichen nicht überschreiten.'],
            'Preset kicker contains unsupported control characters.' => ['Kicker preset berisi karakter kontrol yang tidak didukung.', 'Der Preset-Kicker enthält nicht unterstützte Steuerzeichen.'],
            'Shortcode Presets' => ['Preset Shortcode', 'Shortcode-Presets'],
            'Open a source preset, then choose a language beside its heading settings. Query and layout configuration stay shared.' => ['Buka preset sumber, lalu pilih bahasa di samping pengaturan judulnya. Konfigurasi kueri dan tata letak tetap digunakan bersama.', 'Öffnen Sie ein Quell-Preset und wählen Sie neben den Überschriftseinstellungen eine Sprache. Abfrage und Layout bleiben gemeinsam.'],
            'Open Shortcode Presets' => ['Buka Preset Shortcode', 'Shortcode-Presets öffnen'],
            'Shortcode Preset Translation Overview' => ['Ringkasan Terjemahan Preset Shortcode', 'Übersicht der Shortcode-Preset-Übersetzungen'],
            'This page is a status overview. The primary workflow starts by opening the source preset and choosing a translation language there.' => ['Halaman ini adalah ringkasan status. Alur utama dimulai dengan membuka preset sumber dan memilih bahasa terjemahan di sana.', 'Diese Seite ist eine Statusübersicht. Der primäre Ablauf beginnt beim Quell-Preset, wo die Übersetzungssprache gewählt wird.'],
            'Translations belong to each Theme Template that uses this renderer. PHP remains the shared source for every language.' => ['Terjemahan dimiliki oleh setiap Theme Template yang memakai renderer ini. PHP tetap menjadi sumber bersama untuk semua bahasa.', 'Übersetzungen gehören zu jeder Theme-Vorlage, die diesen Renderer verwendet. PHP bleibt die gemeinsame Quelle für alle Sprachen.'],
            'Theme Template usage could not be loaded.' => ['Pemakaian Theme Template tidak dapat dimuat.', 'Die Verwendung in Theme-Vorlagen konnte nicht geladen werden.'],
            'This renderer is not used by a package-composed Theme Template, so it has no translation target yet.' => ['Renderer ini belum dipakai oleh Theme Template berbasis paket, sehingga belum memiliki target terjemahan.', 'Dieser Renderer wird noch von keiner paketbasierten Theme-Vorlage verwendet und hat daher noch kein Übersetzungsziel.'],
            'Used by Theme Template' => ['Dipakai oleh Theme Template', 'Verwendet von Theme-Vorlage'],
            'Unavailable' => ['Tidak tersedia', 'Nicht verfügbar'],
            'Opened from the source renderer. The matching section is highlighted below:' => ['Dibuka dari renderer sumber. Section yang sesuai disorot di bawah:', 'Vom Quell-Renderer geöffnet. Der passende Abschnitt ist unten hervorgehoben:'],
            'Shortcodes' => ['Shortcode', 'Shortcodes'],
            'Translate Shortcode Presets' => ['Terjemahkan Preset Shortcode', 'Shortcode-Presets übersetzen'],
            'Edit Shortcode Preset Translation' => ['Edit Terjemahan Preset Shortcode', 'Shortcode-Preset-Übersetzung bearbeiten'],
            'Translate Shortcode Preset management titles and localized kicker text without changing query or layout configuration.' => ['Terjemahkan judul pengelolaan Preset Shortcode dan teks kicker lokal tanpa mengubah konfigurasi kueri atau tata letak.', 'Übersetzen Sie Verwaltungstitel und lokalisierte Kicker-Texte von Shortcode-Presets, ohne Abfrage oder Layout zu ändern.'],
            'Manage Shortcode Presets' => ['Kelola Preset Shortcode', 'Shortcode-Presets verwalten'],
            'Shortcode Preset Translation' => ['Terjemahan Preset Shortcode', 'Shortcode-Preset-Übersetzung'],
            'Translate preset management titles and the optional kicker shown above fetched content. Query and layout settings always remain source-controlled.' => ['Terjemahkan judul pengelolaan preset dan kicker opsional di atas konten. Pengaturan kueri dan tata letak tetap dikendalikan sumber.', 'Übersetzen Sie Verwaltungstitel und den optionalen Kicker. Abfrage und Layout bleiben quellenkontrolliert.'],
            'Translated management title' => ['Judul pengelolaan terjemahan', 'Übersetzter Verwaltungstitel'],
            'Used to identify this preset translation in administration; it does not replace fetched post or page titles.' => ['Digunakan untuk mengenali terjemahan preset di administrasi; tidak menggantikan judul pos atau halaman.', 'Kennzeichnet die Preset-Übersetzung in der Verwaltung; abgerufene Beitrags- oder Seitentitel werden nicht ersetzt.'],
            'Localized kicker / heading' => ['Kicker / judul lokal', 'Lokalisierter Kicker / Überschrift'],
            'Only this text key can be overlaid. Leaving it empty keeps the source preset heading behavior.' => ['Hanya teks ini yang dapat dilokalkan. Jika kosong, perilaku judul preset sumber dipertahankan.', 'Nur dieser Text kann überlagert werden. Leer bleibt das Verhalten der Quellüberschrift erhalten.'],
            'Source preset' => ['Preset sumber', 'Quell-Preset'],
            'Management title' => ['Judul pengelolaan', 'Verwaltungstitel'],
            'Source heading' => ['Judul sumber', 'Quellüberschrift'],
            'Source status' => ['Status sumber', 'Quellstatus'],
            'Locale translations' => ['Terjemahan bahasa', 'Sprachübersetzungen'],
            'Automatic' => ['Otomatis', 'Automatisch'],
            'Search preset title or slug…' => ['Cari judul atau slug preset…', 'Preset-Titel oder Slug suchen…'],
            'No Shortcode Presets found.' => ['Preset Shortcode tidak ditemukan.', 'Keine Shortcode-Presets gefunden.'],
            'Shortcode Preset pages' => ['Halaman Preset Shortcode', 'Shortcode-Preset-Seiten'],
            'Incomplete' => ['Belum lengkap', 'Unvollständig'],
            'The stored preset text override is invalid. Save a valid draft or delete this translation; invalid data is never applied at runtime.' => ['Penggantian teks preset tersimpan tidak valid. Simpan draf yang valid atau hapus terjemahan ini; data tidak valid tidak pernah diterapkan saat runtime.', 'Die gespeicherte Textüberlagerung ist ungültig. Speichern Sie einen gültigen Entwurf oder löschen Sie die Übersetzung; ungültige Daten werden nie angewendet.'],
            'This preset has no explicitly configured source kicker. Fetched Posts or Pages are translated through their existing Content Translation resources. You may still add a localized kicker when the localized presentation needs one.' => ['Preset ini tidak memiliki kicker sumber eksplisit. Pos atau Halaman diterjemahkan melalui sumber Terjemahan Konten yang ada. Kicker lokal tetap dapat ditambahkan bila diperlukan.', 'Dieses Preset hat keinen expliziten Quell-Kicker. Beiträge oder Seiten werden über ihre vorhandenen Inhaltsübersetzungen übersetzt. Bei Bedarf kann ein lokalisierter Kicker hinzugefügt werden.'],
            'Category, post type, author, limits, ordering, layout, wrapper, and all unknown extension configuration remain controlled by the source preset and cannot be translated here.' => ['Kategori, jenis pos, penulis, batas, urutan, tata letak, pembungkus, dan konfigurasi ekstensi tetap dikendalikan preset sumber.', 'Kategorie, Beitragstyp, Autor, Grenzen, Sortierung, Layout, Wrapper und Erweiterungskonfiguration bleiben vom Quell-Preset gesteuert.'],
            'Preset translation storage is unavailable. Source preset behavior is unchanged; check the server error log before retrying.' => ['Penyimpanan terjemahan preset tidak tersedia. Perilaku preset sumber tidak berubah; periksa log galat server sebelum mencoba lagi.', 'Der Speicher für Preset-Übersetzungen ist nicht verfügbar. Das Quellverhalten bleibt unverändert; prüfen Sie vor dem erneuten Versuch das Serverprotokoll.'],
            '%d orphan preset translation(s) were found.' => ['%d terjemahan preset yatim ditemukan.', '%d verwaiste Preset-Übersetzung(en) gefunden.'],
            'Remove orphan translations' => ['Hapus terjemahan yatim', 'Verwaiste Übersetzungen entfernen'],
            '%d orphan preset translation(s) removed.' => ['%d terjemahan preset yatim dihapus.', '%d verwaiste Preset-Übersetzung(en) entfernt.'],
            'Orphan preset translation cleanup failed. Check the server error log.' => ['Pembersihan terjemahan preset yatim gagal. Periksa log galat server.', 'Die Bereinigung verwaister Preset-Übersetzungen ist fehlgeschlagen. Prüfen Sie das Serverprotokoll.'],
            'Orphan cleanup failed.' => ['Pembersihan data yatim gagal.', 'Bereinigung verwaister Daten fehlgeschlagen.'],
            'Orphan translations removed.' => ['Terjemahan yatim dihapus.', 'Verwaiste Übersetzungen entfernt.'],
            'Invalid preset translation request.' => ['Permintaan terjemahan preset tidak valid.', 'Ungültige Preset-Übersetzungsanfrage.'],
            'Preset translation fields must be text.' => ['Kolom terjemahan preset harus berupa teks.', 'Preset-Übersetzungsfelder müssen Text sein.'],
            'Translation text must be valid UTF-8.' => ['Teks terjemahan harus berupa UTF-8 yang valid.', 'Der Übersetzungstext muss gültiges UTF-8 sein.'],
            'Translated management title must not exceed 191 characters.' => ['Judul pengelolaan terjemahan tidak boleh melebihi 191 karakter.', 'Der übersetzte Verwaltungstitel darf 191 Zeichen nicht überschreiten.'],
            'Localized kicker must not exceed 255 characters.' => ['Kicker lokal tidak boleh melebihi 255 karakter.', 'Der lokalisierte Kicker darf 255 Zeichen nicht überschreiten.'],
            'Translation text contains unsupported control characters.' => ['Teks terjemahan berisi karakter kontrol yang tidak didukung.', 'Der Übersetzungstext enthält nicht unterstützte Steuerzeichen.'],
            'Invalid translation status.' => ['Status terjemahan tidak valid.', 'Ungültiger Übersetzungsstatus.'],
            'Published preset translations require a management title.' => ['Terjemahan preset yang diterbitkan memerlukan judul pengelolaan.', 'Veröffentlichte Preset-Übersetzungen benötigen einen Verwaltungstitel.'],
            'Preset or locale is not available.' => ['Preset atau bahasa tidak tersedia.', 'Preset oder Sprache ist nicht verfügbar.'],
            'Editor lock state is invalid. Reload the editor.' => ['Status kunci editor tidak valid. Muat ulang editor.', 'Der Sperrstatus des Editors ist ungültig. Laden Sie den Editor neu.'],
            'Preset translation storage is unavailable. Check the server error log.' => ['Penyimpanan terjemahan preset tidak tersedia. Periksa log galat server.', 'Der Speicher für Preset-Übersetzungen ist nicht verfügbar. Prüfen Sie das Serverprotokoll.'],
            'Authorization state is unavailable.' => ['Status otorisasi tidak tersedia.', 'Der Autorisierungsstatus ist nicht verfügbar.'],
            'Shortcode translation permission denied.' => ['Izin terjemahan shortcode ditolak.', 'Die Berechtigung zur Shortcode-Übersetzung wurde verweigert.'],
            'Source preset no longer exists.' => ['Preset sumber sudah tidak ada.', 'Das Quell-Preset ist nicht mehr vorhanden.'],
            'The source preset changed after this translation was loaded. Reload before saving.' => ['Preset sumber berubah setelah terjemahan dimuat. Muat ulang sebelum menyimpan.', 'Das Quell-Preset wurde nach dem Laden geändert. Laden Sie vor dem Speichern neu.'],
            'The source preset changed after this translation was loaded. Reload before deleting.' => ['Preset sumber berubah setelah terjemahan dimuat. Muat ulang sebelum menghapus.', 'Das Quell-Preset wurde nach dem Laden geändert. Laden Sie vor dem Löschen neu.'],
            'This preset translation was changed by another editor. Reload before saving.' => ['Terjemahan preset ini diubah oleh editor lain. Muat ulang sebelum menyimpan.', 'Diese Preset-Übersetzung wurde von einem anderen Bearbeiter geändert. Laden Sie vor dem Speichern neu.'],
            'This translation was changed by another editor. Reload before saving.' => ['Terjemahan ini diubah oleh editor lain. Muat ulang sebelum menyimpan.', 'Diese Übersetzung wurde von einem anderen Bearbeiter geändert. Laden Sie vor dem Speichern neu.'],
            'This preset translation was changed by another editor. Reload before deleting.' => ['Terjemahan preset ini diubah oleh editor lain. Muat ulang sebelum menghapus.', 'Diese Preset-Übersetzung wurde von einem anderen Bearbeiter geändert. Laden Sie vor dem Löschen neu.'],
            'Preset translation no longer exists.' => ['Terjemahan preset sudah tidak ada.', 'Die Preset-Übersetzung ist nicht mehr vorhanden.'],
            'Preset translation save failed.' => ['Penyimpanan terjemahan preset gagal.', 'Das Speichern der Preset-Übersetzung ist fehlgeschlagen.'],
            'Saved preset translation could not be loaded.' => ['Terjemahan preset yang disimpan tidak dapat dimuat.', 'Die gespeicherte Preset-Übersetzung konnte nicht geladen werden.'],
            'Preset translation delete failed.' => ['Penghapusan terjemahan preset gagal.', 'Das Löschen der Preset-Übersetzung ist fehlgeschlagen.'],
            'Preset translation cleanup failed.' => ['Pembersihan terjemahan preset gagal.', 'Die Bereinigung der Preset-Übersetzung ist fehlgeschlagen.'],
            'Orphan preset translation cleanup failed.' => ['Pembersihan terjemahan preset yatim gagal.', 'Die Bereinigung verwaister Preset-Übersetzungen ist fehlgeschlagen.'],
            'Preset UI translation storage is unavailable.' => ['Penyimpanan terjemahan UI preset tidak tersedia.', 'Der Speicher für Preset-UI-Übersetzungen ist nicht verfügbar.'],
            'Source preset state could not be verified.' => ['Status preset sumber tidak dapat diverifikasi.', 'Der Zustand des Quell-Presets konnte nicht verifiziert werden.'],
            'Source preset configuration is invalid.' => ['Konfigurasi preset sumber tidak valid.', 'Die Konfiguration des Quell-Presets ist ungültig.'],
            'Database not available.' => ['Basis data tidak tersedia.', 'Datenbank nicht verfügbar.'],
            'Admin role required.' => ['Peran admin diperlukan.', 'Administratorrolle erforderlich.'],
            'Access denied.' => ['Akses ditolak.', 'Zugriff verweigert.'],
            'Back' => ['Kembali', 'Zurück'],
            'Shortcode Preset not found.' => ['Preset Shortcode tidak ditemukan.', 'Shortcode-Preset nicht gefunden.'],
            'Translation' => ['Terjemahan', 'Übersetzung'],
            'Status' => ['Status', 'Status'],
            'Draft' => ['Draf', 'Entwurf'],
            'Published' => ['Diterbitkan', 'Veröffentlicht'],
            'Edit' => ['Edit', 'Bearbeiten'],
            'Add' => ['Tambah', 'Hinzufügen'],
            'Save Translation' => ['Simpan Terjemahan', 'Übersetzung speichern'],
            'Delete Translation' => ['Hapus Terjemahan', 'Übersetzung löschen'],
            'Save failed.' => ['Penyimpanan gagal.', 'Speichern fehlgeschlagen.'],
            'Translation saved.' => ['Terjemahan disimpan.', 'Übersetzung gespeichert.'],
            'Network error.' => ['Galat jaringan.', 'Netzwerkfehler.'],
            'Delete this translation? This cannot be undone.' => ['Hapus terjemahan ini? Tindakan ini tidak dapat dibatalkan.', 'Diese Übersetzung löschen? Dies kann nicht rückgängig gemacht werden.'],
            'Delete translation' => ['Hapus terjemahan', 'Übersetzung löschen'],
            'Delete' => ['Hapus', 'Löschen'],
            'Cancel' => ['Batal', 'Abbrechen'],
            'Confirmation dialog is unavailable. Translation was not deleted.' => ['Dialog konfirmasi tidak tersedia. Terjemahan tidak dihapus.', 'Der Bestätigungsdialog ist nicht verfügbar. Die Übersetzung wurde nicht gelöscht.'],
            'Delete failed.' => ['Penghapusan gagal.', 'Löschen fehlgeschlagen.'],
            'Translation deleted.' => ['Terjemahan dihapus.', 'Übersetzung gelöscht.'],
            'No translation locales enabled.' => ['Tidak ada bahasa terjemahan yang diaktifkan.', 'Keine Übersetzungssprachen aktiviert.'],
            'Configure locales' => ['Konfigurasi bahasa', 'Sprachen konfigurieren'],
            'Filter' => ['Filter', 'Filtern'],
            'Reset' => ['Atur ulang', 'Zurücksetzen'],
            'Preset' => ['Preset', 'Preset'],
            'Previous' => ['Sebelumnya', 'Zurück'],
            'Page %d of %d' => ['Halaman %d dari %d', 'Seite %d von %d'],
            'Next' => ['Berikutnya', 'Weiter'],
            'POST required' => ['POST diperlukan', 'POST erforderlich'],
            'Database not available' => ['Basis data tidak tersedia', 'Datenbank nicht verfügbar'],
            'Admin role required' => ['Peran admin diperlukan', 'Administratorrolle erforderlich'],
            'Access denied' => ['Akses ditolak', 'Zugriff verweigert'],
            'Shortcode Preset not found' => ['Preset Shortcode tidak ditemukan', 'Shortcode-Preset nicht gefunden'],
            'Invalid CSRF token' => ['Token CSRF tidak valid', 'Ungültiges CSRF-Token'],
            'Localized media' => ['Media lokal', 'Lokalisierte Medien'],
            'Original metadata language' => ['Bahasa metadata asli', 'Sprache der Originalmetadaten'],
            'Availability' => ['Ketersediaan', 'Verfügbarkeit'],
            'All locales' => ['Semua bahasa', 'Alle Sprachen'],
            'Selected locales' => ['Bahasa terpilih', 'Ausgewählte Sprachen'],
            'Choose which site languages may use this asset. Selecting a language automatically switches availability to selected locales.' => ['Pilih bahasa situs yang dapat menggunakan aset ini. Memilih bahasa akan otomatis mengubah ketersediaan menjadi bahasa terpilih.', 'Wählen Sie aus, welche Website-Sprachen dieses Asset verwenden dürfen. Die Auswahl einer Sprache wechselt die Verfügbarkeit automatisch auf ausgewählte Sprachen.'],
            'Metadata language' => ['Bahasa metadata', 'Metadatensprache'],
            'The fields below show the selected language. Save before editing another translation.' => ['Bidang di bawah menampilkan bahasa terpilih. Simpan sebelum mengedit terjemahan lain.', 'Die folgenden Felder zeigen die ausgewählte Sprache. Speichern Sie, bevor Sie eine andere Übersetzung bearbeiten.'],
            'Use translated title' => ['Gunakan judul terjemahan', 'Übersetzten Titel verwenden'],
            'Use translated caption' => ['Gunakan keterangan terjemahan', 'Übersetzte Bildunterschrift verwenden'],
            'Use translated credit' => ['Gunakan kredit terjemahan', 'Übersetzte Quellenangabe verwenden'],
            'Caption' => ['Keterangan', 'Bildunterschrift'],
            'Credit' => ['Kredit', 'Quellenangabe'],
            'Alt text mode' => ['Mode teks alt', 'Alternativtext-Modus'],
            'Inherit original' => ['Warisi teks asli', 'Original übernehmen'],
            'Translated text' => ['Teks terjemahan', 'Übersetzter Text'],
            'Decorative (empty alt)' => ['Dekoratif (alt kosong)', 'Dekorativ (leerer Alternativtext)'],
            'Translation status' => ['Status terjemahan', 'Übersetzungsstatus'],
            'Delete selected translation' => ['Hapus terjemahan terpilih', 'Ausgewählte Übersetzung löschen'],
            'This affects only the selected translation; original metadata is retained.' => ['Ini hanya memengaruhi terjemahan terpilih; metadata asli tetap dipertahankan.', 'Dies betrifft nur die ausgewählte Übersetzung; die Originalmetadaten bleiben erhalten.'],
            'This media translation will be deleted when you save.' => ['Terjemahan media ini akan dihapus saat Anda menyimpan.', 'Diese Medienübersetzung wird beim Speichern gelöscht.'],
            'Localized featured media' => ['Media unggulan lokal', 'Lokalisiertes Beitragsbild'],
            'YouTube remains the first display-image source. This selection is used when no valid YouTube thumbnail is present.' => ['YouTube tetap menjadi sumber gambar tampilan pertama. Pilihan ini digunakan saat gambar mini YouTube yang valid tidak tersedia.', 'YouTube bleibt die erste Quelle für das Anzeigebild. Diese Auswahl wird verwendet, wenn kein gültiges YouTube-Vorschaubild vorhanden ist.'],
            'Inherit source thumbnail' => ['Warisi gambar mini sumber', 'Quell-Vorschaubild übernehmen'],
            'Choose media for this locale' => ['Pilih media untuk bahasa ini', 'Medien für diese Sprache auswählen'],
            'No thumbnail' => ['Tanpa gambar mini', 'Kein Vorschaubild'],
            'Open media picker' => ['Buka pemilih media', 'Medienauswahl öffnen'],
            'This media is unavailable, private, or deleted for the selected locale. It may remain in a draft but cannot be published.' => ['Media ini tidak tersedia, bersifat privat, atau dihapus untuk bahasa terpilih. Media dapat tetap berada dalam draf tetapi tidak dapat diterbitkan.', 'Dieses Medium ist für die ausgewählte Sprache nicht verfügbar, privat oder gelöscht. Es kann im Entwurf bleiben, aber nicht veröffentlicht werden.'],
            'Override alt text at this use site' => ['Ganti teks alt pada penggunaan ini', 'Alternativtext an dieser Verwendungsstelle überschreiben'],
            'Override caption at this use site' => ['Ganti keterangan pada penggunaan ini', 'Bildunterschrift an dieser Verwendungsstelle überschreiben'],
            'This media is unavailable, private, or deleted for the selected locale. Save as draft or choose compatible media.' => ['Media ini tidak tersedia, bersifat privat, atau dihapus untuk bahasa terpilih. Simpan sebagai draf atau pilih media yang kompatibel.', 'Dieses Medium ist für die ausgewählte Sprache nicht verfügbar, privat oder gelöscht. Als Entwurf speichern oder ein kompatibles Medium auswählen.'],
            'Content Translation cannot be disabled or deleted while localized media state exists.' => ['Terjemahan Konten tidak dapat dinonaktifkan atau dihapus selama data media lokal masih ada.', 'Content Translation kann nicht deaktiviert oder gelöscht werden, solange lokalisierte Mediendaten vorhanden sind.'],
            'Content Translation state could not be verified.' => ['Status Terjemahan Konten tidak dapat diverifikasi.', 'Der Zustand von Content Translation konnte nicht überprüft werden.'],
            'Content default language cannot change while localized media state exists.' => ['Bahasa bawaan konten tidak dapat diubah selama data media lokal masih ada.', 'Die Standardsprache für Inhalte kann nicht geändert werden, solange lokalisierte Mediendaten vorhanden sind.'],
            'Content default language cannot change because localized media state could not be verified.' => ['Bahasa bawaan konten tidak dapat diubah karena status media lokal tidak dapat diverifikasi.', 'Die Standardsprache für Inhalte kann nicht geändert werden, da der lokalisierte Medienzustand nicht überprüft werden konnte.'],
            'Invalid localized featured media mode.' => ['Mode media unggulan lokal tidak valid.', 'Ungültiger Modus für lokalisierte Beitragsmedien.'],
            'Choose media for the localized thumbnail.' => ['Pilih media untuk gambar mini lokal.', 'Wählen Sie ein Medium für das lokalisierte Vorschaubild.'],
            'Localized media overrides are too long.' => ['Penggantian media lokal terlalu panjang.', 'Lokalisierte Medienüberschreibungen sind zu lang.'],
            'Published translations require available public media.' => ['Terjemahan yang diterbitkan memerlukan media publik yang tersedia.', 'Veröffentlichte Übersetzungen benötigen verfügbare öffentliche Medien.'],
            'Choose a valid source metadata language.' => ['Pilih bahasa metadata sumber yang valid.', 'Wählen Sie eine gültige Sprache für die Quellmetadaten.'],
            'Selected availability requires at least one locale.' => ['Ketersediaan terpilih memerlukan setidaknya satu bahasa.', 'Die ausgewählte Verfügbarkeit erfordert mindestens eine Sprache.'],
            'Localized media profile is unavailable.' => ['Profil media lokal tidak tersedia.', 'Das lokalisierte Medienprofil ist nicht verfügbar.'],
            'This media profile was changed by another editor. Reload before saving.' => ['Profil media ini diubah oleh editor lain. Muat ulang sebelum menyimpan.', 'Dieses Medienprofil wurde von einem anderen Bearbeiter geändert. Laden Sie vor dem Speichern neu.'],
            'Choose a valid alternate metadata language.' => ['Pilih bahasa metadata alternatif yang valid.', 'Wählen Sie eine gültige alternative Metadatensprache.'],
            'Invalid media translation state.' => ['Status terjemahan media tidak valid.', 'Ungültiger Zustand der Medienübersetzung.'],
            'Invalid media translation operation.' => ['Operasi terjemahan media tidak valid.', 'Ungültige Medienübersetzungsoperation.'],
            'This media translation was changed by another editor. Reload before saving.' => ['Terjemahan media ini diubah oleh editor lain. Muat ulang sebelum menyimpan.', 'Diese Medienübersetzung wurde von einem anderen Bearbeiter geändert. Laden Sie vor dem Speichern neu.'],
            'Translated alt text cannot be empty; use decorative for an intentional empty alt.' => ['Teks alt terjemahan tidak boleh kosong; gunakan dekoratif untuk alt kosong yang disengaja.', 'Übersetzter Alternativtext darf nicht leer sein; verwenden Sie dekorativ für einen beabsichtigten leeren Alternativtext.'],
            'The new source metadata language already has a media translation. Delete that translation first.' => ['Bahasa metadata sumber baru sudah memiliki terjemahan media. Hapus terjemahan tersebut terlebih dahulu.', 'Für die neue Sprache der Quellmetadaten existiert bereits eine Medienübersetzung. Löschen Sie diese zuerst.'],
        ];
    }

    function ct_seed_shortcode_preset_ui_translations(PDO $pdo): void {
        $translations = ct_shortcode_preset_ui_translations();
        $seedHash = hash('sha256', json_encode($translations, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        if (function_exists('settings_get')
            && hash_equals($seedHash, (string)settings_get($pdo, 'content_translation_shortcode_ui_seed', ''))) {
            return;
        }
        try {
            if (!ct_ensure_schema($pdo)) throw new RuntimeException('Preset UI translation storage is unavailable.');
            $ownsTransaction = !$pdo->inTransaction();
            if ($ownsTransaction) $pdo->beginTransaction();
            $owned = $pdo->prepare('SELECT value FROM ct_ui_translation_seeds WHERE scope = ? AND source_hash = ? AND locale = ? AND source = ? LIMIT 1 FOR UPDATE');
            $insertUi = $pdo->prepare('INSERT IGNORE INTO ui_translations (scope, source, value, locale) VALUES (?, ?, ?, ?)');
            $updateUi = $pdo->prepare('UPDATE ui_translations SET value = ? WHERE scope = ? AND source = ? AND locale = ? AND value = ?');
            $saveOwned = $pdo->prepare('INSERT INTO ct_ui_translation_seeds (scope, source_hash, source, locale, value) VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE source = VALUES(source), value = VALUES(value)');
            $desired = [];
            foreach ($translations as $source => [$id, $de]) {
                foreach (['id' => $id, 'de' => $de] as $locale => $value) {
                    $sourceHash = hash('sha256', $source);
                    $desired[$sourceHash . "\0" . $locale] = true;
                    $owned->execute(['default', $sourceHash, $locale, $source]);
                    $previous = $owned->fetchColumn();
                    $insertUi->execute(['default', $source, $value, $locale]);
                    $inserted = $insertUi->rowCount() > 0;
                    if ($previous !== false) {
                        $updateUi->execute([$value, 'default', $source, $locale, (string)$previous]);
                    }
                    if ($inserted || $previous !== false) {
                        $saveOwned->execute(['default', $sourceHash, $source, $locale, $value]);
                    }
                }
            }
            $obsolete = $pdo->prepare('SELECT source_hash, source, locale, value FROM ct_ui_translation_seeds WHERE scope = ? FOR UPDATE');
            $obsolete->execute(['default']);
            $deleteUi = $pdo->prepare('DELETE FROM ui_translations WHERE scope = ? AND source = ? AND locale = ? AND value = ?');
            $deleteOwned = $pdo->prepare('DELETE FROM ct_ui_translation_seeds WHERE scope = ? AND source_hash = ? AND locale = ?');
            foreach ($obsolete->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $key = (string)$row['source_hash'] . "\0" . (string)$row['locale'];
                if (isset($desired[$key])) continue;
                $deleteUi->execute(['default', (string)$row['source'], (string)$row['locale'], (string)$row['value']]);
                $deleteOwned->execute(['default', (string)$row['source_hash'], (string)$row['locale']]);
            }
            if ($ownsTransaction) $pdo->commit();
            if (function_exists('settings_set')) settings_set($pdo, 'content_translation_shortcode_ui_seed', $seedHash);
        } catch (Throwable $e) {
            if (isset($ownsTransaction) && $ownsTransaction && $pdo->inTransaction()) $pdo->rollBack();
            error_log('[content-translation] UI translation seed error: ' . $e->getMessage());
        }
    }
}

add_action('shortcode_preset_editor_fields', function ($config, $preset, $pdo, $context): void {
    if (!$pdo instanceof PDO || !is_array($config) || !is_array($context) || empty($context['is_admin'])) return;
    $ownerId = is_array($preset) ? (int)($preset['created_by'] ?? 0) : 0;
    if (!ct_user_can_workspace($pdo)
        || !user_can($pdo, ct_current_user_id(), 'core.shortcodes.update', ['owner_id' => $ownerId])) return;
    $base = defined('ADMIN_BASE_PATH') ? ADMIN_BASE_PATH : '/adiwira';
    $presetId = is_array($preset) ? (int)($preset['id'] ?? 0) : 0;
    $persistedConfig = [];
    if ($presetId > 0 && is_array($preset)) {
        $decoded = json_decode((string)($preset['meta'] ?? ''), true);
        if (is_array($decoded)) $persistedConfig = $decoded;
    }
    $kickerMode = ct_shortcode_preset_kicker_mode($persistedConfig);
    $kicker = $kickerMode === 'custom' && is_scalar($persistedConfig['kicker'] ?? null) ? (string)$persistedConfig['kicker'] : '';
    echo '<section class="adam-card ct-preset-core-fields" style="margin-top:.75rem">';
    echo '<h3 style="margin-top:0">' . htmlspecialchars(__('Preset heading & translations'), ENT_QUOTES, 'UTF-8') . '</h3>';
    echo '<label for="ct-preset-kicker-mode">' . htmlspecialchars(__('Source heading behavior'), ENT_QUOTES, 'UTF-8') . '</label><br>';
    echo '<select name="preset_config[_ct_kicker_mode]" id="ct-preset-kicker-mode" class="inpud" style="width:auto">';
    foreach (['automatic' => __('Automatic category heading'), 'hidden' => __('Hidden'), 'custom' => __('Custom heading')] as $mode => $label) {
        echo '<option value="' . $mode . '"' . ($kickerMode === $mode ? ' selected' : '') . '>' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</option>';
    }
    echo '</select>';
    echo '<small class="muted ct-preset-kicker-help" data-mode="automatic">' . htmlspecialchars(__('Core automatically uses the selected category name when available.'), ENT_QUOTES, 'UTF-8') . '</small>';
    echo '<small class="muted ct-preset-kicker-help" data-mode="hidden">' . htmlspecialchars(__('No heading is shown above the preset results.'), ENT_QUOTES, 'UTF-8') . '</small>';
    echo '<label id="ct-preset-custom-kicker-wrap" style="display:block;margin-top:.6rem">' . htmlspecialchars(__('Custom source kicker / heading'), ENT_QUOTES, 'UTF-8') . '<br>';
    echo '<input type="text" name="preset_config[kicker]" id="ct-preset-source-kicker" maxlength="255" class="inpud" value="' . htmlspecialchars($kicker, ENT_QUOTES, 'UTF-8') . '" placeholder="' . htmlspecialchars(__('Heading shown above preset results'), ENT_QUOTES, 'UTF-8') . '"></label>';
    if ($presetId > 0) {
        $translations = ct_shortcode_preset_translations($pdo, $presetId);
        $locales = ct_enabled_locales($pdo);
        $sourceEditorUrl = $base . '/?page=admin/shortcodes/edit&id=' . $presetId;
        echo '<div class="ct-editor-translation-control" style="max-width:none;margin-bottom:0"><label for="ct-preset-translation-locale">' . htmlspecialchars(__('Translations'), ENT_QUOTES, 'UTF-8') . '</label>';
        echo '<div class="ct-preset-picker"><select id="ct-preset-translation-locale"><option value="">' . htmlspecialchars(__('Choose translation language…'), ENT_QUOTES, 'UTF-8') . '</option>';
        foreach ($locales as $locale) {
            $translation = $translations[$locale] ?? null;
            $status = (string)($translation['status'] ?? '');
            $action = $translation ? ($status === 'draft' ? __('Draft') : __('Edit')) : __('Add');
            $title = trim((string)($translation['title'] ?? ''));
            $url = $base . '/?page=admin/tools/content-translation/shortcode-edit&preset_id=' . $presetId . '&locale=' . rawurlencode($locale) . '&return_to=' . rawurlencode($sourceEditorUrl);
            $label = strtoupper($locale) . ' - ' . ($title !== '' ? $title . ' - ' : '') . $action;
            echo '<option value="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</option>';
        }
        echo '</select><a id="ct-preset-translation-open" class="btn" href="#" aria-disabled="true">' . htmlspecialchars(__('Open translation'), ENT_QUOTES, 'UTF-8') . '</a></div></div>';
        echo '<script>(function(){var s=document.getElementById("ct-preset-translation-locale"),b=document.getElementById("ct-preset-translation-open");if(!s||!b)return;function u(){b.href=s.value||"#";b.setAttribute("aria-disabled",s.value?"false":"true")}s.addEventListener("change",u);b.addEventListener("click",function(e){if(!s.value)e.preventDefault()});u()})()</script>';
    } else {
        echo '<p class="muted">' . htmlspecialchars(__('Save the preset before adding locale translations.'), ENT_QUOTES, 'UTF-8') . '</p>';
    }
    echo '</section>';
    echo '<script>(function(){var mode=document.getElementById("ct-preset-kicker-mode"),field=document.getElementById("ct-preset-source-kicker"),wrap=document.getElementById("ct-preset-custom-kicker-wrap");if(!mode||!field||!wrap)return;function update(){var custom=mode.value==="custom";wrap.hidden=!custom;field.disabled=!custom;field.required=custom;document.querySelectorAll(".ct-preset-kicker-help").forEach(function(help){help.hidden=help.dataset.mode!==mode.value})}mode.addEventListener("change",update);update();if(window.__ctPresetPreviewConfig)return;window.__ctPresetPreviewConfig=true;document.addEventListener("shortcode-preset-preview-config",function(event){var detail=event.detail||{},config=detail.config;if(!config)return;if(mode.value==="automatic")delete config.kicker;else if(mode.value==="hidden")config.kicker="";else config.kicker=field.value.trim()})})()</script>';
}, 20, 4);

add_filter('shortcode_preset_config_before_save', function ($config, $context, $pdo) {
    if (!is_array($config)) return $config;
    $modeInput = $config['_ct_kicker_mode'] ?? null;
    unset($config['_ct_kicker_mode']);
    if (!is_array($context) || !$pdo instanceof PDO) return $config;
    $canTranslate = ct_user_can_workspace($pdo)
        && user_can($pdo, ct_current_user_id(), 'core.shortcodes.update', ['owner_id' => (int)($context['created_by'] ?? 0)]);
    if (!$canTranslate || $modeInput === null) {
        try {
            $persisted = ct_shortcode_preset_persisted_config($pdo, (int)($context['id'] ?? 0));
        } catch (Throwable $e) {
            error_log('[content-translation] source kicker verification error: ' . $e->getMessage());
            $config['kicker'] = [];
            return $config;
        }
        if (array_key_exists('kicker', $persisted)) $config['kicker'] = $persisted['kicker'];
        else unset($config['kicker']);
        return $config;
    }
    if (!is_scalar($modeInput) || !in_array((string)$modeInput, ['automatic', 'hidden', 'custom'], true)) {
        $config['_ct_kicker_error'] = 'Invalid source heading behavior.';
        return $config;
    }
    $mode = (string)$modeInput;
    if ($mode === 'automatic') {
        unset($config['kicker']);
        return $config;
    }
    if ($mode === 'hidden') {
        $config['kicker'] = '';
        return $config;
    }
    if (!array_key_exists('kicker', $config) || !is_scalar($config['kicker']) || trim((string)$config['kicker']) === '') {
        $config['_ct_kicker_error'] = 'Custom source heading requires text.';
        return $config;
    }
    $config['kicker'] = trim((string)$config['kicker']);
    return $config;
}, 20, 3);

add_filter('shortcode_preset_validation_errors', function ($errors, $config) {
    if (!is_array($errors)) $errors = [];
    if (is_array($config) && isset($config['_ct_kicker_error']) && is_string($config['_ct_kicker_error'])) {
        $errors[] = __($config['_ct_kicker_error']);
    }
    if (!is_array($config) || !array_key_exists('kicker', $config)) return $errors;
    if (!is_scalar($config['kicker'])) {
        $errors[] = __('Preset kicker must be text.');
        return $errors;
    }
    $kicker = (string)$config['kicker'];
    $validUtf8 = preg_match('//u', $kicker) === 1;
    if (!$validUtf8) $errors[] = __('Preset kicker must be valid UTF-8 text.');
    elseif (ct_shortcode_preset_text_length($kicker) > 255) $errors[] = __('Preset kicker must not exceed 255 characters.');
    if (preg_match('/[\x00-\x1F\x7F]/', $kicker) === 1) $errors[] = __('Preset kicker contains unsupported control characters.');
    return $errors;
}, 20, 2);

add_filter('shortcode_preset_preview_config', function ($config, $context, $pdo) {
    return $config;
}, 20, 3);

add_filter('shortcode_preset_runtime_config', function ($config, $preset, $pdo, $context = []) {
    if (!is_array($config) || !is_array($preset) || !$pdo instanceof PDO) return $config;
    $locale = $GLOBALS['ct_request_locale'] ?? null;
    if (!is_string($locale) || $locale === '') return $config;
    $published = ct_published_shortcode_preset_overrides_for_locale($pdo, $locale);
    $overrides = $published[(int)($preset['id'] ?? 0)] ?? null;
    if (!is_array($overrides)) return $config;
    $kicker = trim((string)($overrides['kicker'] ?? ''));
    if ($kicker !== '') $config['kicker'] = $kicker;
    return $config;
}, 20, 4);

add_action('admin_shortcode_preset_before_delete', function ($presetId, $pdo): void {
    if (!$pdo instanceof PDO) return;
    ct_delete_shortcode_preset_translations($pdo, (int)$presetId);
}, 10, 2);
