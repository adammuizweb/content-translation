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
function theme_section_name_is_valid(string $name): bool {
    return strlen($name) <= 120 && preg_match('/\A[a-z][a-z0-9]*(?:[._-][a-z0-9]+)*\z/', $name) === 1;
}
function theme_section_safe_url(mixed $value): string {
    $url = trim((string)$value);
    if ($url === '' || preg_match('/[\x00-\x1F\x7F]/', $url)) return '';
    $scheme = parse_url($url, PHP_URL_SCHEME);
    return $scheme === null || in_array(strtolower((string)$scheme), ['http', 'https', 'mailto', 'tel'], true) ? $url : '';
}

require dirname(__DIR__) . '/includes/theme-section-packages.php';

$failures = [];
$check = static function (bool $condition, string $message) use (&$failures): void {
    echo ($condition ? 'PASS' : 'FAIL') . ' ' . $message . PHP_EOL;
    if (!$condition) $failures[] = $message;
};

$html = ['landing.hero' => '<section><h1>Welcome</h1></section>', 'landing.cta' => '<section><h2>Continue</h2></section>'];
$sections = [];
foreach ($html as $name => $sectionHtml) {
    $sections[$name] = [
        'html' => $sectionHtml,
        'fallback' => [
            'title' => $name === 'landing.hero' ? 'Welcome' : 'Continue',
            'summary' => 'Generic semantic fallback.',
            'url' => $name === 'landing.cta' ? 'https://example.com/continue' : '',
            'link_label' => $name === 'landing.cta' ? 'Continue' : '',
        ],
        'sha256' => hash('sha256', $sectionHtml),
    ];
}
$package = [
    'format' => ct_theme_section_package_format(),
    'theme_folder' => 'example',
    'composition' => 'theme-sections-v1',
    'source_sha256' => hash('sha256', implode('', $html)),
    'sections' => $sections,
];
$encoded = json_encode($package, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

$check(ct_decode_theme_section_package($encoded) !== null, 'valid generic Theme Section package is accepted');
$composition = ct_theme_section_package_composition($package);
$check(substr_count($composition, '[[widget:theme_section') === 2, 'package produces one shortcode per ordered section');
$check(str_contains($composition, 'link_label="Continue"'), 'semantic CTA fallback is serialized into the composition');
$post = ct_apply_theme_section_package(['ct_locale' => 'id', 'content' => $encoded]);
$check(isset($post['ct_theme_section_package']) && $post['content'] === $composition, 'localized package overlays Theme Template composition');

$tampered = $package;
$tampered['sections']['landing.hero']['html'] .= 'edited';
$check(ct_decode_theme_section_package(json_encode($tampered, JSON_THROW_ON_ERROR)) === null, 'stale section hash is rejected');
$tampered = $package;
$tampered['source_sha256'] = str_repeat('0', 64);
$check(ct_decode_theme_section_package(json_encode($tampered, JSON_THROW_ON_ERROR)) === null, 'stale aggregate hash is rejected');
$tampered = $package;
$tampered['sections']['../landing'] = $tampered['sections']['landing.hero'];
$check(ct_decode_theme_section_package(json_encode($tampered, JSON_THROW_ON_ERROR)) === null, 'invalid section name is rejected');
$tampered = $package;
$tampered['sections']['landing.hero']['html'] .= '<?php';
$tampered['sections']['landing.hero']['sha256'] = hash('sha256', $tampered['sections']['landing.hero']['html']);
$tampered['source_sha256'] = hash('sha256', implode('', array_column($tampered['sections'], 'html')));
$check(ct_decode_theme_section_package(json_encode($tampered, JSON_THROW_ON_ERROR)) === null, 'PHP payload is rejected');
$tampered = $package;
$tampered['sections']['landing.cta']['fallback']['url'] = 'javascript:alert(1)';
$check(ct_decode_theme_section_package(json_encode($tampered, JSON_THROW_ON_ERROR)) === null, 'unsafe fallback URL is rejected');

$check(isset($filters['theme_post_data'][20]), 'direct Theme Template package hook is registered');
$check(isset($filters['theme_slot_post_data'][20]), 'assigned Theme Template package hook is registered');
$check(isset($filters['theme_section_html'][20]), 'theme-scoped localized HTML hook is registered');
$check(isset($actions['init'][18]), 'assigned homepage sitemap renderer is registered');

if ($failures !== []) exit(1);
echo "RESULT: ALL PASS\n";
