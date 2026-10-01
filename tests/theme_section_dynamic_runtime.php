<?php
declare(strict_types=1);

$filters = [];
$actions = [];
function add_filter(string $hook, callable $callback, int $priority = 10, int $acceptedArgs = 1): void { global $filters; $filters[$hook][$priority][] = [$callback, $acceptedArgs]; }
function add_action(string $hook, callable $callback, int $priority = 10, int $acceptedArgs = 1): void { global $actions; $actions[$hook][$priority][] = [$callback, $acceptedArgs]; }
function theme_section_name_is_valid(string $name): bool { return preg_match('/\A[a-z][a-z0-9.-]*\z/', $name) === 1; }
function theme_section_safe_url(mixed $value): string { return (string)$value; }
function theme_section_path_is_within(string $path, string $root): bool { return $path === $root || str_starts_with($path, rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR); }
function get_active_theme_folder(?PDO $pdo = null): string { return (string)$GLOBALS['ct_test_theme_folder']; }
function theme_section_theme_directory(?PDO $pdo = null, bool $create = false, ?string $folder = null): ?string { return $GLOBALS['ct_test_theme_roots'][$folder ?? get_active_theme_folder($pdo)] ?? null; }
function theme_section_resolve_layout(string $name, ?PDO $pdo = null): ?string { return $GLOBALS['ct_test_layouts'][$name] ?? null; }

define('DEFAULT_THEME_FOLDER', 'default');
$root = sys_get_temp_dir() . '/ct-theme-section-' . bin2hex(random_bytes(6));
$activeRoot = $root . '/custom/partials/shortcodes/section';
$fallbackRoot = $root . '/default/partials/shortcodes/section';
mkdir($activeRoot, 0777, true);
mkdir($fallbackRoot, 0777, true);
$dynamicPath = $activeRoot . '/live.php';
$staticPath = $activeRoot . '/static.php';
$fallbackPath = $fallbackRoot . '/fallback.php';
file_put_contents($dynamicPath, '<?php echo render_shortcode_preset($pdo, "news"); echo render_widget("search");');
file_put_contents($staticPath, '<section>Static</section>');
file_put_contents($fallbackPath, '<section>Fallback</section>');
$GLOBALS['ct_test_theme_folder'] = 'custom';
$GLOBALS['ct_test_theme_roots'] = ['custom' => $activeRoot, 'default' => $fallbackRoot];
$GLOBALS['ct_test_layouts'] = ['live' => $dynamicPath, 'static' => $staticPath, 'fallback' => $fallbackPath];

require dirname(__DIR__) . '/includes/theme-section-packages.php';

$failures = [];
$check = static function (bool $condition, string $message) use (&$failures): void {
    echo ($condition ? 'PASS' : 'FAIL') . ' ' . $message . PHP_EOL;
    if (!$condition) $failures[] = $message;
};
$pdo = (new ReflectionClass(PDO::class))->newInstanceWithoutConstructor();
$check(ct_theme_section_renderer_status($pdo, 'live')['dynamic'] === true, 'active-theme renderer detects trusted live Preset and widget calls');
$check(ct_theme_section_renderer_status($pdo, 'static') === ['available' => true, 'dynamic' => false, 'reason' => '', 'path' => realpath($staticPath)], 'active-theme static renderer is package eligible');
$check(ct_theme_section_renderer_status($pdo, 'fallback')['available'] === false, 'resolved default fallback renderer is rejected');
$GLOBALS['ct_test_theme_folder'] = 'default';
$check(ct_theme_section_renderer_status($pdo, 'fallback')['available'] === true, 'physical default-theme renderer is eligible when default is the active owner');
$GLOBALS['ct_test_theme_folder'] = 'custom';

$package = [
    'theme_folder' => 'custom',
    'sections' => ['live' => ['html' => '<section>Stored snapshot</section>', 'fallback' => [
        'title' => 'Translated title', 'summary' => 'Translated summary', 'url' => '/translated', 'link_label' => 'Read',
    ]], 'static' => ['html' => '<section>Stored static translation</section>', 'fallback' => [
        'title' => 'Static title', 'summary' => 'Static summary', 'url' => '', 'link_label' => '',
    ]]],
];
$context = ['post' => ['ct_theme_section_package' => $package]];
$attrsFilter = $filters['theme_section_attrs'][20][0][0];
$htmlFilter = $filters['theme_section_html'][20][0][0];
$attrs = $attrsFilter(['title' => 'Source'], 'live', [], $context);
$fresh = '<section data-page="2">Fresh query result</section>';
$rendered = $htmlFilter($fresh, 'live', $attrs, $context, $pdo, $dynamicPath);
$check(($attrs['title'] ?? '') === 'Translated title' && ($attrs['summary'] ?? '') === 'Translated summary', 'dynamic renderer still receives translated semantic attributes');
$check($rendered === $fresh, 'dynamic renderer keeps fresh live HTML instead of the stored snapshot');
$staticRendered = $htmlFilter($fresh, 'static', $attrs, $context, $pdo, $staticPath);
$check($staticRendered === '<section>Stored static translation</section>', 'eligible static renderer still uses translated snapshot HTML');

unlink($dynamicPath);
unlink($staticPath);
unlink($fallbackPath);
rmdir($activeRoot);
rmdir(dirname($activeRoot));
rmdir(dirname(dirname($activeRoot)));
rmdir(dirname(dirname(dirname($activeRoot))));
rmdir($fallbackRoot);
rmdir(dirname($fallbackRoot));
rmdir(dirname(dirname($fallbackRoot)));
rmdir(dirname(dirname(dirname($fallbackRoot))));
rmdir($root);

if ($failures !== []) exit(1);
echo "RESULT: ALL PASS\n";
