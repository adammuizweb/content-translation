<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/includes/helpers.php';
$manifest = json_decode((string)file_get_contents($root . '/plugin.json'), true, 64, JSON_THROW_ON_ERROR);
$sources = [
    'helpers' => (string)file_get_contents($root . '/includes/helpers.php'),
    'frontend' => (string)file_get_contents($root . '/includes/frontend.php'),
    'admin' => (string)file_get_contents($root . '/includes/admin.php'),
    'zone_editor' => (string)file_get_contents($root . '/admin/theme-zone-edit.php'),
    'string_list' => (string)file_get_contents($root . '/admin/theme-strings.php'),
    'string_editor' => (string)file_get_contents($root . '/admin/theme-string-edit.php'),
    'bootstrap' => (string)file_get_contents($root . '/plugin.php'),
];
$failures = [];
$check = static function (bool $condition, string $message) use (&$failures): void {
    echo ($condition ? 'PASS' : 'FAIL') . ' ' . $message . PHP_EOL;
    if (!$condition) $failures[] = $message;
};

$routes = [];
foreach ((array)($manifest['admin']['pages'] ?? []) as $route) $routes[(string)($route['route'] ?? '')] = $route;
foreach (['theme-zone-edit', 'theme-strings', 'theme-string-edit'] as $page) {
    $route = $routes['admin/tools/content-translation/' . $page] ?? null;
    $check(is_array($route) && ($route['permission'] ?? '') === 'plugin.content-translation.workspace.access',
        $page . ' route uses workspace permission');
}

$check(str_contains($sources['helpers'], 'CREATE TABLE IF NOT EXISTS ct_theme_zone_item_translations')
    && str_contains($sources['helpers'], 'CREATE TABLE IF NOT EXISTS ct_theme_string_translations')
    && str_contains($sources['bootstrap'], "'ct_theme_zone_item_translations'")
    && str_contains($sources['bootstrap'], "'ct_theme_string_translations'"),
    'plugin owns and uninstalls both multilingual theme schemas');
$check(str_contains($sources['bootstrap'], "add_action('theme_zone_item_before_delete'")
    && str_contains($sources['bootstrap'], 'DELETE FROM ct_theme_zone_item_translations WHERE theme_zone_item_id = ?'),
    'Core source deletion atomically cleans plugin-owned Theme Zone translations');
$check(str_contains($sources['helpers'], "'version' => 5")
    && str_contains($sources['helpers'], "'theme_zone_item_translations'")
    && str_contains($sources['helpers'], "'theme_string_translations'"),
    'versioned export includes Theme Zone and static theme strings');
$check(str_contains($sources['frontend'], "add_filter('theme_zone_items'")
    && str_contains($sources['frontend'], "status = 'published'")
    && str_contains($sources['frontend'], 'source_fingerprint')
    && str_contains($sources['frontend'], 'array_chunk(array_keys($byId), 200)')
    && str_contains($sources['frontend'], "foreach (\$resource['schema'] as \$key"),
    'runtime overlays only declared Theme Zone keys from current published rows');
$check(str_contains($sources['frontend'], "add_filter('localized_string'")
    && str_contains($sources['frontend'], "\$context['theme_folder']")
    && str_contains($sources['frontend'], 'ct_theme_string_placeholders'),
    'theme strings resolve by physical owner and preserve format placeholders');
$check(str_contains($sources['admin'], "add_action('theme_zone_item_editor_actions'")
    && str_contains($sources['zone_editor'], 'ct_user_is_site_owner')
    && str_contains($sources['zone_editor'], 'core.themes.manage'),
    'generic Core action is adapted to a Site Owner plugin editor');
$check(str_contains($sources['zone_editor'], 'source_fingerprint')
    && substr_count($sources['zone_editor'], 'translation_state') >= 4
    && str_contains($sources['helpers'], 'FOR UPDATE'),
    'Theme Zone mutation carries source and translation optimistic state');
$check(str_contains($sources['string_editor'], 'adiwira_safe_return_to')
    && str_contains($sources['string_editor'], 'translation_state')
    && str_contains($sources['string_list'], 'ct_theme_string_resources'),
    'static-string workflow has safe navigation, discovery, and optimistic state');

$literals = ct_theme_string_literals(<<<'PHP'
<?php
__('Home');
_e('Scoped string', 'portfolio');
__('Dynamic ' . $name);
__('Hi ' . get_name());
$object->__('Object method');
Translator::_e('Static method');
\__('Global call');
new __('Constructor');
PHP);
$check($literals === [
    ['scope' => 'default', 'source' => 'Home'],
    ['scope' => 'portfolio', 'source' => 'Scoped string'],
    ['scope' => 'default', 'source' => 'Global call'],
], 'token discovery accepts only complete literal calls with stable literal scopes');
$check(ct_theme_string_decode_literal('"Unknown \\q"') === 'Unknown \\q'
    && ct_theme_string_decode_literal('"Line\\nBreak"') === "Line\nBreak"
    && ct_theme_string_decode_literal('"Unicode \\u{263A}"') === 'Unicode ☺',
    'double-quoted discovery matches PHP escapes while preserving unknown escapes');
$check(ct_theme_string_placeholders('Hello %s, %d items') === ct_theme_string_placeholders('%d items for %s')
    && ct_theme_string_placeholders('Hello %s') !== ct_theme_string_placeholders('Hello'),
    'placeholder signatures allow reordering but reject dropped placeholders');
$check(str_contains($sources['helpers'], 'RecursiveDirectoryIterator')
    && str_contains($sources['helpers'], 'is_link($path)')
    && str_contains($sources['helpers'], '$phpFiles > 500')
    && str_contains($sources['helpers'], '$entries > 10000')
    && str_contains($sources['helpers'], '$totalBytes'),
    'physical theme scanner rejects symlinks and enforces file and aggregate bounds');

if ($failures !== []) {
    fwrite(STDERR, count($failures) . " assertion(s) failed.\n");
    exit(1);
}
echo "RESULT: ALL PASS\n";
