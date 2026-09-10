<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$failures = [];
$check = static function (bool $ok, string $message) use (&$failures): void {
    echo ($ok ? 'PASS' : 'FAIL') . ' ' . $message . PHP_EOL;
    if (!$ok) $failures[] = $message;
};

$manifest = json_decode((string)file_get_contents($root . '/plugin.json'), true, 32, JSON_THROW_ON_ERROR);
$pages = $manifest['admin']['pages'] ?? [];
$check(($manifest['requires']['jyavani'] ?? '') === '>=2.3.108', 'manifest requires the Core 2.3.108 generic media extension contract');
$check(($manifest['permissions'][0]['key'] ?? '') === 'plugin.content-translation.workspace.access', 'manifest owns one workspace permission');
$check(count($pages) === 21, 'manifest declares all twenty-one admin routes');
$check(array_filter($pages, static fn(array $page): bool => ($page['permission'] ?? '') !== 'plugin.content-translation.workspace.access') === [], 'every admin route uses workspace permission');

$helpers = (string)file_get_contents($root . '/includes/helpers.php');
$adminHooks = (string)file_get_contents($root . '/includes/admin.php');
$list = (string)file_get_contents($root . '/admin/index.php');
$save = (string)file_get_contents($root . '/admin/api/save.php');
$delete = (string)file_get_contents($root . '/admin/api/delete.php');
$shortcodeSave = (string)file_get_contents($root . '/admin/api/shortcode-save.php');
$export = (string)file_get_contents($root . '/admin/export.php');

$check(str_contains($helpers, 'ct_user_can_workspace') && str_contains($helpers, 'ct_user_can_view_post_representation') && str_contains($helpers, 'ct_user_can_edit_post_locale'), 'shared helpers intersect workspace, source, and locale permissions');
$check(str_contains($helpers, 'authorization_lock_actor_permissions') && str_contains($helpers, 'authorization_lock_owner_contexts'), 'generic translation mutations reauthorize under locks');
$check(str_contains($helpers, 'core.posts.unfiltered_html') && str_contains($helpers, 'core.pages.unfiltered_html'), 'article and page HTML follows Core unfiltered policy');
$check(str_contains($list, 'authorization_owner_scope_condition') && str_contains($list, 'p.created_by'), 'content lists apply owner-scoped Core read permission in SQL');
$check(str_contains($save, "['article', 'page', 'theme']") && str_contains($save, 'ct_user_can_publish_post_translation'), 'generic save allowlists sources and enforces publish permission');
$check(str_contains($delete, 'ct_current_user_id()') && str_contains($helpers, "current['status']"), 'generic delete protects published translations');
$check(str_contains($helpers, 'ct_user_locale_edit_grants') && str_contains($helpers, 'ct_role_locale_edit_grants') && str_contains($helpers, 'updated_by = VALUES(updated_by)'), 'plugin grants and translation mutation attribution are enforced');
$check(str_contains($shortcodeSave, 'core.shortcodes.update') && str_contains($shortcodeSave, 'core.shortcodes.delete'), 'preset mutations split scoped update and global cleanup');
$check(str_contains($adminHooks, 'core.settings.manage') && str_contains($adminHooks, 'core.categories.update') && str_contains($adminHooks, 'core.profile.manage'), 'Core-integrated hooks require their matching Core permissions');
$check(str_contains($export, '$requiredPermissions') && str_contains($export, 'core.users.read') && str_contains($export, 'core.themes.manage'), 'whole-site export requires the broad read/manage matrix');

$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
$legacyChecks = [];
foreach ($iterator as $file) {
    if (!$file->isFile() || $file->getExtension() !== 'php' || str_contains($file->getPathname(), '/tests/')) continue;
    $source = (string)file_get_contents($file->getPathname());
    if (str_contains($source, 'current_user_role(')) $legacyChecks[] = substr($file->getPathname(), strlen($root) + 1);
}
$check($legacyChecks === [], 'runtime authorization contains no direct legacy-role checks');

if ($failures !== []) {
    fwrite(STDERR, implode('; ', $failures) . PHP_EOL);
    exit(1);
}
echo "Content Translation authorization contract passed.\n";
