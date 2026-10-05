<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$manifest = json_decode((string)file_get_contents($root . '/plugin.json'), true, 64, JSON_THROW_ON_ERROR);
$admin = (string)file_get_contents($root . '/includes/admin.php');
$themeFiles = (string)file_get_contents($root . '/admin/index.php');
$themeStrings = (string)file_get_contents($root . '/admin/theme-strings.php');
$css = (string)file_get_contents($root . '/assets/css/translate.css');
$failures = [];
$check = static function (bool $condition, string $message) use (&$failures): void {
    echo ($condition ? 'PASS' : 'FAIL') . ' ' . $message . PHP_EOL;
    if (!$condition) $failures[] = $message;
};

$check(str_contains($admin, "add_action('theme_manager_theme_actions'")
    && str_contains($admin, "add_action('theme_source_editor_actions'")
    && str_contains($admin, "add_action('theme_source_editor_context'")
    && str_contains($admin, "add_action('theme_customize_actions'")
    && str_contains($admin, "add_action('theme_zone_item_summary'")
    && !str_contains($admin, "], 20, 3)") && !str_contains($admin, "], 20, 4)"),
    'plugin registers owner navigation and Customize workflow hooks through Core');
$check(str_contains($admin, "ct_user_can_integration(\$pdo, 'core.themes.manage'")
    && str_contains($admin, 'ct_user_is_site_owner($pdo, $actorId)')
    && ($manifest['permissions'][0]['key'] ?? null) === 'plugin.content-translation.workspace.access',
    'theme integration intersects workspace, Site Owner, and Core theme authority');
$check(str_contains($admin, 'theme-source-action-group--content-translation')
    && str_contains($admin, 'tm-action-group--content-translation')
    && str_contains($admin, 'ct-action-owner')
    && str_contains($admin, "'Customizer Text'") && str_contains($admin, "'PHP Interface Strings'"),
    'both Core surfaces receive explicit Content Translation owner groups');
$check(str_contains($admin, "is_array(\$context['slot_keys'] ?? null)")
    && str_contains($admin, 'ct_theme_file_resources($hookPdo, $folder)')
    && str_contains($admin, 'count($slots) >= 256')
    && str_contains($admin, 'isset($resourceSlots[$slot])')
    && str_contains($admin, "preg_match('/\\A[a-f0-9]{64}\\z/D'"),
    'source actions rebind the complete bounded Core slot set through one safe Theme File discovery');
$check(str_contains($admin, 'Frontend translation context')
    && str_contains($admin, 'Runtime source')
    && str_contains($admin, 'Theme Template')
    && str_contains($admin, 'Zone Gadget Text')
    && str_contains($admin, 'ct_theme_zone_translation_statuses_for_resources')
    && str_contains((string)file_get_contents($root . '/includes/helpers.php'), 'function ct_theme_zone_resources('),
    'Source Editor context resolves runtime ownership and bounded Theme Zone translation resources');
$check(str_contains($themeFiles, "\$_GET['theme_folder']")
    && str_contains($themeFiles, "\$_GET['slot_key']")
    && str_contains($themeFiles, 'adiwira_safe_return_to')
    && str_contains($themeFiles, "\$listQuery['return_to'] = \$returnTo")
    && str_contains($themeFiles, "'return_to' => \$listUrl"),
    'Customizer Text overview accepts bounded filters and preserves layered return navigation');
$check(str_contains($themeStrings, 'adiwira_safe_return_to')
    && str_contains($themeStrings, 'name="return_to"')
    && str_contains($themeStrings, "'return_to' => \$listUrl")
    && str_contains($themeStrings, 'PHP Interface Strings'),
    'PHP Interface String navigation preserves list and validated Core return targets');
$check(str_contains($admin, 'Homepage frontend source')
    && str_contains($admin, 'Stored Customizer translations')
    && str_contains($admin, 'ct_theme_file_runtime_state')
    && str_contains($admin, 'ct_theme_zone_translation_actions_html'),
    'Customize explains runtime ownership and exposes persistent locale actions');
$check(str_contains($themeFiles, 'ct_theme_file_runtime_state')
    && str_contains($themeFiles, 'Not used on frontend')
    && str_contains($themeFiles, 'ct-locale-chip')
    && str_contains($themeStrings, 'ct-locale-chip'),
    'theme translation lists expose visible locale versions and runtime usage');
$check(str_contains($css, '.tm-action-group--content-translation')
    && str_contains($css, '.theme-source-action-group--content-translation')
    && str_contains($css, '.theme-source-action-group--content-translation .theme-source-action')
    && str_contains($css, 'display: flex')
    && str_contains($css, '.ct-theme-action') && str_contains($css, '.ct-theme-source-action')
    && str_contains($css, 'html.theme-dark .theme-source-action-group--content-translation'),
    'Content Translation actions have owner-scoped teal styling in light and dark themes');
$check(str_contains($css, '.ct-customize-guide')
    && str_contains($css, '.ct-source-context')
    && str_contains($css, '.ct-back-link .lucide-icon')
    && str_contains($css, '.ct-locale-chip--published')
    && str_contains($css, '.ct-theme-zone-summary')
    && str_contains($css, ':not(.ct-theme-strings-table)')
    && str_contains($themeStrings, 'ct-table-scroll'),
    'Customize workflow and responsive PHP string locale controls have complete styling');
$check(str_contains((string)file_get_contents($root . '/plugin.json'), 'content-translation.css?v=1.20.3-ordinary-core-content'),
    'compact list-language stylesheet uses a cache-distinct asset URL');
$themeBuilderReferences = [];
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($iterator as $file) {
    if (!$file->isFile() || $file->getExtension() !== 'php' || str_contains($file->getPathname(), '/tests/')) continue;
    $runtimeSource = strtolower((string)file_get_contents($file->getPathname()));
    if (str_contains($runtimeSource, 'theme-builder') || str_contains($runtimeSource, 'themebuilder')) {
        $themeBuilderReferences[] = substr($file->getPathname(), strlen($root) + 1);
    }
}
$check(!isset($manifest['requires']['plugins']['theme-builder'])
    && !isset($manifest['dependencies']['plugins']['theme-builder'])
    && $themeBuilderReferences === [],
    'Content Translation integration has no Theme Builder manifest or runtime dependency');

if ($failures !== []) {
    fwrite(STDERR, implode('; ', $failures) . PHP_EOL);
    exit(1);
}
echo "Content Translation Core theme integration contract passed.\n";
