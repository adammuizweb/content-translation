<?php
declare(strict_types=1);

// Content Translation — admin hooks

function ct_core_theme_action_context(array $themeRow, array $context, PDO $pdo): ?array {
    $actorId = filter_var($context['actor_id'] ?? $context['user_id'] ?? null, FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1],
    ]);
    if ($actorId === false || !ct_user_can_integration($pdo, 'core.themes.manage', $actorId)
        || !ct_user_is_site_owner($pdo, $actorId)) return null;

    $folder = is_string($context['folder'] ?? null) ? trim($context['folder']) : '';
    $rowFolder = is_string($themeRow['folder_name'] ?? null) ? trim($themeRow['folder_name']) : '';
    if ($folder === '' || strlen($folder) > 100
        || preg_match('/\A[A-Za-z0-9_-][A-Za-z0-9._-]*\z/D', $folder) !== 1
        || ($rowFolder !== '' && !hash_equals($rowFolder, $folder))) return null;

    $base = is_string($context['admin_base_path'] ?? null) ? rtrim($context['admin_base_path'], '/') : '';
    if ($base === '' || !str_starts_with($base, '/') || str_starts_with($base, '//')
        || str_contains($base, '?') || str_contains($base, '#')) return null;
    return ['actor_id' => $actorId, 'folder' => $folder, 'base' => $base];
}

function ct_core_theme_url(string $base, array $query): string {
    return $base . '/?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
}

function ct_core_theme_action(string $class, string $url, string $label): string {
    return '<a class="' . htmlspecialchars($class, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
        . '" href="' . htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">'
        . htmlspecialchars(__($label), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</a>';
}

function ct_admin_translation_status_label(string $status): string {
    return match ($status) {
        'published' => __('Published'),
        'draft' => __('Draft'),
        'incomplete' => __('Incomplete'),
        'stale' => __('Stale source'),
        default => __('Add'),
    };
}

function ct_theme_source_locale_chips_html(string $sourceLocale, array $locales, array $statuses, callable $urlForLocale): string {
    $html = '<div class="ct-locale-chips"><span class="ct-locale-chip ct-locale-chip--source"><b>'
        . htmlspecialchars(strtoupper($sourceLocale), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</b><small>'
        . htmlspecialchars(__('Source'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</small></span>';
    foreach (array_slice($locales, 0, 20) as $locale) {
        if (!is_string($locale) || $locale === '') continue;
        $status = is_string($statuses[$locale] ?? null) ? $statuses[$locale] : 'empty';
        $url = $urlForLocale($locale);
        if (!is_string($url) || $url === '') continue;
        $html .= '<a class="ct-locale-chip ct-locale-chip--' . htmlspecialchars($status, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
            . '" href="' . htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"><b>'
            . htmlspecialchars(strtoupper($locale), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</b><small>'
            . htmlspecialchars(ct_admin_translation_status_label($status), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</small></a>';
    }
    return $html . '</div>';
}

function ct_theme_zone_translation_actions_html(array $item, array $context, PDO $pdo): string {
    if (!ct_user_can_workspace($pdo) || !ct_user_is_site_owner($pdo)
        || !user_can($pdo, ct_current_user_id(), 'core.themes.manage')) return '';
    $resource = ct_theme_zone_resource_from_row($item);
    $contextFolder = is_string($context['theme_folder'] ?? null) ? $context['theme_folder'] : '';
    $contextZone = is_string($context['zone_slug'] ?? null) ? $context['zone_slug'] : '';
    $contextPosition = is_string($context['position'] ?? null) ? $context['position'] : '';
    if (!$resource || empty($item['active'])
        || !hash_equals((string)$resource['theme_folder'], $contextFolder)
        || !hash_equals((string)$resource['zone_slug'], $contextZone)
        || !hash_equals((string)$resource['position'], $contextPosition)
        || array_filter($resource['source_values'], static fn(string $value): bool => trim($value) !== '') === []) return '';
    $locales = array_slice(ct_enabled_locales($pdo), 0, 20);
    if ($locales === []) return '';
    $statuses = ct_theme_zone_translation_statuses($pdo, (int)$resource['id'], $resource);
    $base = defined('ADMIN_BASE_PATH') ? ADMIN_BASE_PATH : '/adiwira';
    $fallback = $base . '/?page=admin/themes/customize';
    $returnUrl = function_exists('adiwira_safe_return_to')
        ? adiwira_safe_return_to($context['return_url'] ?? null, $fallback)
        : $fallback;
    $sourceLocale = function_exists('content_default_locale') ? content_default_locale() : 'en';
    $html = '<div class="ct-theme-zone-summary"><span class="ct-theme-zone-summary__label">'
        . htmlspecialchars(__('Frontend text'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</span>'
        . '<span class="ct-locale-chip ct-locale-chip--source"><b>'
        . htmlspecialchars(strtoupper($sourceLocale), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</b><small>'
        . htmlspecialchars(__('Source'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</small></span>';
    foreach ($locales as $locale) {
        $status = $statuses[$locale] ?? 'empty';
        $url = $base . '/?' . http_build_query([
            'page' => 'admin/tools/content-translation/theme-zone-edit',
            'item_id' => $resource['id'],
            'locale' => $locale,
            'return_to' => $returnUrl,
        ], '', '&', PHP_QUERY_RFC3986);
        $html .= '<a class="ct-locale-chip ct-locale-chip--' . htmlspecialchars($status, ENT_QUOTES, 'UTF-8')
            . '" href="' . htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"><b>'
            . htmlspecialchars(strtoupper($locale), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</b><small>'
            . htmlspecialchars(ct_admin_translation_status_label($status), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</small></a>';
    }
    return $html . '</div>';
}

add_action('theme_manager_theme_actions', function (array $themeRow, array $manifest, array $context): void {
    try {
        $hookPdo = $GLOBALS['pdo'] ?? null;
        if (!$hookPdo instanceof PDO) return;
        $action = ct_core_theme_action_context($themeRow, $context, $hookPdo);
        if ($action === null) return;
        $folder = $action['folder'];
        $base = $action['base'];
        $returnTo = ct_core_theme_url($base, ['page' => 'admin/themes/assign']);
        $resources = array_slice(ct_theme_file_resources($hookPdo, $folder), 0, 128, true);
        $items = [];
        if ($resources !== []) {
            $items[] = ct_core_theme_action('tm-action ct-theme-action', ct_core_theme_url($base, [
                'page' => 'admin/tools/content-translation/theme-files',
                'theme_folder' => $folder,
                'return_to' => $returnTo,
            ]), 'Customizer Text');
        }
        if (!empty($context['is_active']) && ct_theme_zone_resources($hookPdo, $folder, null, true, 1) !== []) {
            $items[] = ct_core_theme_action('tm-action ct-theme-action', ct_core_theme_url($base, [
                'page' => 'admin/themes/customize',
            ]), 'Zone Gadget Text');
        }
        $items[] = ct_core_theme_action('tm-action ct-theme-action', ct_core_theme_url($base, [
            'page' => 'admin/tools/content-translation/theme-strings',
            'theme_folder' => $folder,
            'return_to' => $returnTo,
        ]), 'PHP Interface Strings');
        echo '<div class="tm-action-group tm-action-group--content-translation">'
            . '<span class="tm-action-owner ct-action-owner">' . htmlspecialchars(__('Content Translation'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</span>'
            . implode('', $items) . '</div>';
    } catch (Throwable $error) {
        error_log('[content-translation-theme-actions] ' . $error->getMessage());
    }
}, 20);

add_action('theme_source_editor_actions', function (array $themeRow, array $context, PDO $hookPdo): void {
    try {
        $action = ct_core_theme_action_context($themeRow, $context, $hookPdo);
        if ($action === null) return;
        $folder = $action['folder'];
        $base = $action['base'];
        $fileId = is_string($context['file_id'] ?? null) && preg_match('/\A[a-f0-9]{64}\z/D', $context['file_id']) === 1
            ? $context['file_id']
            : null;
        $returnQuery = ['page' => 'admin/themes/source', 'folder' => $folder];
        if ($fileId !== null) $returnQuery['file'] = $fileId;
        $returnTo = ct_core_theme_url($base, $returnQuery);

        $slots = [];
        foreach (is_array($context['slot_keys'] ?? null) ? $context['slot_keys'] : [] as $slot) {
            if (is_string($slot) && strlen($slot) <= 150 && preg_match('/\A[A-Za-z0-9_-]+(?:\.[A-Za-z0-9_-]+)*\z/D', $slot) === 1) {
                $slots[$slot] = true;
            }
            if (count($slots) >= 256) break;
        }
        $resourceSlots = [];
        foreach (ct_theme_file_resources($hookPdo, $folder) as $resource) {
            $resourceSlot = is_array($resource) && is_string($resource['slot_key'] ?? null)
                ? $resource['slot_key']
                : '';
            if ($resourceSlot !== '') $resourceSlots[$resourceSlot] = true;
        }
        $translatableSlots = [];
        foreach (array_keys($slots) as $slot) {
            if (isset($resourceSlots[$slot])) $translatableSlots[] = $slot;
        }

        $items = [];
        if ($translatableSlots !== []) {
            $query = [
                'page' => 'admin/tools/content-translation/theme-files',
                'theme_folder' => $folder,
                'return_to' => $returnTo,
            ];
            if (count($translatableSlots) === 1) $query['slot_key'] = $translatableSlots[0];
            $items[] = ct_core_theme_action('theme-source-action ct-theme-source-action', ct_core_theme_url($base, $query), 'Customizer Text');
        }
        $zoneResources = [];
        if (!empty($context['active'])) {
            foreach (array_keys($slots) as $slot) {
                foreach (ct_theme_zone_resources($hookPdo, $folder, $slot) as $resource) {
                    if (array_filter($resource['source_values'] ?? [], static fn($value): bool => is_scalar($value) && trim((string)$value) !== '') !== []) {
                        $zoneResources[] = $resource;
                    }
                }
            }
        }
        if ($zoneResources !== []) {
            $items[] = ct_core_theme_action('theme-source-action ct-theme-source-action', ct_core_theme_url($base, [
                'page' => 'admin/themes/customize',
                'edit' => (int)$zoneResources[0]['id'],
            ]), 'Zone Gadget Text');
        }
        $items[] = ct_core_theme_action('theme-source-action ct-theme-source-action', ct_core_theme_url($base, [
            'page' => 'admin/tools/content-translation/theme-strings',
            'theme_folder' => $folder,
            'return_to' => $returnTo,
        ]), 'PHP Interface Strings');
        echo '<div class="theme-source-action-group theme-source-action-group--content-translation">'
            . '<span class="theme-source-action-owner ct-action-owner">' . htmlspecialchars(__('Content Translation'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</span>'
            . implode('', $items) . '</div>';
    } catch (Throwable $error) {
        error_log('[content-translation-source-actions] ' . $error->getMessage());
    }
}, 20);

add_action('theme_source_editor_context', function (array $themeRow, array $context, PDO $hookPdo): void {
    try {
        $action = ct_core_theme_action_context($themeRow, $context, $hookPdo);
        if ($action === null || !function_exists('resolve_template')) return;
        $folder = $action['folder'];
        $base = $action['base'];
        $fileId = is_string($context['file_id'] ?? null) && preg_match('/\A[a-f0-9]{64}\z/D', $context['file_id']) === 1
            ? $context['file_id']
            : null;
        $relativePath = is_string($context['relative_path'] ?? null) ? trim($context['relative_path']) : '';
        if ($fileId === null || $relativePath === '' || strlen($relativePath) > 500) return;
        $slots = [];
        foreach (is_array($context['slot_keys'] ?? null) ? $context['slot_keys'] : [] as $slot) {
            if (is_string($slot) && strlen($slot) <= 150
                && preg_match('/\A[A-Za-z0-9_-]+(?:\.[A-Za-z0-9_-]+)*\z/D', $slot) === 1) $slots[$slot] = true;
            if (count($slots) >= 256) break;
        }
        if ($slots === []) return;

        $fallback = ct_core_theme_url($base, ['page' => 'admin/themes/source', 'folder' => $folder, 'file' => $fileId]);
        $returnUrl = function_exists('adiwira_safe_return_to')
            ? adiwira_safe_return_to($context['return_url'] ?? null, $fallback)
            : $fallback;
        $sourceLocale = function_exists('content_default_locale') ? content_default_locale() : 'en';
        $locales = array_slice(ct_enabled_locales($hookPdo), 0, 20);
        $fileResourcesBySlot = [];
        foreach (ct_theme_file_resources($hookPdo, $folder) as $resource) {
            $slot = is_array($resource) && is_string($resource['slot_key'] ?? null) ? $resource['slot_key'] : '';
            if ($slot !== '' && isset($slots[$slot])) $fileResourcesBySlot[$slot] = $resource;
        }
        $fileStatuses = ct_theme_file_translation_statuses($hookPdo, array_values($fileResourcesBySlot));
        $zoneResourcesBySlot = [];
        $allZoneResources = [];
        foreach (array_keys($slots) as $slot) {
            foreach (ct_theme_zone_resources($hookPdo, $folder, $slot) as $resource) {
                if (array_filter($resource['source_values'] ?? [], static fn($value): bool => is_scalar($value) && trim((string)$value) !== '') === []) continue;
                $zoneResourcesBySlot[$slot][] = $resource;
                $allZoneResources[] = $resource;
            }
        }
        $zoneStatuses = ct_theme_zone_translation_statuses_for_resources($hookPdo, $allZoneResources);

        echo '<section class="ct-source-context"><div class="ct-source-context__heading"><div><strong>'
            . htmlspecialchars(__('Frontend translation context'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</strong><span>'
            . htmlspecialchars(__('Source ownership and visitor-language versions connected to this PHP file.'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
            . '</span></div><a href="' . htmlspecialchars(ct_core_theme_url($base, ['page' => 'admin/tools/content-translation']), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">'
            . htmlspecialchars(__('Open Content Translation'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</a></div><div class="ct-source-context__slots">';

        foreach (array_keys($slots) as $slot) {
            try {
                $resolved = resolve_template($hookPdo, $slot);
            } catch (Throwable $resolveError) {
                error_log('[content-translation-source-context] Runtime resolution failed: ' . $resolveError->getMessage());
                $resolved = ['type' => 'unavailable'];
            }
            $runtimeType = (string)($resolved['type'] ?? 'unavailable');
            $runtimePost = $runtimeType === 'custom_post' && is_array($resolved['post'] ?? null) ? $resolved['post'] : null;
            $runtimeFolder = $runtimeType === 'theme_file' ? (string)($resolved['theme_folder'] ?? '') : '';
            $runtimeFile = $runtimeType === 'theme_file' ? (string)($resolved['theme_file'] ?? '') : '';
            $selectedRuntime = $runtimeType === 'theme_file' && $runtimeFolder !== '' && $runtimeFile !== ''
                && hash_equals($folder, $runtimeFolder) && hash_equals($relativePath, $runtimeFile);
            if ($selectedRuntime) {
                $runtimeLabel = __('Selected PHP file');
                $runtimeClass = 'active';
            } elseif ($runtimePost !== null) {
                $runtimeLabel = __('Theme Template') . ': ' . (string)($runtimePost['title'] ?? ('#' . (int)($runtimePost['id'] ?? 0)));
                $runtimeClass = 'shadowed';
            } elseif ($runtimeType === 'theme_file' && $runtimeFolder !== '') {
                $runtimeLabel = __('Theme PHP') . ': ' . $runtimeFolder;
                $runtimeClass = 'inactive';
            } else {
                $runtimeLabel = __('Unavailable');
                $runtimeClass = 'inactive';
            }
            echo '<article class="ct-source-context__slot"><header><div><span>'
                . htmlspecialchars(__('Frontend slot'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</span><code>'
                . htmlspecialchars($slot, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</code></div><span class="ct-runtime-state ct-runtime-state--'
                . htmlspecialchars($runtimeClass, ENT_QUOTES, 'UTF-8') . '">'
                . htmlspecialchars(__('Runtime source') . ': ' . $runtimeLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</span></header>';

            if ($runtimePost !== null) {
                $postId = (int)($runtimePost['id'] ?? 0);
                $postStatuses = [];
                foreach ($locales as $locale) {
                    $translation = ct_get_translation($hookPdo, $postId, $locale);
                    $postStatuses[$locale] = !is_array($translation) ? 'empty'
                        : ((string)($translation['status'] ?? 'draft') !== 'published' ? 'draft'
                            : (ct_post_translation_is_complete($hookPdo, $translation) ? 'published' : 'incomplete'));
                }
                $postPage = function_exists('ct_parse_theme_section_composition')
                    && ct_parse_theme_section_composition((string)($runtimePost['content'] ?? '')) !== null
                    ? 'admin/tools/content-translation/theme-section-edit'
                    : 'admin/tools/content-translation/edit';
                echo '<div class="ct-source-context__resource"><div><b>'
                    . htmlspecialchars(__('Theme Template'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</b><small>'
                    . htmlspecialchars(__('This stored template currently replaces the selected PHP file on the frontend.'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</small></div>'
                    . ct_theme_source_locale_chips_html($sourceLocale, $locales, $postStatuses, static fn(string $locale): string => ct_core_theme_url($base, [
                        'page' => $postPage, 'post_id' => $postId, 'locale' => $locale, 'return_to' => $returnUrl,
                    ])) . '</div>';
            }

            $fileResource = $fileResourcesBySlot[$slot] ?? null;
            if (is_array($fileResource)) {
                $resourceId = (string)$fileResource['id'];
                echo '<div class="ct-source-context__resource"><div><b>'
                    . htmlspecialchars(__('Customizer Text'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</b><small>'
                    . htmlspecialchars($selectedRuntime ? __('Used on frontend') : __('Stored but not used by the current frontend assignment'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</small></div>'
                    . ct_theme_source_locale_chips_html($sourceLocale, $locales, $fileStatuses[$resourceId] ?? [], static fn(string $locale): string => ct_core_theme_url($base, [
                        'page' => 'admin/tools/content-translation/theme-file-edit', 'theme_folder' => $folder,
                        'slot_key' => $slot, 'locale' => $locale, 'return_to' => $returnUrl,
                    ])) . '</div>';
            }

            foreach ($zoneResourcesBySlot[$slot] ?? [] as $resource) {
                $itemId = (int)$resource['id'];
                $title = trim((string)($resource['title'] ?? ''));
                if ($title === '') $title = '#' . $itemId;
                $location = $title . ' - ' . (string)($resource['position'] ?? '');
                echo '<div class="ct-source-context__resource"><div><b>'
                    . htmlspecialchars(__('Zone Gadget Text'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</b><small>'
                    . htmlspecialchars($location . ($selectedRuntime ? '' : ' - ' . __('Not used on frontend')), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</small></div>'
                    . ct_theme_source_locale_chips_html($sourceLocale, $locales, $zoneStatuses[$itemId] ?? [], static fn(string $locale): string => ct_core_theme_url($base, [
                        'page' => 'admin/tools/content-translation/theme-zone-edit', 'item_id' => $itemId,
                        'locale' => $locale, 'return_to' => $returnUrl,
                    ])) . '</div>';
            }
            echo '</article>';
        }
        echo '</div></section>';
    } catch (Throwable $error) {
        error_log('[content-translation-source-context] ' . $error->getMessage());
    }
}, 20);

add_action('theme_customize_actions', function (array $themeRow, array $context, PDO $hookPdo): void {
    try {
        $action = ct_core_theme_action_context($themeRow, $context, $hookPdo);
        if ($action === null) return;
        $folder = $action['folder'];
        $base = $action['base'];
        $fallback = $base . '/?page=admin/themes/customize';
        $returnUrl = function_exists('adiwira_safe_return_to')
            ? adiwira_safe_return_to($context['return_url'] ?? null, $fallback)
            : $fallback;
        $sourceLocale = function_exists('content_default_locale') ? content_default_locale() : 'en';
        $locales = array_slice(ct_enabled_locales($hookPdo), 0, 20);
        $homepage = ct_homepage_theme_post($hookPdo);
        $resources = ct_theme_file_resources($hookPdo, $folder);

        echo '<section class="ct-customize-guide"><div class="ct-customize-guide__heading"><div><strong>'
            . htmlspecialchars(__('Frontend translations'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</strong><span>'
            . htmlspecialchars(sprintf(__('You are editing the %s source. Published locale values replace only declared frontend text; layout and behavior stay shared.'), strtoupper($sourceLocale)), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
            . '</span></div><a href="' . htmlspecialchars($base . '/?page=admin/tools/content-translation', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">'
            . htmlspecialchars(__('Open Content Translation'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</a></div>';

        if (is_array($homepage)) {
            $postId = (int)($homepage['id'] ?? 0);
            $isPackage = function_exists('ct_parse_theme_section_composition')
                && ct_parse_theme_section_composition((string)($homepage['content'] ?? '')) !== null;
            echo '<div class="ct-customize-guide__row"><div><b>' . htmlspecialchars(__('Homepage frontend source'), ENT_QUOTES, 'UTF-8')
                . '</b><span>' . htmlspecialchars(__('Theme Template'), ENT_QUOTES, 'UTF-8') . ': '
                . htmlspecialchars((string)($homepage['title'] ?? ('#' . $postId)), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                . '</span></div><div class="ct-locale-chips"><span class="ct-locale-chip ct-locale-chip--source"><b>'
                . htmlspecialchars(strtoupper($sourceLocale), ENT_QUOTES, 'UTF-8') . '</b><small>' . htmlspecialchars(__('Source'), ENT_QUOTES, 'UTF-8') . '</small></span>';
            foreach ($locales as $locale) {
                $translation = ct_get_translation($hookPdo, $postId, $locale);
                $status = !is_array($translation)
                    ? 'empty'
                    : ((string)($translation['status'] ?? 'draft') !== 'published'
                        ? 'draft'
                        : (ct_post_translation_is_complete($hookPdo, $translation) ? 'published' : 'incomplete'));
                $page = $isPackage ? 'admin/tools/content-translation/theme-section-edit' : 'admin/tools/content-translation/edit';
                $url = $base . '/?' . http_build_query(['page' => $page, 'post_id' => $postId, 'locale' => $locale, 'return_to' => $returnUrl], '', '&', PHP_QUERY_RFC3986);
                echo '<a class="ct-locale-chip ct-locale-chip--' . htmlspecialchars($status, ENT_QUOTES, 'UTF-8') . '" href="'
                    . htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"><b>' . htmlspecialchars(strtoupper($locale), ENT_QUOTES, 'UTF-8')
                    . '</b><small>' . htmlspecialchars(ct_admin_translation_status_label($status), ENT_QUOTES, 'UTF-8') . '</small></a>';
            }
            echo '</div></div>';
        }

        $inactiveResources = [];
        foreach ($resources as $resource) {
            $runtime = ct_theme_file_runtime_state($hookPdo, $resource);
            if (!$runtime['active']) $inactiveResources[] = ['resource' => $resource, 'runtime' => $runtime];
        }
        if ($inactiveResources !== []) {
            $themeFilesUrl = $base . '/?' . http_build_query([
                'page' => 'admin/tools/content-translation/theme-files',
                'theme_folder' => $folder,
                'return_to' => $returnUrl,
            ], '', '&', PHP_QUERY_RFC3986);
            echo '<div class="ct-customize-guide__row ct-customize-guide__row--warning"><div><b>'
                . htmlspecialchars(__('Stored Customizer translations'), ENT_QUOTES, 'UTF-8') . '</b><span>'
                . htmlspecialchars(sprintf(__('%d resource(s) are not used by the current frontend assignment. Editing them will not change the visible page.'), count($inactiveResources)), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                . '</span></div><a class="ct-customize-guide__link" href="' . htmlspecialchars($themeFilesUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">'
                . htmlspecialchars(__('Review Customizer Text'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</a></div>';
        }
        echo '<div class="ct-customize-guide__row"><div><b>' . htmlspecialchars(__('Header and footer text'), ENT_QUOTES, 'UTF-8')
            . '</b><span>' . htmlspecialchars(__('Open a gadget to edit its source text, or use the always-visible locale badges on that gadget to edit what visitors see.'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
            . '</span></div></div></section>';
    } catch (Throwable $error) {
        error_log('[content-translation-customize-actions] ' . $error->getMessage());
    }
}, 20);

add_action('theme_zone_item_summary', function ($item, $context, $pdo): void {
    if (!is_array($item) || !is_array($context) || !$pdo instanceof PDO) return;
    try {
        echo ct_theme_zone_translation_actions_html($item, $context, $pdo);
    } catch (Throwable $error) {
        error_log('[content-translation] Theme Zone summary failed: ' . $error->getMessage());
    }
}, 10, 3);

// ─── Ensure schema exists when in admin ───
add_action('admin_init', function () {
    $pdo = $GLOBALS['pdo'] ?? null;
    if (!$pdo instanceof PDO) return;
    ct_ensure_schema($pdo);
    if (!ct_user_can_workspace($pdo)) return;
    ct_seed_shortcode_preset_ui_translations($pdo);

    $page = trim((string)($_GET['page'] ?? ''), '/');
    $types = [
        'admin/posts/edit' => 'article',
        'admin/pages/edit' => 'page',
        'admin/themes/edit' => 'theme',
    ];
    if (!isset($types[$page])) return;

    $postId = (int)($_GET['id'] ?? 0);
    if ($postId <= 0) return;
    $stmt = $pdo->prepare('SELECT id, type, content, meta, status, created_by FROM posts WHERE id = ? AND type = ? AND is_deleted = 0 LIMIT 1');
    $stmt->execute([$postId, $types[$page]]);
    $post = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$post) return;

    $locale = ct_author_default_locale($pdo, ct_current_user_id());
    if ($locale === ct_post_source_locale($pdo, $post)
        || !in_array($locale, ct_post_translation_locales($pdo, $post), true)
        || !ct_user_can_edit_post_locale($pdo, $post, $locale)) {
        return;
    }

    $base = defined('ADMIN_BASE_PATH') ? ADMIN_BASE_PATH : '/adiwira';
    $defaultReturnTo = $base . '/?page=' . match ((string)$post['type']) {
        'page' => 'admin/pages/index',
        'theme' => 'admin/themes/index',
        default => 'admin/posts/index',
    };
    $returnTo = function_exists('adiwira_safe_return_to')
        ? adiwira_safe_return_to($_GET['return_to'] ?? null, $defaultReturnTo)
        : $defaultReturnTo;
    $packageComposed = $post['type'] === 'theme'
        && ct_parse_theme_section_composition((string)($post['content'] ?? '')) !== null;
    $target = $base . '/?' . http_build_query([
        'page' => 'admin/tools/content-translation/' . ($packageComposed ? 'theme-section-edit' : 'edit'),
        'post_id' => $postId,
        'locale' => $locale,
        'return_to' => $returnTo,
    ]);
    header('Location: ' . $target, true, 302);
    exit;
});

if (!function_exists('ct_create_authored_post_workflow')) {
    function ct_create_authored_post_workflow(int $postId, PDO $pdo): void {
        if ($postId <= 0 || ct_post_workflow($pdo, $postId) !== null) return;
        $stmt = $pdo->prepare("SELECT id, type, title, slug, content, meta, status, created_by FROM posts WHERE id = ? AND type IN ('article', 'page') AND is_deleted = 0 LIMIT 1 FOR UPDATE");
        $stmt->execute([$postId]);
        $post = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$post) throw new RuntimeException('Authored post is unavailable.');

        $authorLocale = ct_author_default_locale($pdo, (int)$post['created_by']);
        $sourceLocale = function_exists('content_default_locale') ? content_default_locale() : 'en';
        $actorId = ct_current_user_id();
        if (!ct_user_has_locale_edit_grant($pdo, $actorId, $authorLocale, $pdo->inTransaction())) {
            throw new RuntimeException('Writing locale permission denied.');
        }
        if ($authorLocale === $sourceLocale) return;
        if (!in_array($authorLocale, ct_enabled_locales($pdo), true)) {
            throw new RuntimeException('Author writing language is unavailable.');
        }
        $slugLock = ct_translation_slug_lock_name($authorLocale, (string)$post['slug']);
        $lock = $pdo->prepare('SELECT GET_LOCK(?, 5)');
        $lock->execute([$slugLock]);
        if ((int)$lock->fetchColumn() !== 1) {
            throw new RuntimeException('Authored translation slug could not be reserved.');
        }
        $GLOBALS['_ct_authored_slug_locks'][$postId] = $slugLock;
        if (ct_translation_slug_conflict($pdo, $postId, $authorLocale, (string)$post['slug']) !== null) {
            throw new RuntimeException('Authored translation slug conflicts with existing localized content.');
        }

        $meta = is_string($post['meta'] ?? null) && $post['meta'] !== ''
            ? json_decode((string)$post['meta'], true)
            : [];
        $metaDescription = is_array($meta) ? trim((string)($meta['meta_tags']['description'] ?? '')) : '';
        if (is_array($meta)) unset($meta['meta_tags']['description']);
        if (($meta['meta_tags'] ?? null) === []) unset($meta['meta_tags']);
        $sourceMeta = $meta === [] ? null : json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $translationStatus = (string)$post['status'] === 'published' ? 'published' : 'draft';

        if (!ct_save_translation($pdo, $postId, $authorLocale, [
            'title' => (string)$post['title'],
            'slug' => (string)$post['slug'],
            'content' => (string)$post['content'],
            'meta_description' => $metaDescription,
            'status' => $translationStatus,
        ], $actorId) || !ct_save_post_workflow($pdo, $postId, $sourceLocale, $authorLocale, 'draft')) {
            throw new RuntimeException('Authored translation workflow could not be saved.');
        }

        $placeholderSlug = ct_pending_source_slug($pdo, $postId, $sourceLocale);
        $update = $pdo->prepare('UPDATE posts SET title = ?, slug = ?, content = ?, meta = ?, status = ? WHERE id = ?');
        if (!$update->execute([
            '[' . strtoupper($sourceLocale) . ' translation pending]',
            $placeholderSlug,
            '',
            $sourceMeta,
            ct_post_effective_status($pdo, $postId, 'draft'),
            $postId,
        ])) {
            throw new RuntimeException('Canonical source draft could not be initialized.');
        }
    }

    function ct_update_authored_post_source(int $postId, PDO $pdo, array $input, string $type): void {
        $workflow = ct_post_workflow($pdo, $postId);
        if (!$workflow) return;
        $actorId = ct_current_user_id();
        $sourceLocale = (string)$workflow['source_locale'];
        if (!ct_user_has_locale_edit_grant($pdo, $actorId, $sourceLocale, $pdo->inTransaction())) {
            throw new RuntimeException('Only the canonical-language editor may update this source.');
        }
        $previousOwner = (int)($input['previous_created_by'] ?? 0);
        $nextOwner = (int)($input['created_by'] ?? 0);
        if ($workflow && ($previousOwner <= 0 || $nextOwner !== $previousOwner)) {
            throw new RuntimeException('Ownership cannot change while a localized workflow is active.');
        }
        $sourceStatus = (string)($input['status'] ?? 'draft');
        if (!ct_update_post_source_status($pdo, $postId, $sourceStatus)) {
            throw new RuntimeException('Canonical source status could not be updated.');
        }
    }
}

add_action('admin_post_before_add_commit', function ($postId, $pdo): void {
    if (!$pdo instanceof PDO) return;
    ct_create_authored_post_workflow((int)$postId, $pdo);
}, 10, 2);

add_action('admin_page_before_add_commit', function ($postId, $pdo): void {
    if (!$pdo instanceof PDO) return;
    ct_create_authored_post_workflow((int)$postId, $pdo);
}, 10, 2);

add_action('admin_post_before_edit_commit', function ($postId, $pdo, $input): void {
    if (!$pdo instanceof PDO || !is_array($input)) return;
    ct_update_authored_post_source((int)$postId, $pdo, $input, 'article');
}, 10, 3);

add_action('admin_page_before_edit_commit', function ($postId, $pdo, $input): void {
    if (!$pdo instanceof PDO || !is_array($input)) return;
    ct_update_authored_post_source((int)$postId, $pdo, $input, 'page');
}, 10, 3);

add_action('admin_post_after_add', function ($postId, $pdo): void {
    $lockName = $GLOBALS['_ct_authored_slug_locks'][(int)$postId] ?? null;
    unset($GLOBALS['_ct_authored_slug_locks'][(int)$postId]);
    if (!$pdo instanceof PDO || !is_string($lockName) || $lockName === '') return;
    $release = $pdo->prepare('SELECT RELEASE_LOCK(?)');
    $release->execute([$lockName]);
}, 1, 2);

add_action('admin_page_after_add', function ($postId, $pdo): void {
    $lockName = $GLOBALS['_ct_authored_slug_locks'][(int)$postId] ?? null;
    unset($GLOBALS['_ct_authored_slug_locks'][(int)$postId]);
    if (!$pdo instanceof PDO || !is_string($lockName) || $lockName === '') return;
    $release = $pdo->prepare('SELECT RELEASE_LOCK(?)');
    $release->execute([$lockName]);
}, 1, 2);

add_filter('admin_post_editor_status', function ($status, $post, $pdo) {
    if (!$pdo instanceof PDO || !is_array($post)) return $status;
    $workflow = ct_post_workflow($pdo, (int)($post['id'] ?? 0));
    return $workflow ? (string)$workflow['source_status'] : $status;
}, 10, 3);

add_filter('admin_page_editor_status', function ($status, $post, $pdo) {
    if (!$pdo instanceof PDO || !is_array($post)) return $status;
    $workflow = ct_post_workflow($pdo, (int)($post['id'] ?? 0));
    return $workflow ? (string)$workflow['source_status'] : $status;
}, 10, 3);

add_filter('site_settings_validation_errors', function ($errors, $pdo, $input, $context) {
    if (!is_array($errors) || !$pdo instanceof PDO || !is_array($context)) return $errors;
    $current = function_exists('content_default_locale') ? content_default_locale() : 'en';
    $requested = trim((string)($context['content_default_language'] ?? $current));
    if ($requested !== '' && $requested !== $current) {
        if (ct_post_authoring_locales_in_use($pdo) !== []) {
            $errors[] = __('Content default language cannot change while localized content workflows exist.');
        }
        if (function_exists('ct_localized_media_supported') && ct_localized_media_supported()) {
            try {
                if (ct_localized_media_state_exists($pdo)) $errors[] = __('Content default language cannot change while localized media state exists.');
            } catch (Throwable $error) {
                $errors[] = __('Content default language cannot change because localized media state could not be verified.');
            }
        }
    }
    if (!array_key_exists('ct_collection_paths', $input)) return $errors;
    $collectionPaths = $input['ct_collection_paths'];
    if (!is_array($collectionPaths)) {
        $errors[] = __('Localized collection paths are invalid.');
        return $errors;
    }
    foreach (ct_enabled_locales($pdo) as $locale) {
        $row = $collectionPaths[$locale] ?? [];
        if (!is_array($row)) {
            $errors[] = __('Localized collection paths are invalid.');
            continue;
        }
        $paths = [];
        foreach (['posts', 'pages'] as $type) {
            $value = $row[$type] ?? '';
            if (!is_string($value)) {
                $errors[] = __('Localized collection paths are invalid.');
                continue;
            }
            $path = trim($value, '/');
            if ($path === '' || !preg_match('/^[a-z0-9_\/-]+$/', $path)) {
                $errors[] = __('Collection paths may only contain lowercase letters, numbers, slashes, underscores, and hyphens.');
                continue;
            }
            $paths[$type] = $path;
        }
        if (isset($paths['posts'], $paths['pages']) && $paths['posts'] === $paths['pages']) {
            $errors[] = __('Post and Page list paths must be different in each language.');
        }
    }
    return $errors;
}, 10, 4);

add_action('admin_posts_bulk_before_mutation', function ($action, $posts, $pdo): void {
    if (!$pdo instanceof PDO || !is_array($posts) || !in_array($action, ['change_status', 'change_author'], true)) return;
    foreach ($posts as $post) {
        if (is_array($post) && ct_post_workflow($pdo, (int)($post['id'] ?? 0)) !== null) {
            throw new RuntimeException('Localized workflow status and ownership must be changed in its language editor.');
        }
    }
}, 10, 3);

add_action('admin_pages_bulk_before_mutation', function ($action, $posts, $pdo): void {
    if (!$pdo instanceof PDO || !is_array($posts) || !in_array($action, ['change_status', 'change_author'], true)) return;
    foreach ($posts as $post) {
        if (is_array($post) && ct_post_workflow($pdo, (int)($post['id'] ?? 0)) !== null) {
            throw new RuntimeException('Localized workflow status and ownership must be changed in its language editor.');
        }
    }
}, 10, 3);

add_filter('admin_category_list_rows', function ($categories, $context, $pdo) {
    if (!is_array($categories) || !$pdo instanceof PDO) return $categories;
    $context = is_array($context) ? $context : [];
    $actorId = (int)($context['actor_id'] ?? 0);
    if ($actorId <= 0) $actorId = ct_current_user_id();
    $locale = ct_admin_content_list_locale($pdo, $context, $actorId);
    $default = function_exists('content_default_locale') ? content_default_locale() : 'en';
    if ($locale === $default) return $categories;

    foreach ($categories as &$category) {
        if (!is_array($category)) continue;
        $translation = ct_get_published_category_translation($pdo, (int)($category['id'] ?? 0), $locale);
        if (!$translation) continue;
        foreach (['name', 'description'] as $field) {
            if (trim((string)($translation[$field] ?? '')) !== '') $category[$field] = (string)$translation[$field];
        }
        $url = ct_category_url($pdo, $category, $locale);
        if (is_string($url) && $url !== '') $category['display_url'] = $url;
    }
    unset($category);
    return $categories;
}, 10, 3);

add_action('admin_category_row_actions', function ($category, $context, $pdo): void {
    if (!is_array($category) || !is_array($context) || !$pdo instanceof PDO
        || empty($context['can_update']) || !ct_user_can_workspace($pdo)) return;
    $locales = ct_enabled_locales($pdo);
    if ($locales === []) return;

    $base = defined('ADMIN_BASE_PATH') ? ADMIN_BASE_PATH : '/adiwira';
    echo '<select aria-label="' . htmlspecialchars(__('Translations'), ENT_QUOTES) . '" onchange="if(this.value)window.location.href=this.value" style="font-size:11px;padding:1px 4px;">';
    echo '<option value="">' . htmlspecialchars(__('Translations'), ENT_QUOTES) . '</option>';
    foreach ($locales as $locale) {
        $translation = ct_get_category_translation($pdo, (int)$category['id'], $locale);
        $url = $base . '/?' . http_build_query([
            'page' => 'admin/tools/content-translation/category-edit',
            'category_id' => (int)$category['id'],
            'locale' => $locale,
            'return_to' => (string)($context['return_to'] ?? ''),
        ]);
        $label = strtoupper($locale) . ' - ' . ($translation ? __('Edit') : __('Add'));
        echo '<option value="' . htmlspecialchars($url, ENT_QUOTES) . '">' . htmlspecialchars($label, ENT_QUOTES) . '</option>';
    }
    echo '</select>';
}, 10, 3);

add_action('admin_category_before_edit_commit', function ($categoryId, $pdo): void {
    if (!$pdo instanceof PDO || (int)$categoryId <= 0) return;
    ct_assert_category_translation_paths_unique($pdo, (int)$categoryId);
}, 10, 2);

add_action('admin_category_before_restore_commit', function ($categoryId, $pdo): void {
    if (!$pdo instanceof PDO || (int)$categoryId <= 0) return;
    ct_assert_category_translation_paths_unique($pdo, (int)$categoryId);
}, 10, 2);

add_action('admin_category_before_purge_commit', function ($categoryId, $category, $pdo): void {
    if (!$pdo instanceof PDO || (int)$categoryId <= 0) return;
    $stmt = $pdo->prepare('DELETE FROM category_translations WHERE category_id = ?');
    if (!$stmt->execute([(int)$categoryId])) throw new RuntimeException('Category translation cleanup failed.');
}, 10, 3);

add_action('admin_footer', function (): void {
    static $rendered = false;
    if ($rendered) return;

    $page = trim((string)($_GET['page'] ?? ''), '/');
    if (!in_array($page, ['admin/posts/add', 'admin/posts/edit', 'admin/pages/add', 'admin/pages/edit'], true)) return;
    $pdo = $GLOBALS['pdo'] ?? null;
    if (!$pdo instanceof PDO) return;
    $default = function_exists('content_default_locale') ? content_default_locale() : 'en';
    $locale = ct_author_default_locale($pdo, ct_current_user_id());
    if (!is_string($locale) || $locale === '' || $locale === $default) return;
    $rendered = true;

    $categoryStmt = $pdo->prepare("SELECT category_id, name FROM category_translations
        WHERE locale = ? AND status = 'published' AND TRIM(name) <> ''");
    $categoryStmt->execute([$locale]);
    $categoryNames = [];
    foreach ($categoryStmt->fetchAll(PDO::FETCH_ASSOC) as $category) {
        $categoryId = (int)($category['category_id'] ?? 0);
        if ($categoryId > 0) $categoryNames[(string)$categoryId] = (string)$category['name'];
    }

    $notice = null;
    if (in_array($page, ['admin/posts/add', 'admin/pages/add'], true)) {
        $contentType = $page === 'admin/pages/add' ? __('page') : __('article');
        $notice = [
            'title' => __('Writing language') . ': ' . strtoupper($locale),
            'message' => sprintf(
                __('This %s will be saved for /%s/. The site default language remains %s.'),
                $contentType,
                $locale,
                strtoupper($default)
            ),
            'form_id' => $page === 'admin/pages/add' ? 'page-add-form' : 'post-add-form',
        ];
    }
    echo '<script>(function(){var names=' . json_encode($categoryNames, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . ';document.querySelectorAll(\'input[name="categories[]"]\').forEach(function(input){var name=names[String(input.value)];if(!name)return;var node=input.nextSibling;while(node&&node.nodeType!==3)node=node.nextSibling;if(node)node.nodeValue=" "+name;else input.parentNode.appendChild(document.createTextNode(" "+name))});var noticeData=' . json_encode($notice, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . ';if(!noticeData)return;var form=document.getElementById(noticeData.form_id);if(!form)return;var notice=document.createElement("div");notice.className="ct-author-language-notice";var strong=document.createElement("strong");strong.textContent=noticeData.title;var span=document.createElement("span");span.textContent=noticeData.message;notice.append(strong,span);form.prepend(notice)})()</script>';
});

add_action('admin_footer', function (): void {
    $page = trim((string)($_GET['page'] ?? ''), '/');
    if (!in_array($page, ['admin/categories/add', 'admin/categories/edit'], true)) return;
    $pdo = $GLOBALS['pdo'] ?? null;
    if (!$pdo instanceof PDO) return;
    $locale = ct_author_default_locale($pdo, ct_current_user_id());
    $default = function_exists('content_default_locale') ? content_default_locale() : 'en';
    if ($locale === $default) return;

    $message = sprintf(
        __('Categories are shared across all content languages. This Core editor manages the canonical %s category; localized labels are managed separately.'),
        strtoupper($default)
    );
    $formId = $page === 'admin/categories/add' ? 'category-add-form' : 'category-edit-form';
    echo '<script>(function(){var form=document.getElementById(' . json_encode($formId) . ');if(!form)return;var notice=document.createElement("div");notice.className="ct-author-language-notice";notice.textContent=' . json_encode($message, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . ';form.prepend(notice)})()</script>';
});

add_action('admin_content_list_filters', function ($context, $pdo): void {
    if (!$pdo instanceof PDO || !is_array($context) || ct_admin_content_list_types($context) === []
        || !ct_user_can_workspace($pdo)) return;
    if (ct_admin_content_list_types($context) === ['theme'] && !ct_user_is_site_owner($pdo)) return;

    $selected = ct_admin_content_list_locale($pdo, $context);
    $presets = function_exists('content_locale_presets') ? content_locale_presets() : [];
    $formId = is_string($context['filter_form_id'] ?? null)
        && preg_match('/\A[a-z][a-z0-9_-]{0,63}\z/', $context['filter_form_id']) === 1
        ? $context['filter_form_id']
        : '';
    $selectedLabel = isset($presets[$selected]) ? __((string)$presets[$selected]) . ' (' . strtoupper($selected) . ')' : strtoupper($selected);
    echo '<div class="ct-content-list-language" title="' . htmlspecialchars(__('Language') . ': ' . $selectedLabel, ENT_QUOTES, 'UTF-8') . '">';
    echo svg_ico('globe');
    echo '<span class="ct-content-list-language-code" aria-hidden="true">' . htmlspecialchars(strtoupper($selected), ENT_QUOTES, 'UTF-8') . '</span>';
    echo '<span class="ct-content-list-language-chevron" aria-hidden="true">' . svg_ico('chevron-down') . '</span>';
    echo '<label class="sr-only" for="ct-content-list-locale">' . htmlspecialchars(__('Language'), ENT_QUOTES, 'UTF-8') . '</label>';
    echo '<select class="inp ct-content-list-locale" id="ct-content-list-locale" name="content_locale" data-ct-content-list-locale aria-label="' . htmlspecialchars(__('Language'), ENT_QUOTES, 'UTF-8') . '"'
        . ($formId !== '' ? ' form="' . htmlspecialchars($formId, ENT_QUOTES, 'UTF-8') . '"' : '') . '>';
    foreach (ct_content_locales($pdo) as $locale) {
        $label = isset($presets[$locale]) ? __((string)$presets[$locale]) . ' (' . strtoupper($locale) . ')' : strtoupper($locale);
        echo '<option value="' . htmlspecialchars($locale, ENT_QUOTES, 'UTF-8') . '"' . ($locale === $selected ? ' selected' : '') . '>' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</option>';
    }
    echo '</select>';
    echo '</div>';
    echo '<script>(function(){var select=document.querySelector("[data-ct-content-list-locale]");if(!select||select.dataset.ctAjaxReady)return;select.dataset.ctAjaxReady="1";select.addEventListener("change",function(){var url=new URL(window.location.href);url.searchParams.set("content_locale",select.value);url.searchParams.delete("p");select.disabled=true;select.setAttribute("aria-busy","true");fetch(url.href,{method:"GET",credentials:"same-origin",cache:"no-store",redirect:"error",headers:{Accept:"text/html"}}).then(function(response){if(!response.ok||!(response.headers.get("content-type")||"").toLowerCase().includes("text/html"))throw new Error("invalid response");return response.text()}).then(function(html){window.history.pushState({contentLocale:select.value},"",url.href);document.open();document.write(html);document.close()}).catch(function(){window.location.assign(url.href)})})})()</script>';
}, 10, 2);

add_filter('admin_content_core_edit_action_visible', function ($visible, $row, $context, $pdo) {
    if ($visible !== true || !is_array($context) || !$pdo instanceof PDO) return $visible;
    $type = (string)($context['content_type'] ?? '');
    if (!in_array($type, ['article', 'page', 'theme', 'category'], true)) return $visible;
    $locale = ct_admin_content_list_locale($pdo, ['type' => $type], (int)($context['actor_id'] ?? 0));
    $default = function_exists('content_default_locale') ? content_default_locale() : 'en';
    return $locale === $default;
}, 10, 4);

// Show the requested list representation while retaining Core's source row as
// the fallback. The list language is independent from the writing preference.
add_filter('post_list_join', function (string $join, $where = '', $context = []): string {
    $pdo = $GLOBALS['pdo'] ?? null;
    if (!$pdo instanceof PDO) return $join;
    $context = is_array($context) ? $context : [];
    if (!ct_admin_post_list_context($context)) return $join;
    $locale = ct_admin_content_list_locale($pdo, $context);
    $default = function_exists('content_default_locale') ? content_default_locale() : 'en';
    $localeSql = $pdo->quote($locale);
    $translationCondition = $locale === $default ? ' AND 1 = 0' : '';

    return $join . " LEFT JOIN ct_post_workflows ct_post_list_workflow
        ON ct_post_list_workflow.post_id = p.id
        LEFT JOIN post_translations ct_post_list_display
        ON ct_post_list_display.post_id = p.id
        AND ct_post_list_display.locale = {$localeSql}{$translationCondition}";
}, 10, 3);

add_filter('post_list_select', function (string $select, $where = '', $context = []): string {
    $pdo = $GLOBALS['pdo'] ?? null;
    if (!$pdo instanceof PDO) return $select;
    $context = is_array($context) ? $context : [];
    if (!ct_admin_post_list_context($context)) return $select;
    $locale = ct_admin_content_list_locale($pdo, $context);
    $localeSql = $pdo->quote($locale);

    return $select . ",
        CASE WHEN TRIM(COALESCE(ct_post_list_display.title, '')) <> '' THEN ct_post_list_display.title ELSE p.title END AS title,
        CASE WHEN TRIM(COALESCE(ct_post_list_display.slug, '')) <> '' THEN ct_post_list_display.slug ELSE p.slug END AS slug,
        CASE
            WHEN {$localeSql} = ct_post_list_workflow.source_locale THEN ct_post_list_workflow.source_status
            WHEN ct_post_list_display.post_id IS NOT NULL THEN ct_post_list_display.status
            ELSE p.status
        END AS status,
        ct_post_list_display.id AS ct_translation_id,
        CASE WHEN ct_post_list_display.post_id IS NOT NULL THEN {$localeSql} ELSE NULL END AS ct_locale,
        CASE WHEN ct_post_list_display.post_id IS NOT NULL THEN ct_post_list_display.slug ELSE NULL END AS ct_translated_slug";
}, 10, 3);

add_filter('post_list_rows', function ($rows, $context) {
    if (!is_array($rows) || !is_array($context)
        || !in_array('theme', ct_admin_content_list_types($context), true)) return $rows;
    $pdo = $GLOBALS['pdo'] ?? null;
    if (!$pdo instanceof PDO) return $rows;

    foreach ($rows as &$row) {
        if (!is_array($row) || (string)($row['type'] ?? 'theme') !== 'theme') continue;
        $locale = trim((string)($row['ct_locale'] ?? ''));
        $slug = trim((string)($row['ct_translated_slug'] ?? ''));
        if ($locale === '') continue;
        $translation = ct_get_public_post_translation($pdo, (int)($row['id'] ?? 0), $locale);
        if (!$translation || (string)($translation['slug'] ?? '') !== $slug) continue;
        $row['public_path'] = $slug === '' ? $locale : $locale . '/' . trim($slug, '/');
        $row['display_permalink'] = ct_post_url($slug, $locale);
    }
    unset($row);
    return $rows;
}, 10, 2);

add_action('site_settings_after_general', function ($pdo) {
    if (!$pdo instanceof PDO || !ct_user_can_workspace($pdo)
        || !user_can($pdo, ct_current_user_id(), 'core.settings.manage')) return;
    $locales = ct_enabled_locales($pdo);
    if (empty($locales)) return;
    $id = 'ct-site-identity';
    echo '<div class="form-group" id="' . $id . '"><label>' . htmlspecialchars(__('Translated site identity'), ENT_QUOTES) . '</label><span class="field-note">' . htmlspecialchars(__('Used for the localized homepage title and description.'), ENT_QUOTES) . '</span>';
    echo '<label style="display:block;margin-top:.6rem">' . htmlspecialchars(__('Language'), ENT_QUOTES) . '<select class="inp inp-w100 ct-site-identity-locale">';
    foreach ($locales as $locale) {
        echo '<option value="' . htmlspecialchars($locale, ENT_QUOTES) . '">' . htmlspecialchars(strtoupper($locale), ENT_QUOTES) . '</option>';
    }
    echo '</select></label>';
    foreach ($locales as $index => $locale) {
        $translation = ct_site_translation($pdo, $locale) ?? [];
        echo '<div data-ct-site-locale="' . htmlspecialchars($locale, ENT_QUOTES) . '" style="display:' . ($index === 0 ? 'block' : 'none') . ';margin-top:.6rem"><input class="inp inp-w100" name="ct_site_title[' . htmlspecialchars($locale, ENT_QUOTES) . ']" value="' . htmlspecialchars((string)($translation['title'] ?? ''), ENT_QUOTES) . '" placeholder="' . htmlspecialchars(__('Site title'), ENT_QUOTES) . '"><textarea class="inp inp-w100" rows="2" style="display:block;margin-top:.6rem" name="ct_site_description[' . htmlspecialchars($locale, ENT_QUOTES) . ']" placeholder="' . htmlspecialchars(__('Site description'), ENT_QUOTES) . '">' . htmlspecialchars((string)($translation['description'] ?? ''), ENT_QUOTES) . '</textarea></div>';
    }
    echo '</div><script>(function(){var box=document.getElementById(' . json_encode($id) . ');if(!box)return;var select=box.querySelector(".ct-site-identity-locale");select.addEventListener("change",function(){box.querySelectorAll("[data-ct-site-locale]").forEach(function(field){field.style.display=field.dataset.ctSiteLocale===select.value?"block":"none"})})})()</script>';
}, 10, 1);

add_action('site_settings_after_collection_paths', function ($pdo, $input = []) {
    if (!$pdo instanceof PDO || !is_array($input) || !ct_user_can_workspace($pdo)
        || !user_can($pdo, ct_current_user_id(), 'core.settings.manage')) return;
    $locales = ct_enabled_locales($pdo);
    if ($locales === []) return;

    $paths = ct_collection_route_paths($pdo);
    $submitted = is_array($input['ct_collection_paths'] ?? null) ? $input['ct_collection_paths'] : [];
    $selected = is_string($input['ct_collection_paths_locale'] ?? null)
        && in_array($input['ct_collection_paths_locale'], $locales, true)
        ? $input['ct_collection_paths_locale']
        : $locales[0];
    $presets = function_exists('content_locale_presets') ? content_locale_presets() : [];
    $id = 'ct-collection-paths';

    echo '<div class="ct-collection-paths" id="' . $id . '">';
    echo '<div class="ct-collection-paths__heading"><strong>' . htmlspecialchars(__('Localized collection paths'), ENT_QUOTES) . '</strong><span class="field-note">' . htmlspecialchars(__('Set the Post and Page list paths used after each language prefix.'), ENT_QUOTES) . '</span></div>';
    echo '<label class="ct-collection-paths__selector" for="ct-collection-paths-locale">' . htmlspecialchars(__('Language'), ENT_QUOTES) . '<select class="inp inp-w100" id="ct-collection-paths-locale" name="ct_collection_paths_locale" data-unsaved-guard-ignore>';
    foreach ($locales as $locale) {
        $language = isset($presets[$locale]) ? __((string)$presets[$locale]) . ' (' . $locale . ')' : strtoupper($locale);
        echo '<option value="' . htmlspecialchars($locale, ENT_QUOTES) . '"' . ($locale === $selected ? ' selected' : '') . '>' . htmlspecialchars($language, ENT_QUOTES) . '</option>';
    }
    echo '</select></label>';
    foreach ($locales as $locale) {
        $submittedRow = is_array($submitted[$locale] ?? null) ? $submitted[$locale] : [];
        $postPath = is_string($submittedRow['posts'] ?? null)
            ? $submittedRow['posts']
            : (string)($paths[$locale]['posts'] ?? ct_collection_source_path($pdo, 'posts'));
        $pagePath = is_string($submittedRow['pages'] ?? null)
            ? $submittedRow['pages']
            : (string)($paths[$locale]['pages'] ?? ct_collection_source_path($pdo, 'pages'));
        $safeLocale = preg_replace('/[^a-zA-Z0-9_-]/', '-', $locale);
        echo '<fieldset class="ct-collection-paths__pane" data-ct-collection-locale="' . htmlspecialchars($locale, ENT_QUOTES) . '">';
        echo '<legend class="sr-only">' . htmlspecialchars(strtoupper($locale), ENT_QUOTES) . '</legend>';
        echo '<label for="ct-collection-posts-' . htmlspecialchars($safeLocale, ENT_QUOTES) . '">' . htmlspecialchars(__('Post list path'), ENT_QUOTES) . '<input class="inp inp-w100" id="ct-collection-posts-' . htmlspecialchars($safeLocale, ENT_QUOTES) . '" name="ct_collection_paths[' . htmlspecialchars($locale, ENT_QUOTES) . '][posts]" value="' . htmlspecialchars($postPath, ENT_QUOTES) . '"></label>';
        echo '<label for="ct-collection-pages-' . htmlspecialchars($safeLocale, ENT_QUOTES) . '">' . htmlspecialchars(__('Page list path'), ENT_QUOTES) . '<input class="inp inp-w100" id="ct-collection-pages-' . htmlspecialchars($safeLocale, ENT_QUOTES) . '" name="ct_collection_paths[' . htmlspecialchars($locale, ENT_QUOTES) . '][pages]" value="' . htmlspecialchars($pagePath, ENT_QUOTES) . '"></label>';
        echo '</fieldset>';
    }
    echo '</div><script>(function(){var box=document.getElementById(' . json_encode($id) . ');if(!box)return;var select=box.querySelector("#ct-collection-paths-locale");var panes=box.querySelectorAll("[data-ct-collection-locale]");function showLocale(){panes.forEach(function(pane){pane.hidden=pane.dataset.ctCollectionLocale!==select.value})}select.addEventListener("change",showLocale);showLocale()})()</script>';
}, 10, 2);

add_filter('post_list_status_expression', function ($expression, $context = []) {
    $pdo = $GLOBALS['pdo'] ?? null;
    if (!$pdo instanceof PDO) return $expression;
    $context = is_array($context) ? $context : [];
    if (!ct_admin_post_list_context($context)) return $expression;
    $localeSql = $pdo->quote(ct_admin_content_list_locale($pdo, $context));
    return "CASE
        WHEN {$localeSql} = ct_post_list_workflow.source_locale THEN ct_post_list_workflow.source_status
        WHEN ct_post_list_display.post_id IS NOT NULL THEN ct_post_list_display.status
        ELSE p.status
    END";
}, 10, 2);

add_filter('admin_content_row_actions', function ($items, $row, $context, $pdo) {
    if (!is_array($items) || !is_array($row) || !is_array($context) || !$pdo instanceof PDO) return $items;
    $type = (string)($context['content_type'] ?? '');
    if (!in_array($type, ['article', 'page', 'theme'], true)) return $items;

    $listContext = ['type' => $type];
    $locale = ct_admin_content_list_locale($pdo, $listContext, (int)($context['actor_id'] ?? 0));
    $default = function_exists('content_default_locale') ? content_default_locale() : 'en';
    if ($locale === $default) return $items;

    $post = $row;
    $post['type'] = $type;
    $post['created_by'] = (int)($row['owner_id'] ?? $row['created_by'] ?? 0);
    $actorId = (int)($context['actor_id'] ?? 0);
    if (!ct_user_can_view_post_representation($pdo, $post, $actorId)) return $items;

    $exists = (int)($row['ct_translation_id'] ?? 0) > 0;
    $canEdit = ct_user_can_edit_post_locale($pdo, $post, $locale, $actorId);
    $label = __($canEdit ? ($exists ? 'Edit' : 'Add') : 'View') . ' ' . strtoupper($locale);
    $query = [
        'page' => 'admin/tools/content-translation/edit',
        'post_id' => (int)($row['id'] ?? 0),
        'locale' => $locale,
    ];
    if (is_string($context['return_to'] ?? null) && $context['return_to'] !== '') $query['return_to'] = $context['return_to'];
    $items[] = [
        'key' => 'content-translation.' . strtolower($locale),
        'label' => $label,
        'url' => rtrim((string)ADMIN_BASE_PATH, '/') . '/?' . http_build_query($query),
        'title' => $label,
    ];
    return $items;
}, 10, 4);

add_filter('post_list_search_condition', function ($condition, $context = []) {
    $pdo = $GLOBALS['pdo'] ?? null;
    if (!$pdo instanceof PDO) return $condition;
    $context = is_array($context) ? $context : [];
    if (!ct_admin_post_list_context($context)) return $condition;
    return '(CASE WHEN ct_post_list_display.post_id IS NOT NULL THEN ct_post_list_display.title ELSE p.title END LIKE :search
        OR CASE WHEN ct_post_list_display.post_id IS NOT NULL THEN ct_post_list_display.slug ELSE p.slug END LIKE :search)';
}, 10, 2);

add_action('site_settings_after_save', function ($pdo, $input) {
    if (!$pdo instanceof PDO || !is_array($input) || !ct_user_can_workspace($pdo)
        || !user_can($pdo, ct_current_user_id(), 'core.settings.manage')) return;
    foreach (ct_enabled_locales($pdo) as $locale) {
        ct_save_site_translation($pdo, $locale, trim((string)($input['ct_site_title'][$locale] ?? '')), trim((string)($input['ct_site_description'][$locale] ?? '')));
    }
    if (is_array($input['ct_collection_paths'] ?? null)) {
        $paths = [];
        foreach (ct_enabled_locales($pdo) as $locale) {
            foreach (['posts', 'pages'] as $type) {
                $paths[$locale][$type] = trim((string)($input['ct_collection_paths'][$locale][$type] ?? ''), '/');
            }
        }
        settings_set($pdo, 'content_translation_collection_paths', json_encode($paths, JSON_UNESCAPED_SLASHES));
    }
}, 10, 2);

// ─── Translation picker in Core content editors ───
if (!function_exists('ct_render_editor_translation_picker')) {
    function ct_render_editor_translation_picker(array $post, PDO $pdo): void {
        if (!ct_user_can_view_post_representation($pdo, $post)) return;
        $id = (int)($post['id'] ?? 0);
        if ($id <= 0) return;

        $locales = ct_content_locales($pdo);
        if (empty($locales)) return;

        $base = defined('ADMIN_BASE_PATH') ? ADMIN_BASE_PATH : '/adiwira';
        $packageComposed = ($post['type'] ?? '') === 'theme'
            && ct_parse_theme_section_composition((string)($post['content'] ?? '')) !== null;
        $editUrl = $base . '/?page=admin/tools/content-translation/' . ($packageComposed ? 'theme-section-edit' : 'edit');
        $translations = ct_translations_for_post($pdo, $id);

        echo '<section class="ct-editor-translation-control">';
        echo '<label for="ct-editor-translation-locale">' . htmlspecialchars(__('Translations'), ENT_QUOTES) . '</label>';
        echo '<select id="ct-editor-translation-locale" onchange="if(this.value) window.location.href=this.value">';
        echo '<option value="">' . htmlspecialchars(__('Choose translation language…'), ENT_QUOTES) . '</option>';
        foreach ($locales as $locale) {
            if ($locale === ct_post_source_locale($pdo, $post)) continue;
            $translation = $translations[$locale] ?? null;
            $has = $translation !== null;
            $isDraft = $has && ($translation['status'] ?? 'published') === 'draft';
            $canEdit = ct_user_can_edit_post_locale($pdo, $post, $locale);
            $label = strtoupper($locale) . ' — ' . ($canEdit ? ($isDraft ? __('Draft') : ($has ? __('Edit') : __('Add'))) : __('View'));
            $url = $editUrl . '&post_id=' . $id . '&locale=' . urlencode($locale);
            echo '<option value="' . htmlspecialchars($url, ENT_QUOTES) . '">' . htmlspecialchars($label, ENT_QUOTES) . '</option>';
        }
        echo '</select>';
        echo '</section>';
    }
}

add_action('admin_content_readonly_actions', function ($post, $context, $pdo): void {
    if (!$pdo instanceof PDO || !is_array($post) || !is_array($context)) return;
    if (!in_array((string)($post['type'] ?? ''), ['article', 'page'], true)) return;
    ct_render_editor_translation_picker($post, $pdo);
}, 10, 3);

add_action('editor_mode_before_options', function ($post, $chosenMode) {
    if (!is_array($post)) return;
    if (!in_array((string)($post['type'] ?? ''), ['article', 'page'], true)) return;

    $pdo = $GLOBALS['pdo'] ?? null;
    if (!$pdo instanceof PDO) return;
    ct_render_editor_translation_picker($post, $pdo);
}, 10, 2);

add_action('theme_editor_before_content', function ($theme, $pdo) {
    if (!is_array($theme) || !$pdo instanceof PDO) return;
    if (($theme['type'] ?? '') !== 'theme') return;
    ct_render_editor_translation_picker($theme, $pdo);
}, 10, 2);

add_action('shortcode_layout_editor_after_header', function ($context, $pdo): void {
    if (!is_array($context) || !$pdo instanceof PDO
        || ($context['scope'] ?? '') !== 'section' || !empty($context['is_new'])
        || !ct_user_can_workspace($pdo)
        || !user_can($pdo, ct_current_user_id(), 'core.shortcode_layouts.manage')) {
        return;
    }
    $sectionName = is_string($context['name'] ?? null) ? trim($context['name']) : '';
    if (!function_exists('theme_section_name_is_valid') || !theme_section_name_is_valid($sectionName)) return;

    $base = defined('ADMIN_BASE_PATH') ? ADMIN_BASE_PATH : '/adiwira';
    $editorUrl = is_string($context['editor_url'] ?? null) ? $context['editor_url'] : '';
    $locales = ct_enabled_locales($pdo);
    try {
        $usages = ct_theme_section_template_usages($pdo, $sectionName);
    } catch (Throwable $error) {
        error_log('[content-translation] Theme Section usage lookup failed: ' . $error->getMessage());
        $usages = null;
    }

    echo '<section class="ct-layout-translation-panel">';
    echo '<div class="ct-layout-translation-heading"><div><strong>' . htmlspecialchars(__('Translations'), ENT_QUOTES, 'UTF-8') . '</strong>';
    echo '<span>' . htmlspecialchars(__('Translations belong to each Theme Template that uses this renderer. PHP remains the shared source for every language.'), ENT_QUOTES, 'UTF-8') . '</span></div>';
    echo '<code>' . htmlspecialchars($sectionName, ENT_QUOTES, 'UTF-8') . '</code></div>';
    $renderer = ct_theme_section_renderer_status($pdo, $sectionName);
    if (!$renderer['available']) {
        echo '<p class="ct-layout-translation-empty">' . htmlspecialchars(__('Translation packages require a physical renderer owned by the active theme. Fallback renderers from another theme, global renderers, and semantic Core fallbacks are unavailable.'), ENT_QUOTES, 'UTF-8') . '</p></section>';
        return;
    }
    if ($usages === null) {
        echo '<p class="ct-layout-translation-empty">' . htmlspecialchars(__('Theme Template usage could not be loaded.'), ENT_QUOTES, 'UTF-8') . '</p></section>';
        return;
    }
    if ($usages === []) {
        echo '<p class="ct-layout-translation-empty">' . htmlspecialchars(__('This renderer is not used by a package-composed Theme Template, so it has no translation target yet.'), ENT_QUOTES, 'UTF-8') . '</p></section>';
        return;
    }
    if ($locales === []) {
        echo '<p class="ct-layout-translation-empty">' . htmlspecialchars(__('No translation locales enabled.'), ENT_QUOTES, 'UTF-8') . '</p></section>';
        return;
    }

    echo '<div class="ct-layout-translation-usages">';
    foreach ($usages as $usage) {
        $postId = (int)$usage['id'];
        $resource = ct_theme_section_source_resource($pdo, $usage, false);
        $translations = ct_translations_for_post($pdo, $postId);
        $sourceUrl = $base . '/?page=admin/themes/edit&id=' . $postId;
        echo '<article class="ct-layout-translation-usage"><div class="ct-layout-translation-template">';
        echo '<span>' . htmlspecialchars(__('Used by Theme Template'), ENT_QUOTES, 'UTF-8') . '</span>';
        echo '<a href="' . htmlspecialchars($sourceUrl, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars((string)$usage['title'], ENT_QUOTES, 'UTF-8') . '</a>';
        echo '<code>' . htmlspecialchars((string)$usage['slug'], ENT_QUOTES, 'UTF-8') . '</code></div>';
        echo '<div class="ct-layout-translation-locales">';
        foreach ($locales as $locale) {
            $translation = $translations[$locale] ?? null;
            $package = $translation ? ct_decode_theme_section_package((string)($translation['content'] ?? '')) : null;
            $packageHasSection = is_array($package) && $resource !== null
                && (string)($package['theme_folder'] ?? '') === (string)$resource['theme_folder']
                && isset($package['sections'][$sectionName]);
            $status = !$translation ? 'empty' : (!$packageHasSection ? 'incomplete' : ((string)($translation['status'] ?? 'draft') === 'published' ? 'published' : 'draft'));
            $label = $status === 'empty' ? __('Add') : ($status === 'incomplete' ? __('Incomplete') : __($status === 'published' ? 'Published' : 'Draft'));
            $sourceState = '';
            if ($translation && $resource !== null) {
                $saved = ct_theme_section_saved_source_fingerprint($pdo, $postId, $locale);
                $sourceState = ct_theme_section_source_state($saved, (string)$resource['source_fingerprint']);
                if ($sourceState === 'stale') $label .= ' / ' . __('Stale source');
                elseif ($sourceState === 'unverified') $label .= ' / ' . __('Unverified source');
            }
            $query = [
                'page' => 'admin/tools/content-translation/theme-section-edit',
                'post_id' => $postId,
                'locale' => $locale,
                'section' => $sectionName,
            ];
            if ($editorUrl !== '') $query['return_to'] = $editorUrl;
            $url = $base . '/?' . http_build_query($query);
            $class = 'ct-layout-locale ct-layout-locale--' . $status;
            if ($resource === null) {
                echo '<span class="' . $class . '" aria-disabled="true"><b>' . htmlspecialchars(strtoupper($locale), ENT_QUOTES, 'UTF-8') . '</b><small>' . htmlspecialchars(__('Unavailable'), ENT_QUOTES, 'UTF-8') . '</small></span>';
            } else {
                echo '<a class="' . $class . '" href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '"><b>' . htmlspecialchars(strtoupper($locale), ENT_QUOTES, 'UTF-8') . '</b><small>' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</small></a>';
            }
        }
        echo '</div></article>';
    }
    echo '</div></section>';
}, 10, 2);

add_action('theme_zone_item_editor_actions', function ($item, $context, $pdo): void {
    if (!is_array($item) || !is_array($context) || !$pdo instanceof PDO
        || ($context['summary_available'] ?? false) === true) return;
    try {
        echo ct_theme_zone_translation_actions_html($item, $context, $pdo);
    } catch (Throwable $error) {
        error_log('[content-translation] Theme Zone editor actions failed: ' . $error->getMessage());
    }
}, 10, 3);

add_action('category_editor_after_fields', function ($category, $pdo) {
    if (!is_array($category) || !$pdo instanceof PDO) return;
    if (!ct_user_can_workspace($pdo)
        || !user_can($pdo, ct_current_user_id(), 'core.categories.update', ['owner_id' => (int)($category['created_by'] ?? 0)])) return;
    $locales = ct_enabled_locales($pdo);
    if (empty($locales)) return;
    $base = defined('ADMIN_BASE_PATH') ? ADMIN_BASE_PATH : '/adiwira';
    echo '<section class="ct-editor-translation-control"><label for="ct-category-translation-locale">' . htmlspecialchars(__('Translations'), ENT_QUOTES) . '</label><select id="ct-category-translation-locale" onchange="if(this.value) window.location.href=this.value"><option value="">' . htmlspecialchars(__('Choose translation language…'), ENT_QUOTES) . '</option>';
    foreach ($locales as $locale) {
        $translation = ct_get_category_translation($pdo, (int)$category['id'], $locale);
        $label = strtoupper($locale) . ' — ' . ($translation ? __('Edit') : __('Add'));
        $url = $base . '/?page=admin/tools/content-translation/category-edit&category_id=' . (int)$category['id'] . '&locale=' . urlencode($locale);
        echo '<option value="' . htmlspecialchars($url, ENT_QUOTES) . '">' . htmlspecialchars($label, ENT_QUOTES) . '</option>';
    }
    echo '</select></section>';
}, 10, 2);

add_action('profile_after_fields', function ($user, $pdo) {
    if (!is_array($user) || !$pdo instanceof PDO) return;
    if (!ct_user_can_workspace($pdo) || (int)($user['id'] ?? 0) !== ct_current_user_id()
        || !user_can($pdo, ct_current_user_id(), 'core.profile.manage')) return;
    $locales = ct_enabled_locales($pdo);
    if (empty($locales)) return;
    $id = 'ct-author-bio-' . (int)$user['id'];
    echo '<fieldset id="' . $id . '" style="margin-top:1rem;padding:12px;border:1px solid #ddd;border-radius:6px"><legend>' . htmlspecialchars(__('Bio translations'), ENT_QUOTES) . '</legend>';
    echo '<label>' . htmlspecialchars(__('Translation language'), ENT_QUOTES) . '<select class="ct-author-bio-locale" style="margin-left:8px">';
    foreach ($locales as $locale) {
        echo '<option value="' . htmlspecialchars($locale, ENT_QUOTES) . '">' . htmlspecialchars(strtoupper($locale), ENT_QUOTES) . '</option>';
    }
    echo '</select></label>';
    foreach ($locales as $index => $locale) {
        $translation = ct_get_author_profile_translation($pdo, (int)$user['id'], $locale);
        echo '<label data-locale="' . htmlspecialchars($locale, ENT_QUOTES) . '" style="display:' . ($index === 0 ? 'block' : 'none') . ';margin-top:8px">' . htmlspecialchars(__('Bio / About Me'), ENT_QUOTES) . '<textarea name="ct_author_bio[' . htmlspecialchars($locale, ENT_QUOTES) . ']" rows="4" style="width:100%;padding:.5rem;margin-top:.4rem;border:1px solid #ddd;border-radius:6px">' . htmlspecialchars((string)($translation['bio'] ?? ''), ENT_QUOTES) . '</textarea></label>';
    }
    echo '</fieldset><script>(function(){var box=document.getElementById(' . json_encode($id) . ');if(!box)return;var select=box.querySelector(".ct-author-bio-locale");select.addEventListener("change",function(){box.querySelectorAll("[data-locale]").forEach(function(field){field.style.display=field.dataset.locale===select.value?"block":"none"})})})()</script>';
}, 10, 2);

add_action('profile_after_save', function ($userId, $pdo, $input) {
    if (!$pdo instanceof PDO || !is_array($input)) return;
    if (!ct_user_can_workspace($pdo) || (int)$userId !== ct_current_user_id()
        || !user_can($pdo, ct_current_user_id(), 'core.profile.manage')) return;
    foreach ((array)($input['ct_author_bio'] ?? []) as $locale => $bio) {
        ct_save_author_profile_translation($pdo, (int)$userId, (string)$locale, trim((string)$bio));
    }
}, 10, 3);
