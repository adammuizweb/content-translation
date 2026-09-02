<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$failures = [];
$check = static function (bool $ok, string $message) use (&$failures): void {
    echo ($ok ? 'PASS' : 'FAIL') . ' ' . $message . PHP_EOL;
    if (!$ok) $failures[] = $message;
};

$permissions = [];
$owners = [];
function content_default_locale(): string { return 'en'; }
function get_supported_locales(): array { return ['en', 'id', 'de']; }
function settings_get(PDO $pdo, string $key, mixed $default = null): mixed {
    return $key === 'content_translation_locales' ? '["id","de"]' : $default;
}
function user_can(PDO $pdo, int $userId, string $permission, array $context = []): bool {
    return !empty($GLOBALS['permissions'][$userId][$permission]);
}
function authorization_actor(PDO $pdo, ?int $userId = null): ?array {
    $userId ??= 0;
    return ['is_site_owner' => !empty($GLOBALS['owners'][$userId])];
}
require_once $root . '/includes/helpers.php';

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->sqliteCreateFunction('NOW', static fn(): string => '2026-09-02 12:00:00');
$pdo->exec('CREATE TABLE ct_user_locale_edit_grants (user_id INTEGER, locale TEXT, PRIMARY KEY (user_id, locale))');
$pdo->exec('CREATE TABLE ct_role_locale_edit_grants (role_id INTEGER, locale TEXT, PRIMARY KEY (role_id, locale))');
$pdo->exec('CREATE TABLE user_roles (user_id INTEGER, role_id INTEGER, expires_at TEXT, PRIMARY KEY (user_id, role_id))');
$pdo->exec("INSERT INTO ct_user_locale_edit_grants VALUES (7, 'id')");
$pdo->exec("INSERT INTO ct_role_locale_edit_grants VALUES (4, 'de')");
$pdo->exec("INSERT INTO user_roles VALUES (8, 4, NULL), (9, 4, '2020-01-01 00:00:00')");

foreach ([7, 8, 9, 10] as $userId) {
    $permissions[$userId] = [
        'plugin.content-translation.workspace.access' => true,
        'core.posts.read' => true,
        'core.posts.update' => true,
        'core.posts.publish' => true,
    ];
}
$post = ['id' => 12, 'type' => 'article', 'created_by' => 3];
$check(ct_user_can_view_post_representation($pdo, $post, 7), 'workspace and owner-scoped Core read allow representation viewing');
$check(ct_user_can_edit_post_locale($pdo, $post, 'id', 7), 'direct user grant allows locale editing');
$check(ct_user_can_edit_post_locale($pdo, $post, 'de', 8), 'active role grant allows locale editing');
$check(!ct_user_can_edit_post_locale($pdo, $post, 'de', 9), 'expired role assignment does not authorize locale editing');
$check(!ct_user_can_edit_post_locale($pdo, $post, 'en', 7), 'canonical locale requires an explicit grant');
$check(ct_user_can_publish_post_translation($pdo, $post, 'de', 8), 'publishing retains the locale edit and Core publish gates');
$permissions[8]['core.posts.publish'] = false;
$check(!ct_user_can_publish_post_translation($pdo, $post, 'de', 8), 'locale edit grant does not bypass Core publish permission');
$owners[10] = true;
$check(ct_user_can_edit_post_locale($pdo, $post, 'en', 10), 'Site Owner bypasses per-locale grants while retaining workspace and Core checks');
$permissions[7]['core.posts.read'] = false;
$check(!ct_user_can_edit_post_locale($pdo, $post, 'id', 7), 'locale grant never bypasses Core source read');
$permissions[7]['core.posts.read'] = true;
$permissions[7]['plugin.content-translation.workspace.access'] = false;
$check(!ct_user_can_view_post_representation($pdo, $post, 7), 'Core source read never bypasses workspace access');

$pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, is_deleted INTEGER, is_locked INTEGER)');
$pdo->exec('CREATE TABLE roles (id INTEGER PRIMARY KEY)');
$pdo->exec('INSERT INTO users VALUES (7, 0, 0), (8, 0, 0), (99, 1, 0)');
$pdo->exec('INSERT INTO roles VALUES (4), (5)');
ct_replace_locale_edit_grants($pdo, [7 => ['en', 'xx'], 99 => ['id']], [5 => ['de', 'xx']]);
$check($pdo->query("SELECT locale FROM ct_user_locale_edit_grants WHERE user_id = 7")->fetchColumn() === 'en'
    && (int)$pdo->query('SELECT COUNT(*) FROM ct_user_locale_edit_grants WHERE user_id = 99')->fetchColumn() === 0,
    'direct grant replacement accepts only active users and configured locales');
$check($pdo->query("SELECT locale FROM ct_role_locale_edit_grants WHERE role_id = 5")->fetchColumn() === 'de',
    'role grant replacement accepts only existing roles and configured locales');

$migration = (string)file_get_contents($root . '/migrations/0003-locale-edit-grants.php');
$helpers = (string)file_get_contents($root . '/includes/helpers.php');
$admin = (string)file_get_contents($root . '/includes/admin.php');
$editor = (string)file_get_contents($root . '/admin/edit.php');
$settings = (string)file_get_contents($root . '/admin/settings.php');
$plugin = (string)file_get_contents($root . '/plugin.php');
$themeSections = (string)file_get_contents($root . '/includes/theme-section-packages.php');
$check(str_contains($migration, 'ct_user_locale_edit_grants') && str_contains($migration, 'ct_role_locale_edit_grants')
    && str_contains($migration, 'content_translation_author_locales') && str_contains($migration, 'fk_post_translations_updated_by')
    && str_contains($migration, 'content_translation_locale_grants_seeded'),
    'append-only migration creates normalized grants, attribution FK, and seeds preferences');
$check(str_contains($helpers, '$grantTablesExisted') && str_contains($helpers, '$grantsMissingForPreferences')
    && str_contains($helpers, 'content_translation_locale_grants_seeded'),
    'runtime repair restores compatibility grants when migration drift recreated empty grant storage');
$check(str_contains($settings, 'user_locale_grants[') && str_contains($settings, 'role_locale_grants[')
    && str_contains($settings, 'ct_replace_locale_edit_grants') && str_contains($settings, 'csrf_check'),
    'settings safely separate direct grants, role grants, and writing preferences');
$check(str_contains($admin, 'ct_user_has_locale_edit_grant') && !str_contains($admin, 'ct_author_default_locale($pdo, $actorId) !==')
    && str_contains($admin, 'Ownership cannot change while a localized workflow is active.'),
    'article and page source commits use grants and single ownership changes are blocked');
$check(str_contains($editor, '$isSource') && str_contains($editor, 'ct_user_can_view_post_representation')
    && str_contains($editor, 'ct-readonly') && str_contains($editor, '<?php if ($canEdit && !$isSource): ?>'),
    'canonical and unassigned representations use a mutation-free read-only view');
$check(str_contains($helpers, "(\$post['type'] ?? '') === 'theme' && !ct_user_is_site_owner")
    && str_contains($themeSections, 'authorization_lock_actor_permissions')
    && str_contains($themeSections, 'ct_user_can_edit_post_locale')
    && str_contains($themeSections, "'updated_by', 'created_at'"),
    'Theme Template translations retain Site Owner policy, locked reauthorization, and complete optimistic attribution state');
$check(str_contains($helpers, "'version' => 6") && str_contains($helpers, 'author_locale_preferences')
    && str_contains($helpers, 'direct_user_locale_edit_grants') && str_contains($helpers, 'updated_by'),
    'export schema 6 includes preferences, grants, and attribution');
$check(str_contains($plugin, "'ct_user_locale_edit_grants'") && str_contains($plugin, "'ct_role_locale_edit_grants'"),
    'uninstall removes plugin-owned grant tables');

if ($failures !== []) {
    fwrite(STDERR, implode('; ', $failures) . PHP_EOL);
    exit(1);
}
echo "Content Translation locale authorization contract passed.\n";
