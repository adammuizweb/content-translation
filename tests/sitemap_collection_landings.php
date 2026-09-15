<?php
declare(strict_types=1);

$filters = [];
$actions = [];
$settings = [];
$defaultLocale = 'en';

function add_filter(string $hook, callable $callback, int $priority = 10, int $acceptedArgs = 1): void {
    $GLOBALS['filters'][$hook][$priority][] = [$callback, $acceptedArgs];
}

function add_action(string $hook, callable $callback, int $priority = 10, int $acceptedArgs = 1): void {
    $GLOBALS['actions'][$hook][$priority][] = [$callback, $acceptedArgs];
}

function settings_get(PDO $pdo, string $key, mixed $default = null): mixed {
    return $GLOBALS['settings'][$key] ?? $default;
}

function content_default_locale(): string {
    return $GLOBALS['defaultLocale'];
}

function get_supported_locales(): array {
    return ['en', 'id', 'de'];
}

function is_posts_list_enabled(PDO $pdo): bool {
    return trim((string)settings_get($pdo, 'posts_list_path', 'artikel'), '/') !== '';
}

function is_pages_list_enabled(PDO $pdo): bool {
    return trim((string)settings_get($pdo, 'pages_list_path', 'halaman'), '/') !== '';
}

require dirname(__DIR__) . '/includes/helpers.php';
require dirname(__DIR__) . '/includes/frontend.php';

final class SitemapCollectionLandingStatement extends PDOStatement {
    public function __construct(private SitemapCollectionLandingPdo $pdo) {}

    public function execute(?array $params = null): bool {
        return true;
    }

    public function fetchColumn(int $column = 0): mixed {
        return $this->pdo->translatedCount;
    }
}

final class SitemapCollectionLandingPdo extends PDO {
    public int $translatedCount = 0;

    public function __construct() {}

    public function prepare(string $query, array $options = []): PDOStatement|false {
        return new SitemapCollectionLandingStatement($this);
    }
}

$pdo = new SitemapCollectionLandingPdo();
$filter = $filters['sitemap_content_list_entries'][10][0][0] ?? null;
$indexFilter = $filters['sitemap_index_entries'][10][0][0] ?? null;
$failures = [];
$check = static function (bool $condition, string $message) use (&$failures): void {
    echo ($condition ? 'PASS' : 'FAIL') . ' ' . $message . PHP_EOL;
    if (!$condition) $failures[] = $message;
};
$locations = static fn(array $entries): array => array_map(static fn(array $entry): string => (string)$entry['loc'], $entries);

$settings = [
    'content_translation_locales' => '["id","de"]',
    'content_translation_sitemap_locales' => '["de"]',
    'content_translation_collection_paths' => '{"id":{"posts":"artikel","pages":"halaman"},"de":{"posts":"artikel","pages":"seiten"}}',
    'posts_list_path' => 'article',
    'pages_list_path' => 'pages',
];
$coreEntry = ['loc' => 'https://example.test/article/', 'lastmod' => '2026-09-15'];
$entries = $filter([
    ['loc' => 'https://example.test/existing/'],
    $coreEntry,
    ['loc' => 'https://example.test/existing/'],
], $pdo, 'https://example.test/');
$locs = $locations($entries);
$check($locs === [
    'https://example.test/existing/',
    'https://example.test/article/',
    'https://example.test/id/artikel/',
    'https://example.test/de/artikel/',
    'https://example.test/pages/',
    'https://example.test/id/halaman/',
    'https://example.test/de/seiten/',
], 'content-list sitemap contains canonical Post and independent Page landings for every public locale');
$check($entries[1] === $coreEntry && count($locs) === count(array_unique($locs)),
    'Core entries are preserved and final URLs are deduplicated');
$check(in_array('https://example.test/id/artikel/', $locs, true),
    'collection locale selection is independent from item sitemap locale selection');
$check($indexFilter([['loc' => 'https://example.test/core-map.xml']], $pdo, 'https://example.test', 30) === [
    ['loc' => 'https://example.test/core-map.xml'],
], 'no translated items create no localized item sitemap maps');

$settings['pages_list_path'] = '';
$entries = $filter([], $pdo, 'https://example.test');
$locs = $locations($entries);
$check($locs === [
    'https://example.test/article/',
    'https://example.test/id/artikel/',
    'https://example.test/de/artikel/',
], 'globally disabled Page collections remain omitted for every locale');
$settings['posts_list_path'] = '';
$check($filter([], $pdo, 'https://example.test') === [],
    'globally disabled Post and Page collections emit no landing URLs');

$defaultLocale = 'id';
$settings = [
    'content_translation_locales' => '["en","de"]',
    'content_translation_sitemap_locales' => '[]',
    'content_translation_collection_paths' => '{"en":{"posts":"article","pages":"pages"},"de":{"posts":"artikel","pages":"seiten"}}',
    'posts_list_path' => 'artikel',
    'pages_list_path' => 'halaman',
];
$entries = $filter([], $pdo, 'https://example.test');
$locs = $locations($entries);
$check($locs === [
    'https://example.test/artikel/',
    'https://example.test/en/article/',
    'https://example.test/de/artikel/',
    'https://example.test/halaman/',
    'https://example.test/en/pages/',
    'https://example.test/de/seiten/',
], 'a non-English default is unprefixed and alternate locales receive exactly one prefix');

$frontend = (string)file_get_contents(dirname(__DIR__) . '/includes/frontend.php');
$renderer = strstr($frontend, "add_filter('sitemap_locale_rendered'");
$renderer = is_string($renderer) ? strstr($renderer, "add_filter('collection_rows'", true) : '';
$check(!str_contains($frontend, 'ct_collection_sitemap_map_count')
    && is_string($renderer)
    && !str_contains($renderer, 'ct_collection_url'),
    'empty-map workaround and collection landing output are absent from localized item sitemaps');

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}
echo "Sitemap collection landing contract passed.\n";
