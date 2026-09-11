<?php
declare(strict_types=1);

function content_default_locale(): string {
    return 'en';
}

function get_page_permalink(array $post): string {
    return test_permalink($post);
}

function get_post_permalink(array $post): string {
    return test_permalink($post);
}

function test_permalink(array $post): string {
    $locale = $GLOBALS['ct_request_locale'] ?? ($post['ct_locale'] ?? null);
    $slug = $locale && isset($post['ct_translated_slug'])
        ? (string)$post['ct_translated_slug']
        : (string)$post['slug'];
    return ($locale ? '/' . $locale : '') . '/' . $slug . '/';
}

require dirname(__DIR__) . '/includes/helpers.php';

final class PublicPostUrlPdo extends PDO {
    public function __construct() {}
}

$pdo = new PublicPostUrlPdo();
$failures = [];
$check = static function (bool $condition, string $message) use (&$failures): void {
    echo ($condition ? 'PASS' : 'FAIL') . ' ' . $message . PHP_EOL;
    if (!$condition) $failures[] = $message;
};

$GLOBALS['ct_request_locale'] = 'id';
$page = [
    'id' => 10,
    'type' => 'page',
    'slug' => 'admission',
    'ct_locale' => 'id',
    'ct_translated_slug' => 'penerimaan',
];
$article = [
    'id' => 11,
    'type' => 'article',
    'slug' => 'source-article',
    'ct_locale' => 'id',
    'ct_translated_slug' => 'artikel',
];

$check(ct_public_post_url($pdo, $page, 'en') === '/admission/',
    'default-locale Page URL ignores the active translated overlay');
$check(($GLOBALS['ct_request_locale'] ?? null) === 'id',
    'Page URL generation restores the active request locale');
$check(ct_public_post_url($pdo, $article, 'en') === '/source-article/',
    'default-locale Article URL ignores the active translated overlay');
$check(($GLOBALS['ct_request_locale'] ?? null) === 'id',
    'Article URL generation restores the active request locale');

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}
echo "Public post URL contract passed.\n";
