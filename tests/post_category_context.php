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

function ct_overlay_category_translation(array $category, PDO $pdo, ?string $locale = null): array
{
    $category['name'] = 'Panduan';
    $category['slug'] = 'panduan';
    return $category;
}

require dirname(__DIR__) . '/includes/frontend.php';

$failures = [];
$check = static function (bool $condition, string $message) use (&$failures): void {
    echo ($condition ? 'PASS' : 'FAIL') . ' ' . $message . PHP_EOL;
    if (!$condition) $failures[] = $message;
};

$itemFilter = $filters['collection_item'][0] ?? null;
$check(is_callable($itemFilter), 'category collection filter is registered');

$GLOBALS['ct_request_locale'] = 'id';
$GLOBALS['pdo'] = (new ReflectionClass(PDO::class))->newInstanceWithoutConstructor();
$source = ['id' => 7, 'name' => 'Guides', 'slug' => 'guides'];

$postCategory = $itemFilter($source, 'category', ['scope' => 'post_category']);
$check(($postCategory['name'] ?? '') === 'Panduan', 'post category uses the request locale');
$check(!isset($GLOBALS['ct_current_category']), 'post category does not replace the active post context');

$categoryPage = $itemFilter($source, 'category', ['scope' => 'category']);
$check(($categoryPage['name'] ?? '') === 'Panduan', 'category page uses the request locale');
$check(($GLOBALS['ct_current_category']['id'] ?? null) === 7, 'category page owns the active category context');

if ($failures !== []) {
    fwrite(STDERR, count($failures) . " assertion(s) failed.\n");
    exit(1);
}
echo "RESULT: ALL PASS\n";
