<?php
declare(strict_types=1);

$filters = [];
$actions = [];
function add_filter(string $hook, callable $callback, int $priority = 10, int $acceptedArgs = 1): void {
    global $filters;
    $filters[$hook][$priority][] = [$callback, $acceptedArgs];
}
function add_action(string $hook, callable $callback, int $priority = 10, int $acceptedArgs = 1): void {
    global $actions;
    $actions[$hook][$priority][] = [$callback, $acceptedArgs];
}
function ct_homepage_theme_post(PDO $pdo): ?array {
    return ['id' => 42, 'slug' => 'home', 'type' => 'theme'];
}
function ct_homepage_theme_file_resource(PDO $pdo): ?array {
    return null;
}
function ct_current_content_from_request(PDO $pdo): ?array {
    return null;
}
function ct_enabled_locales(PDO $pdo): array {
    return ['id', 'de'];
}
function content_default_locale(): string {
    return 'en';
}
function ct_get_published_translation(PDO $pdo, int $postId, string $locale): ?array {
    return ['post_id' => $postId, 'locale' => $locale, 'status' => 'published'];
}
function ct_get_public_post_translation(PDO $pdo, int $postId, string $locale): ?array {
    return ct_get_published_translation($pdo, $postId, $locale);
}
function ct_homepage_url(?string $locale = null): string {
    return $locale ? '/' . $locale . '/' : '/';
}

final class HomepageSwitcherPdo extends PDO {
    public function __construct() {}
}

$GLOBALS['pdo'] = new HomepageSwitcherPdo();
$_SERVER['REQUEST_URI'] = '/';
$_GET = [];

require dirname(__DIR__) . '/includes/frontend.php';

$failures = [];
$check = static function (bool $condition, string $message) use (&$failures): void {
    echo ($condition ? 'PASS' : 'FAIL') . ' ' . $message . PHP_EOL;
    if (!$condition) $failures[] = $message;
};

$init = $actions['init'][10][0][0] ?? null;
$check(is_callable($init), 'frontend homepage initialization is registered');
if (is_callable($init)) $init();

$check((int)($GLOBALS['ct_current_post']['id'] ?? 0) === 42, 'default homepage initializes the current Theme Template');
$check(!empty($GLOBALS['ct_localized_homepage']), 'default homepage initializes homepage URL context');

$html = ct_language_switcher('', 'select');
$check(str_contains($html, 'class="ct-lang-select"'), 'default homepage renders the language selector');
$check(str_contains($html, 'value="/" selected'), 'default locale links to the root homepage');
$check(str_contains($html, 'value="/id/"'), 'translated locale links to its localized homepage');
$check(str_contains($html, 'value="/de/"'), 'all published homepage translations are included');

if ($failures !== []) exit(1);
echo "RESULT: ALL PASS\n";
