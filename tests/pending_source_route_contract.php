<?php
declare(strict_types=1);

$filters = [];
function add_filter(string $hook, callable $callback, int $priority = 10, int $acceptedArgs = 1): void {
    $GLOBALS['filters'][$hook][$priority][] = $callback;
}
function add_action(string $hook, callable $callback, int $priority = 10, int $acceptedArgs = 1): void {}
function content_default_locale(): string { return 'en'; }
function get_supported_locales(): array { return ['en', 'id', 'de']; }
function set_locale(string $locale): void { $GLOBALS['test_locale'] = $locale; }
function ct_enabled_locales(PDO $pdo): array { return ['id', 'de']; }
function ct_find_translation_by_slug(PDO $pdo, string $locale, string $slug): ?array {
    return $locale === 'id' && $slug === 'artikel-id' ? ['post_id' => 22] : null;
}
function ct_original_slug(PDO $pdo, int $postId): ?string {
    return $postId === 22 ? 'ct-pending-en-22' : null;
}
function ct_get_public_post_translation(PDO $pdo, int $postId, string $locale): ?array {
    return $postId === 22 && $locale === 'id' ? ['post_id' => 22, 'locale' => 'id', 'status' => 'published'] : null;
}

$root = dirname(__DIR__);
require $root . '/includes/frontend.php';

$pdo = (new ReflectionClass(PDO::class))->newInstanceWithoutConstructor();
$GLOBALS['pdo'] = $pdo;

$failures = [];
$check = static function (bool $ok, string $message) use (&$failures): void {
    echo ($ok ? 'PASS' : 'FAIL') . ' ' . $message . PHP_EOL;
    if (!$ok) $failures[] = $message;
};

$router = $filters['router_path'][10][0] ?? null;
$postData = $filters['post_data'][10][0] ?? null;
$check(is_callable($router) && is_callable($postData), 'localized routing and post overlay filters are registered');
$resolved = $router('id/artikel-id');
$check($resolved === 'ct-pending-en-22'
    && ($GLOBALS['ct_request_locale'] ?? null) === 'id'
    && ($GLOBALS['test_locale'] ?? null) === 'id', 'published ID slug resolves to the aggregate-public canonical row');

if ($failures !== []) {
    fwrite(STDERR, implode('; ', $failures) . PHP_EOL);
    exit(1);
}
echo "Pending source route contract passed.\n";
