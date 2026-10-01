<?php
declare(strict_types=1);

define('ADMIN_BASE_PATH', '/control');
$capturedActions = [];
$integrationAllowed = true;
$siteOwner = true;

function add_action(string $hook, callable $callback, int $priority = 10, int $acceptedArgs = 1): void {
    global $capturedActions;
    $capturedActions[$hook][] = $callback;
}
function add_filter(string $hook, callable $callback, int $priority = 10, int $acceptedArgs = 1): void {}
function __(string $value): string { return $value === 'Content Translation' ? '<Translation & Team>' : $value; }
function ct_current_user_id(): int { return 7; }
function ct_user_can_workspace(PDO $pdo, ?int $actorId = null): bool {
    global $integrationAllowed;
    return $integrationAllowed && ($actorId === null || $actorId === 7);
}
function ct_user_can_integration(PDO $pdo, string $permission, ?int $actorId = null): bool {
    global $integrationAllowed;
    return $integrationAllowed && $permission === 'core.themes.manage' && $actorId === 7;
}
function ct_user_is_site_owner(PDO $pdo, ?int $actorId = null): bool {
    global $siteOwner;
    return $siteOwner && ($actorId === null || $actorId === 7);
}
function user_can(PDO $pdo, int $actorId, string $permission): bool {
    global $integrationAllowed;
    return $integrationAllowed && $actorId === 7 && $permission === 'core.themes.manage';
}
function adiwira_safe_return_to(mixed $candidate, string $fallback): string {
    return is_string($candidate) && str_starts_with($candidate, '/control/') ? $candidate : $fallback;
}
function ct_theme_file_resources(PDO $pdo, ?string $folder = null): array {
    return $folder === 'safe-theme' ? ['safe-theme:main.homepage' => ['id' => 'safe-theme:main.homepage', 'theme_folder' => $folder, 'slot_key' => 'main.homepage']] : [];
}
function ct_theme_file_translation_statuses(PDO $pdo, array $resources): array {
    return ['safe-theme:main.homepage' => ['id' => 'published', 'de' => 'draft']];
}
function ct_enabled_locales(PDO $pdo): array { return ['id', 'de']; }
function content_default_locale(): string { return 'en'; }
function ct_homepage_theme_post(PDO $pdo): array { return ['id' => 42, 'title' => 'Homepage', 'content' => '[[theme_section name="home.hero"]]']; }
function ct_parse_theme_section_composition(string $content): array { return [['name' => 'home.hero']]; }
function ct_get_translation(PDO $pdo, int $postId, string $locale): ?array {
    return match ($locale) {
        'de' => ['status' => 'published', 'complete' => true],
        'id' => ['status' => 'published', 'complete' => false],
        default => null,
    };
}
function ct_theme_file_runtime_state(PDO $pdo, array $resource): array {
    return ['active' => false, 'type' => 'theme_template', 'label' => 'Homepage', 'post_id' => 42];
}
function resolve_template(PDO $pdo, string $slot): array {
    if ($slot === 'main.homepage') return ['type' => 'custom_post', 'post' => ct_homepage_theme_post($pdo)];
    if ($slot === 'footer') return ['type' => 'theme_file', 'theme_folder' => 'safe-theme', 'theme_file' => 'footer.php'];
    return ['type' => 'unavailable'];
}
function ct_theme_zone_resources(PDO $pdo, string $folder, ?string $zone = null, bool $activeOnly = true, int $limit = 128): array {
    if ($folder !== 'safe-theme' || ($zone !== null && $zone !== 'footer')) return [];
    return [[
        'id' => 9,
        'theme_folder' => 'safe-theme',
        'zone_slug' => 'footer',
        'position' => 'copyright',
        'title' => 'Copyright',
        'source_values' => ['html' => '<p>Footer source</p>'],
        'source_fingerprint' => str_repeat('a', 64),
    ]];
}
function ct_theme_zone_translation_statuses_for_resources(PDO $pdo, array $resources): array {
    return [9 => ['de' => 'published']];
}
function ct_theme_zone_resource_from_row(array $item): ?array {
    return [
        'id' => (int)$item['id'],
        'theme_folder' => (string)($item['theme_folder'] ?? ''),
        'zone_slug' => (string)($item['zone_slug'] ?? ''),
        'position' => (string)($item['position'] ?? ''),
        'source_values' => ['html' => (string)($item['source'] ?? '')],
    ];
}
function ct_theme_zone_translation_statuses(PDO $pdo, int $itemId, ?array $resource = null): array { return ['de' => 'published']; }
function ct_post_translation_is_complete(PDO $pdo, array $translation): bool { return ($translation['complete'] ?? false) === true; }
require dirname(__DIR__) . '/includes/admin.php';

$pdo = new PDO('sqlite::memory:');
$GLOBALS['pdo'] = $pdo;
$theme = ['folder_name' => 'safe-theme'];
$managerContext = ['actor_id' => 7, 'folder' => 'safe-theme', 'is_active' => true, 'admin_base_path' => '/control', 'return_url' => '/control/?page=admin/themes/assign'];
$sourceContext = [
    'actor_id' => 7,
    'folder' => 'safe-theme',
    'admin_base_path' => '/control',
    'relative_path' => 'main/homepage.php',
    'slot_keys' => ['main.homepage'],
    'file_id' => str_repeat('a', 64),
    'return_url' => '/control/?page=admin/themes/source&folder=safe-theme&file=' . str_repeat('a', 64),
];
$footerSourceContext = [
    'actor_id' => 7,
    'folder' => 'safe-theme',
    'admin_base_path' => '/control',
    'relative_path' => 'footer.php',
    'slot_keys' => ['footer'],
    'file_id' => str_repeat('b', 64),
    'active' => true,
    'return_url' => '/control/?page=admin/themes/source&folder=safe-theme&file=' . str_repeat('b', 64),
];
$customizeContext = [
    'actor_id' => 7,
    'folder' => 'safe-theme',
    'admin_base_path' => '/control',
    'return_url' => '/control/?page=admin/themes/customize',
];
$zoneItem = ['id' => 9, 'theme_folder' => 'safe-theme', 'zone_slug' => 'footer', 'position' => 'copyright', 'active' => 1, 'source' => '<p>Footer source</p>'];
$zoneContext = ['theme_folder' => 'safe-theme', 'zone_slug' => 'footer', 'position' => 'copyright', 'return_url' => '/control/?page=admin/themes/customize&edit=9'];
$render = static function (string $hook, array $args) use (&$capturedActions): string {
    ob_start();
    foreach ($capturedActions[$hook] ?? [] as $callback) $callback(...$args);
    return (string)ob_get_clean();
};
$failures = [];
$check = static function (bool $condition, string $message) use (&$failures): void {
    echo ($condition ? 'PASS' : 'FAIL') . ' ' . $message . PHP_EOL;
    if (!$condition) $failures[] = $message;
};

$manager = $render('theme_manager_theme_actions', [$theme, [], $managerContext]);
$check(str_contains($manager, 'tm-action-group--content-translation')
    && str_contains($manager, '&lt;Translation &amp; Team&gt;')
    && str_contains($manager, 'theme-files') && str_contains($manager, 'theme-strings')
    && str_contains($manager, 'Zone Gadget Text') && str_contains($manager, 'admin%2Fthemes%2Fcustomize'),
    'authorized Theme Manager hook emits escaped owner-labelled actions');

$source = $render('theme_source_editor_actions', [$theme, $sourceContext, $pdo]);
$check(str_contains($source, 'theme-source-action-group--content-translation')
    && str_contains($source, 'slot_key=main.homepage')
    && str_contains($source, 'relative_path') === false,
    'authorized Source Editor hook emits only rebound resource navigation');
$footerActions = $render('theme_source_editor_actions', [$theme, $footerSourceContext, $pdo]);
$check(str_contains($footerActions, 'Zone Gadget Text') && str_contains($footerActions, 'edit=9'),
    'active Theme Zone source actions navigate back to the exact gadget editor');

$sourceContextPanel = $render('theme_source_editor_context', [$theme, $sourceContext, $pdo]);
$check(str_contains($sourceContextPanel, 'ct-source-context')
    && str_contains($sourceContextPanel, 'Theme Template: Homepage')
    && str_contains($sourceContextPanel, 'Stored but not used by the current frontend assignment')
    && str_contains($sourceContextPanel, 'theme-file-edit')
    && str_contains($sourceContextPanel, 'theme-section-edit')
    && str_contains($sourceContextPanel, '>ID<') && str_contains($sourceContextPanel, 'Published'),
    'Source Editor context identifies a Theme Template runtime owner and shadowed Customizer text');
$footerContextPanel = $render('theme_source_editor_context', [$theme, $footerSourceContext, $pdo]);
$check(str_contains($footerContextPanel, 'Runtime source: Selected PHP file')
    && str_contains($footerContextPanel, 'Zone Gadget Text')
    && str_contains($footerContextPanel, 'Copyright - copyright')
    && str_contains($footerContextPanel, 'theme-zone-edit')
    && str_contains($footerContextPanel, '>ID<') && str_contains($footerContextPanel, 'Add')
    && str_contains($footerContextPanel, '>DE<') && str_contains($footerContextPanel, 'Published'),
    'Source Editor context links active footer gadget source and visitor-language versions');

$customize = $render('theme_customize_actions', [$theme, $customizeContext, $pdo]);
$check(str_contains($customize, 'ct-customize-guide')
    && str_contains($customize, 'Homepage frontend source')
    && str_contains($customize, 'theme-section-edit')
    && str_contains($customize, 'Stored Customizer translations')
    && str_contains($customize, 'not used by the current frontend assignment')
    && str_contains($customize, 'Incomplete') && str_contains($customize, 'Published'),
    'Customize guide identifies effective homepage status and shadowed Customizer storage');

$summary = $render('theme_zone_item_summary', [$zoneItem, $zoneContext, $pdo]);
$check(str_contains($summary, 'ct-theme-zone-summary')
    && str_contains($summary, '>EN<')
    && str_contains($summary, '>ID<') && str_contains($summary, 'Add')
    && str_contains($summary, '>DE<') && str_contains($summary, 'Published'),
    'collapsed gadget summary exposes source and visitor-language versions');
$check($render('theme_zone_item_editor_actions', [$zoneItem, ['summary_available' => true] + $zoneContext, $pdo]) === ''
    && str_contains($render('theme_zone_item_editor_actions', [$zoneItem, $zoneContext, $pdo]), 'ct-theme-zone-summary'),
    'expanded editor suppresses duplicate controls while retaining the older-Core fallback');
$check($render('theme_zone_item_summary', [array_replace($zoneItem, ['active' => 0]), $zoneContext, $pdo]) === ''
    && $render('theme_zone_item_summary', [$zoneItem, array_replace($zoneContext, ['position' => 'other']), $pdo]) === '',
    'gadget summaries suppress inactive and context-mismatched resources');

$integrationAllowed = false;
$check($render('theme_manager_theme_actions', [$theme, [], $managerContext]) === ''
    && $render('theme_source_editor_actions', [$theme, $sourceContext, $pdo]) === ''
    && $render('theme_source_editor_context', [$theme, $sourceContext, $pdo]) === ''
    && $render('theme_customize_actions', [$theme, $customizeContext, $pdo]) === ''
    && $render('theme_zone_item_summary', [$zoneItem, $zoneContext, $pdo]) === '',
    'all Core theme integrations emit nothing when Core theme permission is denied');
$integrationAllowed = true;
$siteOwner = false;
$check($render('theme_manager_theme_actions', [$theme, [], $managerContext]) === ''
    && $render('theme_source_editor_actions', [$theme, $sourceContext, $pdo]) === ''
    && $render('theme_source_editor_context', [$theme, $sourceContext, $pdo]) === ''
    && $render('theme_customize_actions', [$theme, $customizeContext, $pdo]) === ''
    && $render('theme_zone_item_summary', [$zoneItem, $zoneContext, $pdo]) === '',
    'all Core theme integrations emit nothing outside the Site Owner boundary');

if ($failures !== []) {
    fwrite(STDERR, count($failures) . " assertion(s) failed.\n");
    exit(1);
}
echo "RESULT: ALL PASS\n";
