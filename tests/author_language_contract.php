<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$failures = [];
$check = static function (bool $ok, string $message) use (&$failures): void {
    echo ($ok ? 'PASS' : 'FAIL') . ' ' . $message . PHP_EOL;
    if (!$ok) $failures[] = $message;
};

$helpers = (string)file_get_contents($root . '/includes/helpers.php');
$workflowMigration = (string)file_get_contents($root . '/includes/workflow-migration.php');
$admin = (string)file_get_contents($root . '/includes/admin.php');
$frontend = (string)file_get_contents($root . '/includes/frontend.php');
$edit = (string)file_get_contents($root . '/admin/edit.php');
$index = (string)file_get_contents($root . '/admin/index.php');
$save = (string)file_get_contents($root . '/admin/api/save.php');
$settings = (string)file_get_contents($root . '/admin/settings.php');
$plugin = (string)file_get_contents($root . '/plugin.php');
$migration = (string)file_get_contents($root . '/migrations/0002-migrate-authored-posts.php');
$manifest = json_decode((string)file_get_contents($root . '/plugin.json'), true, 32, JSON_THROW_ON_ERROR);

$authorLanguageSettings = [
    'content_translation_locales' => json_encode(['id', 'de']),
    'content_translation_author_locales' => json_encode(['7' => 'id', '8' => 'en', '9' => 'fr']),
];
function settings_get(PDO $pdo, string $key, mixed $default = null): mixed {
    global $authorLanguageSettings;
    return $authorLanguageSettings[$key] ?? $default;
}
function settings_set(PDO $pdo, string $key, mixed $value, int $autoload = 1): bool {
    global $authorLanguageSettings;
    $authorLanguageSettings[$key] = $value;
    return true;
}
function content_default_locale(): string { return 'en'; }
function get_supported_locales(): array { return ['en', 'id', 'de']; }
function __(string $text): string { return $text; }
function user_can(PDO $pdo, int $userId, string $permission, array $context = []): bool { return $userId > 0; }
function add_action(string $hook, callable $callback, int $priority = 10, int $acceptedArgs = 1): void {
    $GLOBALS['authorLanguageActions'][$hook][$priority][] = $callback;
}
function add_filter(string $hook, callable $callback, int $priority = 10, int $acceptedArgs = 1): void {
    $GLOBALS['authorLanguageFilters'][$hook][$priority][] = $callback;
}
require_once $root . '/includes/helpers.php';

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE posts (id INTEGER PRIMARY KEY, type TEXT, title TEXT, slug TEXT, content TEXT, meta TEXT, status TEXT, created_by INTEGER, is_deleted INTEGER)');
$pdo->exec('CREATE TABLE ct_post_workflows (post_id INTEGER PRIMARY KEY, source_locale TEXT, author_locale TEXT, source_status TEXT)');
$pdo->exec('CREATE TABLE post_translations (id INTEGER PRIMARY KEY, post_id INTEGER, locale TEXT, title TEXT, slug TEXT, content TEXT, meta_description TEXT, status TEXT)');
$pdo->exec('CREATE TABLE jvb_layouts (post_id INTEGER PRIMARY KEY, status TEXT, published_at TEXT, draft_json TEXT)');
$pdo->exec('CREATE TABLE ct_user_locale_edit_grants (user_id INTEGER, locale TEXT, PRIMARY KEY (user_id, locale))');
$pdo->exec('CREATE TABLE ct_role_locale_edit_grants (role_id INTEGER, locale TEXT, PRIMARY KEY (role_id, locale))');
$pdo->exec('CREATE TABLE user_roles (user_id INTEGER, role_id INTEGER, expires_at TEXT)');
$pdo->exec("INSERT INTO ct_user_locale_edit_grants VALUES (7, 'id'), (7, 'de')");
$pdo->exec('CREATE TABLE categories (id INTEGER PRIMARY KEY, parent_id INTEGER, slug TEXT, is_deleted INTEGER)');
$pdo->exec('CREATE TABLE category_translations (category_id INTEGER, locale TEXT, name TEXT, slug TEXT, description TEXT, status TEXT)');
$pdo->exec("INSERT INTO posts VALUES (22, 'article', '[EN translation pending]', 'ct-pending-en-22', '', NULL, 'published', 7, 0)");
$pdo->exec("INSERT INTO ct_post_workflows VALUES (22, 'en', 'id', 'draft')");
$pdo->exec("INSERT INTO post_translations VALUES (1, 22, 'id', 'Artikel ID', 'artikel-id', 'Isi', '', 'published')");
$pdo->exec("INSERT INTO post_translations VALUES (2, 22, 'de', 'Deutscher Artikel', 'deutscher-artikel', 'Inhalt', '', 'published')");
$pdo->exec("INSERT INTO jvb_layouts VALUES (22, 'published', '2026-09-22 00:00:00', NULL)");
$pdo->exec("INSERT INTO categories VALUES (1, NULL, 'news', 0), (2, NULL, 'draft', 0), (3, NULL, 'parent', 0), (4, 3, 'child', 0)");
$pdo->exec("INSERT INTO category_translations VALUES
    (1, 'id', 'Berita', 'berita', '', 'published'),
    (1, 'de', 'Nachrichten', 'nachrichten', 'Deutsche Nachrichten', 'published'),
    (2, 'id', 'Draf Tersembunyi', 'draf', '', 'draft'),
    (2, 'de', 'Entwurf', 'entwurf', '', 'draft'),
    (4, 'de', 'Kind', 'kind', '', 'published')");
$post = $pdo->query('SELECT * FROM posts WHERE id = 22')->fetch(PDO::FETCH_ASSOC);

$check(($manifest['version'] ?? '') === '1.19.1', 'plugin release is 1.19.1');
$check(str_contains($helpers, 'content_translation_author_locales')
    && str_contains($helpers, 'ct_author_default_locale')
    && str_contains($helpers, 'ct_set_author_locale_preferences'), 'author locale preferences use shared validated helpers');
$check(str_contains($settings, 'author_locales[')
    && str_contains($settings, 'ct_user_can_workspace($pdo, (int)$user[\'id\'])')
    && str_contains($settings, "'core.posts.create'")
    && str_contains($settings, "'core.pages.create'")
    && str_contains($settings, "'core.theme_content.create'"),
    'settings list users who can create translatable content');
$check(str_contains($migration, "includes/workflow-migration.php")
    && str_contains($migration, 'ct_130_migrate_legacy_authored_posts')
    && str_contains($workflowMigration, 'FOR UPDATE')
    && str_contains($workflowMigration, '$markerClear->execute')
    && str_contains($helpers, "JSON_REMOVE") === false, 'workflow schema and data migration do not discard legacy content');
$check(str_contains($admin, "add_action('admin_post_before_add_commit'")
    && str_contains($admin, "add_action('admin_post_before_edit_commit'")
    && str_contains($admin, "add_action('admin_posts_bulk_before_mutation'")
    && str_contains($admin, "add_filter('admin_post_editor_status'")
    && str_contains($admin, "add_action('admin_page_before_add_commit'")
    && str_contains($admin, "add_action('admin_page_before_edit_commit'")
    && str_contains($admin, "add_action('admin_pages_bulk_before_mutation'")
    && str_contains($admin, "add_filter('admin_page_editor_status'")
    && str_contains($admin, 'ct_create_authored_post_workflow')
    && str_contains($admin, "type IN ('article', 'page')")
    && str_contains($admin, 'ct_update_authored_post_source'),
    'Core transaction hooks own canonical article and page source workflow changes');
$schemaInit = strpos($admin, 'ct_ensure_schema($pdo);');
$workspaceGate = strpos($admin, 'if (!ct_user_can_workspace($pdo)) return;');
$check($schemaInit !== false && $workspaceGate !== false && $schemaInit < $workspaceGate,
    'admin schema initialization completes before assigned authors enter Core content transactions');
$check(str_contains($admin, "add_action('admin_post_after_add'")
    && str_contains($admin, 'RELEASE_LOCK')
    && str_contains($admin, 'GET_LOCK')
    && !str_contains($admin, "add_action('admin_post_after_edit'"), 'post-commit handling only releases the authored slug lock');
$check(str_contains($admin, "add_filter('site_settings_validation_errors'")
    && str_contains($admin, 'Content default language cannot change'), 'active workflows lock the canonical content language');
$check(str_contains($admin, "add_filter('post_list_join'")
    && str_contains($admin, "add_action('admin_content_list_filters'")
    && str_contains($admin, "add_filter('admin_content_row_actions'")
    && str_contains($admin, 'content_locale')
    && str_contains($helpers, 'ct_admin_content_list_locale')
    && str_contains($admin, 'ct_post_list_workflow.source_status')
    && str_contains($admin, 'ct_post_list_display.status')
    && str_contains($admin, "add_filter('post_list_status_expression'")
    && str_contains($admin, "add_filter('post_list_search_condition'")
    && str_contains($admin, "add_filter('post_list_rows'")
    && str_contains($admin, "admin/themes/edit"),
    'article, page, and Theme Template dashboards support an independent list language with translation actions');
$check(str_contains($admin, "add_filter('admin_category_list_rows'")
    && str_contains($admin, "add_action('admin_category_row_actions'")
    && str_contains($admin, "add_action('admin_category_before_purge_commit'")
    && str_contains($admin, 'ct_get_published_category_translation')
    && str_contains($admin, 'ct_admin_content_list_locale')
    && str_contains($admin, 'ct_category_url'),
    'Content Translation adapts selected-locale category labels, actions, URLs, and cleanup through Core hooks');
$check(str_contains($helpers, 'ct_category_translation_slug_conflict')
    && str_contains($helpers, 'ct_assert_category_translation_paths_unique')
    && str_contains((string)file_get_contents($root . '/admin/category-edit.php'), 'authorization_lock_actor_permissions')
    && str_contains($admin, "add_action('admin_category_before_edit_commit'")
    && str_contains($admin, "add_action('admin_category_before_restore_commit'"),
    'category translation saves and hierarchy changes reject ambiguous sibling slugs under ordered locks');
$check(str_contains($admin, "'admin/pages/add', 'admin/pages/edit'")
    && str_contains($admin, "FROM category_translations")
    && str_contains($admin, "status = 'published'")
    && str_contains($admin, 'input[name="categories[]"]'),
    'post editors overlay published category labels for the current writing locale');
$check(str_contains($frontend, 'ct_source_post_is_public')
    && str_contains($frontend, "add_filter('sitemap_query_clauses'")
    && !str_contains($frontend, '$.content_translation.authoring_locale')
    && !str_contains($frontend, 'register_frontend_route'), 'Core fallback routing overlays translations while hiding an unpublished EN source');
$check(str_contains($edit, 'ct_content_locales')
    && str_contains($index, 'ct_content_locales')
    && str_contains($save, 'ct_post_translation_locales')
    && str_contains($edit, '$targetLocale'), 'translation screens expose canonical and translated content locales including DE');
$check(str_contains($helpers, 'ct_recompute_post_effective_status')
    && str_contains($helpers, "DELETE FROM post_translations")
    && str_contains($helpers, 'Post visibility could not be updated.'), 'translation saves and deletes recompute aggregate visibility');
$check(str_contains($plugin, "['disable', 'delete']")
    && str_contains($plugin, "'ct_post_workflows'")
    && str_contains($plugin, "p.type IN ('article', 'page')"),
    'article and page workflows block unsafe lifecycle changes and restore source status on uninstall');

$check(ct_author_default_locale($pdo, 7) === 'id'
    && ct_author_default_locale($pdo, 8) === 'en'
    && ct_author_default_locale($pdo, 9) === 'en', 'stored preferences allow only enabled non-default locales');
$check(ct_admin_content_list_locale($pdo, ['type' => 'article'], 7, ['content_locale' => 'de']) === 'de'
    && ct_admin_content_list_locale($pdo, ['type' => 'article'], 7, ['content_locale' => ['id']]) === 'id'
    && ct_admin_content_list_locale($pdo, ['type' => 'article'], 7, ['content_locale' => 'fr']) === 'id',
    'explicit valid list language overrides writing preference without accepting malformed or disabled locales');
$check(ct_admin_content_list_types(['type' => 'category']) === ['category']
    && ct_admin_content_list_types(['type' => 'mixed', 'content_types' => ['page', 'article', 'theme']]) === ['page', 'article', 'theme']
    && ct_admin_content_list_types(['type' => 'mixed', 'content_types' => ['page', 'page']]) === []
    && ct_admin_content_list_types(['type' => 'mixed', 'content_types' => ['page', 'unsupported']]) === []
    && ct_admin_post_list_context(['type' => 'category']) === false
    && ct_admin_post_list_context(['type' => 'mixed', 'content_types' => ['page', 'article', 'theme']]),
    'content-list contexts accept Category and validated mixed Core post types while rejecting malformed capabilities');
$check(ct_admin_content_list_locale($pdo, [
    'type' => 'mixed',
    'content_types' => ['page', 'article', 'theme'],
], 7, ['content_locale' => 'de']) === 'de', 'mixed compatible lists honor an explicit locale');
$check(ct_set_author_locale_preferences($pdo, [7 => 'de', 8 => 'en', 9 => 'fr'])
    && json_decode((string)$authorLanguageSettings['content_translation_author_locales'], true) === ['7' => 'de'], 'preference writes discard default and unavailable locales');
$check(ct_post_authoring_locale($pdo, $post) === 'id'
    && ct_post_source_locale($pdo, $post) === 'en'
    && ct_post_translation_locales($pdo, $post) === ['id', 'de'], 'ID-authored workflow retains EN as canonical source and exposes ID and DE targets');
$check(ct_post_source_status($pdo, $post) === 'draft'
    && !ct_source_post_is_public($pdo, $post)
    && ct_post_effective_status($pdo, 22) === 'published', 'published ID keeps the aggregate row public without publishing EN');
$check(ct_translation_slug_lock_name('en', 'example') === ct_translation_slug_lock_name('en', 'example')
    && ct_translation_slug_lock_name('en', 'example') !== ct_translation_slug_lock_name('id', 'example'), 'translation slug locks remain locale-specific');

$authorLanguageSettings['content_translation_author_locales'] = json_encode(['7' => 'id']);
$_SESSION['user_id'] = 7;
$GLOBALS['pdo'] = $pdo;
$_GET = ['page' => 'admin/posts/add'];
require $root . '/includes/admin.php';
$_GET = ['page' => 'admin/tools/compatible-builder', 'content_locale' => 'de'];
$mixedListContext = [
    'schema' => 1,
    'surface' => 'plugin.compatible-builder',
    'type' => 'mixed',
    'content_types' => ['page', 'article', 'theme'],
    'actor_id' => 7,
];
$joinFilter = $GLOBALS['authorLanguageFilters']['post_list_join'][10][0] ?? null;
$selectFilter = $GLOBALS['authorLanguageFilters']['post_list_select'][10][0] ?? null;
$statusFilter = $GLOBALS['authorLanguageFilters']['post_list_status_expression'][10][0] ?? null;
$searchFilter = $GLOBALS['authorLanguageFilters']['post_list_search_condition'][10][0] ?? null;
$mixedJoin = is_callable($joinFilter) ? $joinFilter('', '', $mixedListContext) : '';
$mixedSelect = is_callable($selectFilter) ? $selectFilter('', '', $mixedListContext) : '';
$mixedStatus = is_callable($statusFilter) ? $statusFilter('p.status', $mixedListContext) : '';
$mixedSearch = is_callable($searchFilter) ? $searchFilter('(p.title LIKE :search)', $mixedListContext) : '';
$check(str_contains($mixedJoin, 'ct_post_list_display')
    && str_contains($mixedSelect, 'ct_translation_id')
    && str_contains($mixedStatus, 'ct_post_list_workflow.source_status')
    && str_contains($mixedSearch, 'ct_post_list_display.title'),
    'compatible mixed-content lists receive localized joins, projections, status, and search expressions');
$mixedSql = "SELECT p.id, p.title, p.slug, p.type, p.status, p.created_by,
        l.status AS jvb_status{$mixedSelect}
    FROM posts p
    LEFT JOIN jvb_layouts l ON l.post_id = p.id
    {$mixedJoin}
    WHERE p.is_deleted = 0
      AND ({$mixedStatus}) != 'private'
      AND ({$mixedSearch})";
$mixedStatement = $pdo->prepare($mixedSql);
$mixedStatement->execute([':search' => '%Deutscher%']);
$mixedRow = $mixedStatement->fetch(PDO::FETCH_ASSOC);
$check(is_array($mixedRow)
    && ($mixedRow['title'] ?? '') === 'Deutscher Artikel'
    && ($mixedRow['slug'] ?? '') === 'deutscher-artikel'
    && ($mixedRow['status'] ?? '') === 'published'
    && ($mixedRow['jvb_status'] ?? '') === 'published',
    'mixed-list SQL composes localized representation and search with one provider-owned layout join');
$categoryContext = ['schema' => 1, 'type' => 'category', 'actor_id' => 7];
$check((is_callable($joinFilter) ? $joinFilter('base join', '', $categoryContext) : '') === 'base join'
    && (is_callable($selectFilter) ? $selectFilter('base select', '', $categoryContext) : '') === 'base select'
    && (is_callable($statusFilter) ? $statusFilter('p.status', $categoryContext) : '') === 'p.status'
    && (is_callable($searchFilter) ? $searchFilter('base search', $categoryContext) : '') === 'base search',
    'Category contexts do not receive post-list SQL fragments');
$listFilter = $GLOBALS['authorLanguageActions']['admin_content_list_filters'][10][0] ?? null;
$_GET = ['page' => 'admin/posts/index', 'content_locale' => 'de'];
ob_start();
if (is_callable($listFilter)) $listFilter(['schema' => 1, 'type' => 'article', 'filter_form_id' => 'posts-list-filter'], $pdo);
$listFilterOutput = (string)ob_get_clean();
$check(str_contains($listFilterOutput, 'name="content_locale"')
    && str_contains($listFilterOutput, 'value="de" selected')
    && str_contains($listFilterOutput, 'form="')
    && str_contains($listFilterOutput, 'fetch(url.href')
    && str_contains($listFilterOutput, 'document.write(html)')
    && str_contains($listFilterOutput, 'searchParams.delete("p")'),
    'content lists render an AJAX language control without changing writing preference');
$_GET = ['page' => 'admin/categories/index', 'content_locale' => 'de'];
ob_start();
if (is_callable($listFilter)) $listFilter([
    'schema' => 1,
    'type' => 'category',
    'filter_form_id' => 'categories-list-filter',
], $pdo);
$categoryFilterOutput = (string)ob_get_clean();
$check(str_contains($categoryFilterOutput, 'value="de" selected')
    && str_contains($categoryFilterOutput, 'form="categories-list-filter"'),
    'Category list renders the selected locale control in its Core filter form');
ob_start();
if (is_callable($listFilter)) $listFilter([
    'schema' => 1,
    'type' => 'mixed',
    'content_types' => ['page', 'article', 'theme'],
    'filter_form_id' => 'jvb-list-filter',
], $pdo);
$mixedFilterOutput = (string)ob_get_clean();
$check(str_contains($mixedFilterOutput, 'value="de" selected')
    && str_contains($mixedFilterOutput, 'form="jvb-list-filter"'),
    'compatible mixed-content plugin lists render the shared locale control');
ob_start();
if (is_callable($listFilter)) $listFilter([
    'schema' => 1,
    'type' => 'mixed',
    'content_types' => ['page', 'unsupported'],
    'filter_form_id' => 'invalid-list-filter',
], $pdo);
$check(ob_get_clean() === '', 'unsupported mixed-list capabilities fail closed without rendering controls');

$categoryRowsFilter = $GLOBALS['authorLanguageFilters']['admin_category_list_rows'][10][0] ?? null;
$categoryRows = [
    ['id' => 1, 'name' => 'News', 'slug' => 'news', 'description' => '', 'parent_id' => null],
    ['id' => 2, 'name' => 'Draft', 'slug' => 'draft', 'description' => '', 'parent_id' => null],
    ['id' => 4, 'name' => 'Child', 'slug' => 'child', 'description' => '', 'parent_id' => 3],
];
$localizedCategories = is_callable($categoryRowsFilter) ? $categoryRowsFilter($categoryRows, [
    'schema' => 1,
    'type' => 'category',
    'actor_id' => 7,
], $pdo) : [];
$check(($localizedCategories[0]['name'] ?? '') === 'Nachrichten'
    && ($localizedCategories[0]['description'] ?? '') === 'Deutsche Nachrichten'
    && ($localizedCategories[0]['display_url'] ?? '') === '/de/category/nachrichten/'
    && ($localizedCategories[1]['name'] ?? '') === 'Draft'
    && !isset($localizedCategories[1]['display_url'])
    && ($localizedCategories[2]['name'] ?? '') === 'Kind'
    && !isset($localizedCategories[2]['display_url']),
    'Category rows use published selected-locale labels and only expose complete localized hierarchy URLs');
$_GET = ['page' => 'admin/categories/index', 'content_locale' => 'en'];
$canonicalCategories = is_callable($categoryRowsFilter) ? $categoryRowsFilter($categoryRows, [
    'schema' => 1,
    'type' => 'category',
    'actor_id' => 7,
], $pdo) : [];
$check($canonicalCategories === $categoryRows, 'canonical Category locale retains the Core source representation');
$_GET = ['page' => 'admin/posts/index', 'content_locale' => 'de'];
if (!defined('ADMIN_BASE_PATH')) define('ADMIN_BASE_PATH', '/dashboard');
$rowActionFilter = $GLOBALS['authorLanguageFilters']['admin_content_row_actions'][10][0] ?? null;
$rowActions = is_callable($rowActionFilter) ? $rowActionFilter([], [
    'id' => 22,
    'owner_id' => 7,
    'ct_translation_id' => 0,
], [
    'content_type' => 'article',
    'actor_id' => 7,
    'return_to' => '/dashboard/?page=admin/posts/index&content_locale=de',
], $pdo) : [];
$check(($rowActions[0]['label'] ?? '') === 'Add DE'
    && str_contains((string)($rowActions[0]['url'] ?? ''), 'locale=de')
    && str_contains((string)($rowActions[0]['url'] ?? ''), 'return_to='),
    'alternate-language list rows expose an authorized translation action with preserved return state');
$coreEditFilter = $GLOBALS['authorLanguageFilters']['admin_content_core_edit_action_visible'][10][0] ?? null;
$check(is_callable($coreEditFilter)
    && $coreEditFilter(true, ['id' => 22], ['content_type' => 'article', 'actor_id' => 7], $pdo) === false
    && $coreEditFilter(true, ['id' => 1], ['content_type' => 'category', 'actor_id' => 7], $pdo) === false,
    'alternate-language Article and Category lists hide the canonical source Edit action');
$_GET = ['page' => 'admin/posts/index', 'content_locale' => 'en'];
$check(is_callable($coreEditFilter)
    && $coreEditFilter(true, ['id' => 22], ['content_type' => 'article', 'actor_id' => 7], $pdo) === true,
    'default-language lists retain the canonical source Edit action');
$_GET = ['page' => 'admin/posts/add'];
$footer = $GLOBALS['authorLanguageActions']['admin_footer'][10][0] ?? null;
ob_start();
if (is_callable($footer)) {
    $footer();
    $footer();
}
$noticeOutput = (string)ob_get_clean();
$check(substr_count($noticeOutput, 'ct-author-language-notice') === 1,
    'Add Post identifies the assigned ID writing language once');
$check(str_contains($noticeOutput, 'Berita'),
    'Add Post exposes the published ID category label');
$check(!str_contains($noticeOutput, 'Draf Tersembunyi'),
    'Add Post excludes draft ID category labels');

if ($failures !== []) {
    fwrite(STDERR, implode('; ', $failures) . PHP_EOL);
    exit(1);
}
echo "Content Translation author language contract passed.\n";
