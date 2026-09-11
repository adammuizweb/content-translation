<?php
declare(strict_types=1);

$settings = [
    'content_translation_locales' => '["id","de"]',
    'content_translation_collection_paths' => '{"id":{"posts":"artikel","pages":"halaman"},"de":{"posts":"artikel","pages":"seiten"}}',
    'posts_list_path' => 'article',
    'pages_list_path' => 'pages',
];

function settings_get(PDO $pdo, string $key, mixed $default = null): mixed {
    return $GLOBALS['settings'][$key] ?? $default;
}

function content_default_locale(): string {
    return 'en';
}

function get_supported_locales(): array {
    return ['en', 'id', 'de'];
}

require dirname(__DIR__) . '/includes/helpers.php';

final class LocalizedCollectionPdo extends PDO {
    public function __construct() {}
}

$pdo = new LocalizedCollectionPdo();
$root = dirname(__DIR__);
$frontend = (string)file_get_contents($root . '/includes/frontend.php');
$admin = (string)file_get_contents($root . '/includes/admin.php');
$failures = [];
$check = static function (bool $condition, string $message) use (&$failures): void {
    echo ($condition ? 'PASS' : 'FAIL') . ' ' . $message . PHP_EOL;
    if (!$condition) $failures[] = $message;
};

$check(ct_collection_route_path($pdo, 'posts', 'en') === 'article',
    'default locale uses the Core Post list path');
$check(ct_collection_route_path($pdo, 'posts', 'id') === 'artikel'
    && ct_collection_route_path($pdo, 'posts', 'de') === 'artikel',
    'alternate locales use their configured Post list paths');
$check(ct_collection_url($pdo, 'posts', 'en') === '/article/'
    && ct_collection_url($pdo, 'posts', 'id') === '/id/artikel/'
    && ct_collection_url($pdo, 'posts', 'de', 2, 'news') === '/de/artikel/p/2/?q=news',
    'collection URLs preserve locale, pagination, and search state');
$check(ct_collection_url($pdo, 'pages', 'de') === '/de/seiten/',
    'Page list paths can differ independently by locale');
$check(str_contains($frontend, "add_filter('posts_list_path'")
    && str_contains($frontend, "add_filter('pages_list_path'")
    && str_contains($frontend, 'ct_collection_request_context')
    && str_contains($frontend, 'ct_collection_url'),
    'frontend integrates localized collection routing and URL generation');
$check(str_contains($frontend, 'hreflang="x-default"')
    && str_contains($frontend, 'ct_render_switcher_items'),
    'localized collections expose hreflang and language-switcher links');
$check(str_contains($admin, "add_action('site_settings_after_collection_paths'")
    && str_contains($admin, 'id="ct-collection-paths-locale"')
    && str_contains($admin, 'data-ct-collection-locale')
    && str_contains($admin, 'data-unsaved-guard-ignore')
    && str_contains($admin, 'ct_collection_paths[')
    && str_contains($admin, 'content_translation_collection_paths')
    && !str_contains($admin, '<fieldset style="border:0;padding:0')
    && str_contains($admin, 'Post and Page list paths must be different in each language.'),
    'Site Settings places one scalable locale selector below the Core collection paths and saves every locale');

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}
echo "Localized collection route contract passed.\n";
