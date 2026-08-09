<?php
declare(strict_types=1);

$filters = [];
$actions = [];

function add_filter(string $hook, callable $callback, int $priority = 10, int $acceptedArgs = 1): void
{
    global $filters;
    $filters[$hook][] = $callback;
}

function add_action(string $hook, callable $callback, int $priority = 10, int $acceptedArgs = 1): void
{
    global $actions;
    $actions[$hook][] = $callback;
}

function ct_overlay_published_translation(array $post, PDO $pdo, ?string $locale = null): array
{
    $post['title'] = '[' . (string)$locale . '] ' . (string)($post['title'] ?? '');
    $post['ct_translated_slug'] = 'ubersetzter-beitrag';
    return $post;
}

function ct_post_url(string $slug, ?string $locale = null): string
{
    return '/' . ($locale ? $locale . '/' : '') . trim($slug, '/') . '/';
}

require dirname(__DIR__) . '/includes/frontend.php';

$failures = [];
$check = static function (bool $condition, string $message) use (&$failures): void {
    echo ($condition ? 'PASS' : 'FAIL') . ' ' . $message . PHP_EOL;
    if (!$condition) $failures[] = $message;
};

$queryFilter = $filters['collection_query_clauses'][0] ?? null;
$rowsFilter = $filters['collection_rows'][0] ?? null;
$urlFilter = $filters['collection_url'][0] ?? null;
$check(is_callable($queryFilter), 'collection query filter is registered');
$check(is_callable($rowsFilter), 'collection rows filter is registered');
$check(is_callable($urlFilter), 'collection URL filter is registered');

$GLOBALS['ct_request_locale'] = 'de';
$GLOBALS['pdo'] = (new ReflectionClass(PDO::class))->newInstanceWithoutConstructor();

$clauses = $queryFilter(['where' => [], 'params' => []], [
    'scope' => 'post_category_shortcode',
    'table_alias' => 'p',
    'required_translation_fields' => ['title', 'slug', 'content'],
]);
$where = implode(' ', $clauses['where'] ?? []);
$check(str_contains($where, 'post_translations'), 'shortcode scope requires a published translation');
$check(str_contains($where, 'ct_post_translation.title'), 'shortcode scope requires translated fields');
$check(($clauses['params'][':ct_collection_locale'] ?? null) === 'de', 'shortcode scope binds the request locale');

$rows = $rowsFilter([['id' => 7, 'title' => 'Source', 'slug' => 'source-post']], [
    'scope' => 'post_category_shortcode',
]);
$check(($rows[0]['title'] ?? '') === '[de] Source', 'shortcode rows receive the translated overlay');
$url = $urlFilter('/source/', 'article', [
    'scope' => 'post_category_shortcode',
    'item' => $rows[0],
]);
$check($url === '/de/ubersetzter-beitrag/', 'shortcode rows receive a localized post URL');
$fallbackUrl = $urlFilter('/de/already-correct/', 'article', [
    'scope' => 'post_category_shortcode',
    'item' => ['slug' => 'source-post'],
]);
$check($fallbackUrl === '/de/already-correct/', 'shortcode URLs retain an existing permalink without a translated slug marker');

if ($failures !== []) {
    fwrite(STDERR, count($failures) . " assertion(s) failed.\n");
    exit(1);
}
echo "RESULT: ALL PASS\n";
