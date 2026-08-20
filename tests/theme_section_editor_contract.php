<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$manifest = json_decode((string)file_get_contents($root . '/plugin.json'), true, 32, JSON_THROW_ON_ERROR);
$sources = [
    'save' => (string)file_get_contents($root . '/admin/api/theme-section-save.php'),
    'generic_save' => (string)file_get_contents($root . '/admin/api/save.php'),
    'generic_edit' => (string)file_get_contents($root . '/admin/edit.php'),
    'delete' => (string)file_get_contents($root . '/admin/api/delete.php'),
    'packages' => (string)file_get_contents($root . '/includes/theme-section-packages.php'),
    'helpers' => (string)file_get_contents($root . '/includes/helpers.php'),
    'admin' => (string)file_get_contents($root . '/includes/admin.php'),
    'list' => (string)file_get_contents($root . '/admin/theme-sections.php'),
    'editor' => (string)file_get_contents($root . '/admin/theme-section-edit.php'),
];
$failures = [];
$check = static function (bool $condition, string $message) use (&$failures): void {
    echo ($condition ? 'PASS' : 'FAIL') . ' ' . $message . PHP_EOL;
    if (!$condition) $failures[] = $message;
};

$routes = [];
foreach ((array)($manifest['admin']['pages'] ?? []) as $route) $routes[(string)($route['route'] ?? '')] = $route;
foreach ([
    'admin/tools/content-translation/theme-sections',
    'admin/tools/content-translation/theme-section-edit',
    'admin/tools/content-translation/api/theme-section-save',
] as $route) {
    $check(isset($routes[$route]) && ($routes[$route]['permission'] ?? '') === 'plugin.content-translation.workspace.access', $route . ' uses workspace permission');
}
$check(str_contains($sources['save'], "REQUEST_METHOD") && str_contains($sources['save'], "!== 'POST'"), 'package mutation requires POST');
$check(str_contains($sources['save'], 'ct_user_can_workspace') && str_contains($sources['save'], 'ct_user_is_site_owner'), 'package mutation enforces workspace and Site Owner in depth');
$check(str_contains($sources['save'], 'csrf_check'), 'package mutation validates CSRF');
$check(str_contains($sources['delete'], 'translation_state') && str_contains($sources['generic_edit'], "fd.set('translation_state'"), 'translation deletion requires the loaded optimistic state');
$check(str_contains($sources['generic_save'], 'ct_save_translation_locked') && str_contains($sources['helpers'], 'ct_translation_row_state_token($current)') && str_contains($sources['helpers'], 'FOR UPDATE'), 'generic save validates the loaded optimistic state under row locks');
$check(str_contains($sources['generic_save'], 'Use the Theme Section editor'), 'generic save rejects package-composed source templates');
$check(str_contains($sources['generic_edit'], 'theme-section-edit') && str_contains($sources['generic_edit'], 'Location:'), 'generic editor redirects package-composed source templates');
$check(str_contains($sources['admin'], 'ct_user_can_translate_post') && str_contains($sources['admin'], 'ct_user_can_workspace'), 'Core editor translation controls intersect plugin and Core permissions');
$check(str_contains($sources['admin'], "shortcode_layout_editor_after_header") && str_contains($sources['admin'], 'ct_theme_section_template_usages'), 'source renderer editor exposes contextual Theme Template translation controls');
$check(str_contains($sources['packages'], 'function ct_theme_section_template_usages') && str_contains($sources['packages'], "LOCATE('widget:theme_section'"), 'renderer usage lookup parses bounded package-composed Theme Templates');
$check(str_contains($sources['editor'], "\$_GET['section']") && str_contains($sources['editor'], 'ct-package-section--focused') && str_contains($sources['editor'], "adiwira_safe_return_to"), 'package editor focuses the requested section and returns safely to its source renderer');
$check(str_contains($sources['editor'], 'theme_section_preview_document_shell') && str_contains($sources['editor'], 'previewBefore +') && substr_count($sources['editor'], 'sandbox="allow-same-origin"') >= 2 && str_contains($sources['editor'], "querySelectorAll('script')"), 'source and translated previews reuse the script-free sandboxed Core theme-asset document shell');
$check(substr_count($sources['packages'], 'FOR UPDATE') >= 2 && str_contains($sources['packages'], 'beginTransaction()'), 'package save uses a transaction and source/translation row locks');
$check(str_contains($sources['packages'], 'loadedSourceFingerprint') && str_contains($sources['packages'], 'loadedTranslationState'), 'package save checks source and translation optimistic locks');
$check(str_contains($sources['packages'], 'existing package identity or order no longer matches'), 'package save enforces existing v1 identity and order server-side');
$check(str_contains($sources['packages'], 'belongs to a different theme'), 'package save rejects an existing package owned by another theme');
$check(str_contains($sources['packages'], 'Theme Section package is too large'), 'package writer enforces the v1 reader aggregate size limit');
$check(str_contains($sources['packages'], 'HTML declarations and comments are not allowed'), 'write validator rejects browser-tokenizer comment ambiguity');
$check(str_contains($sources['packages'], 'rollBack()') && str_contains($sources['packages'], 'ct_theme_section_translation_meta'), 'failed package saves roll back translation and source metadata together');
$check(str_contains($sources['helpers'], "'version' => 4") && str_contains($sources['helpers'], "'theme_section_translation_metadata'"), 'versioned export includes source verification metadata');
$check(str_contains($sources['helpers'], 'CREATE TABLE IF NOT EXISTS ct_theme_section_translation_meta'), 'metadata schema creation is idempotent and plugin-owned');
$check(str_contains($sources['save'], '!$homepage && $slug ===') && str_contains($sources['save'], '(!$homepage && $slug === \'\')'), 'package endpoint preserves homepage empty-slug exception');
$check(str_contains($sources['list'], '$perPage = 20') && str_contains($sources['list'], '$totalPages'), 'Theme Sections list uses 20-row exact filtered pagination');
$check(str_contains($sources['list'], '$batchSize = 250') && str_contains($sources['list'], 'id < ?'), 'Theme Sections list scans SQL candidates in bounded keyset batches');
$check(str_contains($sources['list'], "LOCATE('widget:theme_section'"), 'Theme Sections SQL prefilter includes Core-valid whitespace after opening brackets');
$check(!str_contains($sources['list'], 'SELECT id, type, title, slug, content'), 'Theme Sections batches do not load every candidate content value into memory');
$check(str_contains($sources['list'], "'&q=' . rawurlencode(\$q)"), 'Theme Sections pagination preserves validated search text');

if ($failures !== []) {
    fwrite(STDERR, count($failures) . " assertion(s) failed.\n");
    exit(1);
}
echo "RESULT: ALL PASS\n";
