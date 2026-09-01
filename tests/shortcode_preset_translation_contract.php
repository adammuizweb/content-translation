<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$manifest = json_decode((string)file_get_contents($root . '/plugin.json'), true, 32, JSON_THROW_ON_ERROR);
$source = [
    'resource' => (string)file_get_contents($root . '/includes/shortcode-presets.php'),
    'helpers' => (string)file_get_contents($root . '/includes/helpers.php'),
    'bootstrap' => (string)file_get_contents($root . '/plugin.php'),
    'hub' => (string)file_get_contents($root . '/admin/index.php'),
    'list' => (string)file_get_contents($root . '/admin/shortcodes.php'),
    'editor' => (string)file_get_contents($root . '/admin/shortcode-edit.php'),
    'save' => (string)file_get_contents($root . '/admin/api/shortcode-save.php'),
    'export' => (string)file_get_contents($root . '/admin/export.php'),
    'uninstall' => (string)file_get_contents($root . '/plugin.php'),
];
$failures = [];
$check = static function (bool $condition, string $message) use (&$failures): void {
    echo ($condition ? 'PASS' : 'FAIL') . ' ' . $message . PHP_EOL;
    if (!$condition) $failures[] = $message;
};

$routes = [];
foreach ((array)($manifest['admin']['pages'] ?? []) as $route) $routes[(string)($route['route'] ?? '')] = $route;
foreach ([
    'admin/tools/content-translation/shortcodes',
    'admin/tools/content-translation/shortcode-edit',
    'admin/tools/content-translation/api/shortcode-save',
] as $route) {
    $check(isset($routes[$route]) && ($routes[$route]['permission'] ?? '') === 'plugin.content-translation.workspace.access', $route . ' uses workspace permission');
}
$check(str_contains($source['hub'], "admin/shortcodes/index&tab=presets") && str_contains($source['hub'], "__('Shortcode Presets')"), 'Content Translation hub starts from the Core Shortcode Presets source list');
$check(str_contains($source['list'], '$perPage = 20') && str_contains($source['list'], '$totalPages'), 'preset resource list has exact 20-row pagination');
$check(str_contains($source['list'], "LIKE :search ESCAPE '!'") && str_contains($source['list'], 'ct_shortcode_preset_like_pattern'), 'preset resource search is bounded, parameterized, and uses a SQL-mode-neutral LIKE escape');
$check(str_contains($source['list'], 'ct_shortcode_preset_translation_statuses') && str_contains($source['list'], 'ct-preset-locale'), 'preset resource list exposes per-locale statuses as direct controls');
$check(str_contains($source['list'], 'ct_user_can_workspace') && str_contains($source['editor'], 'core.shortcodes.update'), 'preset list and editor enforce plugin and Core permissions in depth');
$check(str_contains($source['save'], "REQUEST_METHOD") && str_contains($source['save'], "!== 'POST'"), 'preset mutation endpoint requires POST');
$check(str_contains($source['save'], 'ct_user_can_workspace') && str_contains($source['save'], 'core.shortcodes.update') && str_contains($source['save'], 'csrf_check'), 'preset mutation endpoint enforces dynamic permissions and CSRF in depth');
$check(str_contains($source['save'], "is_scalar(\$_POST['csrf_token']") && str_contains($source['save'], '$scalar = static function'), 'preset endpoint rejects non-scalar request values safely');
$check(substr_count($source['resource'], 'FOR UPDATE') >= 4 && substr_count($source['resource'], 'beginTransaction()') >= 3, 'save, delete, and lifecycle cleanup use transactions and row locks');
$check(str_contains($source['editor'], 'source_state') && str_contains($source['editor'], 'translation_state'), 'preset editor carries source and translation optimistic state');
$check(substr_count($source['resource'], 'hash_equals($loadedSourceState') === 2 && substr_count($source['resource'], 'hash_equals($loadedTranslationState') === 2, 'save and delete reject stale source and translation state under lock');
$check(str_contains($source['resource'], "return ['kicker']") && str_contains($source['resource'], "\$config['kicker'] = \$kicker"), 'runtime override allowlist contains only kicker');
$check(!str_contains($source['resource'], "\$config['layout'] =") && !str_contains($source['resource'], "\$config['limit'] ="), 'runtime cannot assign structural layout or query keys');
$check(str_contains($source['resource'], "shortcode_preset_editor_fields") && str_contains($source['resource'], 'ct-preset-translation-locale') && str_contains($source['resource'], 'Open translation'), 'Core preset editor visibly exposes source kicker and locale launcher');
$check(str_contains($source['resource'], "return_to=") && str_contains($source['editor'], 'adiwira_safe_return_to'), 'preset translation flow returns safely to the source preset editor');
$check(str_contains($source['editor'], 'Fetched Posts or Pages are translated through their existing Content Translation resources'), 'editor explains no-source-kicker content translation behavior');
$check(str_contains($source['resource'], "admin_shortcode_preset_before_delete") && !str_contains($source['resource'], "admin_shortcode_preset_after_delete") && str_contains($source['resource'], 'DELETE FROM shortcode_preset_translations WHERE preset_id = ?'), 'failure-propagating Core pre-delete hook cleans plugin-owned translations');
$check(str_contains($source['helpers'], 'CREATE TABLE IF NOT EXISTS shortcode_preset_translations') && str_contains($source['helpers'], 'UNIQUE KEY uniq_shortcode_preset_locale (preset_id, locale)'), 'plugin schema is idempotent and unique by preset and locale');
$check(str_contains($source['helpers'], "'version' => 4") && str_contains($source['helpers'], "'shortcode_preset_translations'"), 'versioned export includes Shortcode Preset translations');
$check(str_contains($source['bootstrap'], "'shortcode_preset_translations'") && str_contains($source['export'], '$requiredPermissions'), 'uninstall drops preset translations and export enforces broad permission matrix');
$check(str_contains($source['helpers'], "p.is_deleted = 0 ORDER BY spt.preset_id") && str_contains($source['list'], 'ct-repair-preset-orphans'), 'exports exclude deleted sources and admins can repair legacy orphans');
$check(str_contains($source['resource'], 'shortcode_preset_preview_config') && str_contains($source['resource'], 'shortcode-preset-preview-config') && str_contains($source['resource'], 'config.kicker=""'), 'all three source kicker states flow through Core live preview');
$check(str_contains($source['resource'], 'shortcode_preset_runtime_config') && str_contains($source['resource'], '$context = []'), 'runtime callback accepts optional Core render-time context');
$check(str_contains($source['list'], '$translatedTitle') && str_contains($source['resource'], "\$translation['title']"), 'translated management titles appear in the list and Core locale picker');
$check(str_contains($source['editor'], 'id="ct-shortcode-delete"') && str_contains($source['editor'], 'hidden = false'), 'delete becomes available after the first AJAX create');
$check(str_contains($source['editor'], 'NewNotifConfirm.danger') && !str_contains($source['editor'], 'window.confirm'), 'preset editor uses NewNotifConfirm without a native confirm fallback');
$check(str_contains($source['editor'], 'aria-live="polite"') && str_contains($source['editor'], 'Confirmation dialog is unavailable'), 'missing confirmation UI fails closed with an accessible visible notification');
$check(str_contains($source['editor'], "\$deleteReturnUrl") && str_contains($source['list'], "'translation_deleted' => __('Translation deleted.')") && !str_contains($source['list'], "\$_GET['flash']"), 'successful deletion returns to the source editor or an allowlisted accessible overview notice');
$check(str_contains($source['resource'], 'ct_ui_translation_seeds') && str_contains($source['resource'], 'AND value = ?'), 'owned seed updates preserve user-edited translation values');
$check(str_contains($source['resource'], 'DELETE FROM ui_translations WHERE scope = ? AND source = ? AND locale = ? AND value = ?') && str_contains($source['resource'], 'DELETE FROM ct_ui_translation_seeds WHERE scope = ? AND source_hash = ? AND locale = ?'), 'reseed prunes obsolete ownership and only matching owned UI values');
$check(str_contains($source['uninstall'], 'INNER JOIN ct_ui_translation_seeds') && str_contains($source['uninstall'], 'owned.value = ui.value'), 'uninstall deletes only unmodified plugin-owned UI seed rows');
$check(($manifest['version'] ?? '') === '1.13.0' && ($manifest['requires']['jyavani'] ?? '') === '>=2.3.81' && str_contains((string)($manifest['assets']['css'][0] ?? ''), 'v=1.13.0'), 'manifest keeps version/cache key and requires locale-aware search Core');

if ($failures !== []) {
    fwrite(STDERR, count($failures) . " assertion(s) failed.\n");
    exit(1);
}
echo "RESULT: ALL PASS\n";
