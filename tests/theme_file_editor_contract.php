<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$manifest = json_decode((string)file_get_contents($root . '/plugin.json'), true, 32, JSON_THROW_ON_ERROR);
$editor = (string)file_get_contents($root . '/admin/theme-file-edit.php');
$overview = (string)file_get_contents($root . '/admin/index.php');
$failures = [];
$check = static function (bool $condition, string $message) use (&$failures): void {
    echo ($condition ? 'PASS' : 'FAIL') . ' ' . $message . PHP_EOL;
    if (!$condition) $failures[] = $message;
};

$routes = [];
foreach ((array)($manifest['admin']['pages'] ?? []) as $route) $routes[(string)($route['route'] ?? '')] = $route;
$route = $routes['admin/tools/content-translation/theme-file-edit'] ?? null;
$check(is_array($route) && ($route['permission'] ?? '') === 'plugin.content-translation.workspace.access',
    'Theme File editor route uses workspace permission');
$check(str_contains($editor, "\$_POST['return_to'] ?? \$_GET['return_to']")
    && str_contains($editor, 'adiwira_safe_return_to($returnToInput, $overviewUrl)')
    && !str_contains($editor, '$_REQUEST'),
    'POST-preferred return target is validated by the shared Core helper');
$check(str_contains($editor, "'return_to' => \$listUrl") && str_contains($editor, 'http_build_query'),
    'editor redirects preserve the validated return target through structured query encoding');
$check(substr_count($editor, 'name="return_to" value="<?= h($listUrl) ?>"') === 2,
    'save and delete forms both propagate the validated return target');
$check(str_contains($editor, '$redirect($deleteReturnUrl)')
    && str_contains($editor, '$listUrl === $overviewUrl')
    && !str_contains($editor, '$redirect($listUrl .'),
    'delete returns to an external owner workspace without appending plugin-specific query data');
$check(substr_count($editor, '$redirect($editorUrl') >= 3,
    'save and mutation errors stay in the editor while preserving its return target');
$check(str_contains($editor, 'href="<?= h($listUrl) ?>"')
    && substr_count($editor, "svg_ico('arrow-left')") >= 2
    && str_contains($editor, 'Back to Customizer Text')
    && str_contains($editor, 'Back to Source Editor')
    && str_contains($overview, 'class="btn ct-back-link"')
    && str_contains($overview, "svg_ico('arrow-left')")
    && str_contains($overview, 'Back to Theme Manager'),
    'visible Back navigation uses Core Lucide icons, contextual labels, and validated return targets');
$check(str_contains($editor, '$scalar = static fn(mixed $value)')
    && !str_contains($editor, "trim((string)(\$_GET['theme_folder']"),
    'resource identity rejects non-scalar request values without conversion warnings');
$check(str_contains($editor, 'ct_user_is_site_owner'),
    'Theme File HTML-capable values remain restricted to the Site Owner');
$check(str_contains((string)file_get_contents($root . '/includes/helpers.php'), 'ct_theme_root($pdo, $folder) === null'),
    'Theme File discovery rejects unregistered, escaping, and symlink-backed theme folders');
$check(str_contains($overview, "\$_GET['theme_folder']") && str_contains($overview, "\$_GET['slot_key']")
    && str_contains($overview, 'ct_theme_file_resource_id($themeFolder, $slotFilter)')
    && str_contains($overview, 'adiwira_safe_return_to'),
    'Theme File overview supports exact folder and slot navigation from Core');
$check(str_contains($overview, "\$listQuery['return_to'] = \$returnTo")
    && str_contains($overview, "'return_to' => \$listUrl")
    && str_contains($overview, 'href="<?= h($returnTo) ?>"'),
    'Customizer Text editors return to their resource list before the validated Core origin');
$check(str_contains($overview, 'ct_theme_file_runtime_state')
    && str_contains($overview, 'Not used on frontend')
    && str_contains($editor, '$runtimeState = ct_theme_file_runtime_state')
    && str_contains($editor, 'Frontend language versions'),
    'Customizer Text list and editor distinguish stored translations from the active frontend source');

if ($failures !== []) {
    fwrite(STDERR, count($failures) . " assertion(s) failed.\n");
    exit(1);
}
echo "RESULT: ALL PASS\n";
