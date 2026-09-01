<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$failures = [];
$check = static function (bool $ok, string $message) use (&$failures): void {
    echo ($ok ? 'PASS' : 'FAIL') . ' ' . $message . PHP_EOL;
    if (!$ok) $failures[] = $message;
};

$helpers = (string)file_get_contents($root . '/includes/helpers.php');
$admin = (string)file_get_contents($root . '/includes/admin.php');
$frontend = (string)file_get_contents($root . '/includes/frontend.php');
$edit = (string)file_get_contents($root . '/admin/edit.php');
$index = (string)file_get_contents($root . '/admin/index.php');
$save = (string)file_get_contents($root . '/admin/api/save.php');
$settings = (string)file_get_contents($root . '/admin/settings.php');
$plugin = (string)file_get_contents($root . '/plugin.php');
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
function add_action(string $hook, callable $callback, int $priority = 10, int $acceptedArgs = 1): void {
    $GLOBALS['authorLanguageActions'][$hook][$priority][] = $callback;
}
function add_filter(string $hook, callable $callback, int $priority = 10, int $acceptedArgs = 1): void {
    $GLOBALS['authorLanguageFilters'][$hook][$priority][] = $callback;
}
require_once $root . '/includes/helpers.php';
$pdo = new PDO('sqlite::memory:');

$check(($manifest['version'] ?? '') === '1.13.0', 'plugin release is 1.13.0');
$check(str_contains($helpers, 'content_translation_author_locales')
    && str_contains($helpers, 'ct_author_default_locale')
    && str_contains($helpers, 'ct_set_author_locale_preferences'), 'author locale preferences are validated through shared helpers');
$check(str_contains($settings, 'author_locales[')
    && str_contains($settings, "'core.posts.create'"), 'settings list only users who can create posts');
$check(str_contains($admin, "add_action('admin_post_after_add'")
    && str_contains($admin, "add_action('admin_post_after_edit'")
    && str_contains($admin, "'core.posts.publish'")
    && str_contains($admin, 'ct_quarantine_authored_post')
    && str_contains($admin, 'retained as a draft'), 'post hooks synchronize authored translations under Core permissions and fail safely');
$check(str_contains($admin, "add_action('admin_footer'")
    && str_contains($admin, 'ct-author-language-notice')
    && str_contains($admin, 'static $rendered = false'), 'standard Add Post UI identifies the assigned writing language once');
$check(str_contains($admin, "add_filter('post_list_join'")
    && str_contains($admin, "add_filter('post_list_select'")
    && str_contains($admin, 'ct_post_list_display'), 'Core post list uses the current dashboard user writing locale');
$check(str_contains($frontend, "$.content_translation.authoring_locale")
    && str_contains($frontend, 'ct_post_authoring_locale')
    && str_contains($frontend, 'header(\'Location: \' . $target, true, 301)'), 'source shadows are excluded and canonicalized to locale URLs');
$check(str_contains($frontend, 'ct_default_search')
    && str_contains($frontend, 'ct_translated_slug')
    && str_contains($frontend, 'ct_post_locale_is_published'), 'published default translations drive search, permalinks, and language alternatives');
$check(str_contains($edit, 'ct_post_translation_locales')
    && str_contains($index, 'ct_post_translation_locales')
    && str_contains($save, 'ct_post_translation_locales'), 'editor, overview, and save API share per-post translation targets');
$check(str_contains($save, 'ct_translation_slug_conflict')
    && str_contains($frontend, "content_route_resolve(\$pdo, \$path, '', 'public')"), 'default translations cannot shadow canonical Core routes');
$check(str_contains($settings, 'ct_post_authoring_locales_in_use')
    && str_contains($plugin, "['disable', 'delete']"), 'used source locales block unsafe settings and plugin lifecycle changes');
$check(ct_author_default_locale($pdo, 7) === 'id'
    && ct_author_default_locale($pdo, 8) === 'en'
    && ct_author_default_locale($pdo, 9) === 'en', 'stored preferences allow only enabled non-default locales');
$check(ct_set_author_locale_preferences($pdo, [7 => 'de', 8 => 'en', 9 => 'fr'])
    && json_decode((string)$authorLanguageSettings['content_translation_author_locales'], true) === ['7' => 'de'], 'preference writes discard default and unavailable locales');
$markedMeta = ct_post_meta_with_authoring_locale(['meta' => json_encode(['sidebar' => 'left'])], 'id');
$check(ct_post_authoring_locale($pdo, ['id' => 12, 'meta' => $markedMeta]) === 'id'
    && (json_decode($markedMeta, true)['sidebar'] ?? '') === 'left', 'post locale markers preserve unrelated Core metadata');
$markedPost = ['id' => 22, 'meta' => $markedMeta, 'status' => 'published'];
$check(ct_post_source_locale($pdo, $markedPost) === 'id'
    && ct_post_translation_locales($pdo, $markedPost) === ['en', 'de'], 'ID-authored posts expose EN and DE translation targets');
$check(ct_post_source_locale($pdo, ['id' => 23, 'meta' => null]) === 'en'
    && ct_post_translation_locales($pdo, ['id' => 23, 'meta' => null]) === ['id', 'de'], 'default-authored posts retain ID and DE translation targets');
$check(ct_translation_slug_lock_name('en', 'example') === ct_translation_slug_lock_name('en', 'example')
    && ct_translation_slug_lock_name('en', 'example') !== ct_translation_slug_lock_name('id', 'example'), 'source sync and translation API share locale-specific slug locks');

$pdo->exec('CREATE TABLE posts (id INTEGER PRIMARY KEY, type TEXT, meta TEXT, is_deleted INTEGER)');
$insert = $pdo->prepare('INSERT INTO posts (id, type, meta, is_deleted) VALUES (?, ?, ?, 0)');
$insert->execute([22, 'article', $markedMeta]);
$GLOBALS['pdo'] = $pdo;
$_GET = ['page' => 'admin/posts/edit', 'id' => 22];
require $root . '/includes/admin.php';
$footer = $GLOBALS['authorLanguageActions']['admin_footer'][10][0] ?? null;
ob_start();
if (is_callable($footer)) {
    $footer();
    $footer();
}
$noticeOutput = (string)ob_get_clean();
$check(substr_count($noticeOutput, 'ct-author-language-notice') === 1, 'duplicate admin_footer dispatch renders one writing-language notice');

if ($failures !== []) {
    fwrite(STDERR, implode('; ', $failures) . PHP_EOL);
    exit(1);
}
echo "Content Translation author language contract passed.\n";
