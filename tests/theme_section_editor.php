<?php
declare(strict_types=1);

$filters = [];
$actions = [];
function add_filter(string $hook, callable $callback, int $priority = 10, int $acceptedArgs = 1): void { global $filters; $filters[$hook][] = $callback; }
function add_action(string $hook, callable $callback, int $priority = 10, int $acceptedArgs = 1): void { global $actions; $actions[$hook][] = $callback; }
function theme_section_name_is_valid(string $name): bool { return strlen($name) <= 120 && preg_match('/\A[a-z][a-z0-9]*(?:[._-][a-z0-9]+)*\z/', $name) === 1; }
function theme_section_safe_url(mixed $value): string {
    $url = trim((string)$value);
    if ($url === '' || preg_match('/[\x00-\x1F\x7F]/', $url)) return '';
    $scheme = parse_url($url, PHP_URL_SCHEME);
    return $scheme === null || in_array(strtolower((string)$scheme), ['http', 'https', 'mailto', 'tel'], true) ? $url : '';
}
function ct_homepage_theme_post(PDO $pdo): ?array { return ['id' => 42]; }

require dirname(__DIR__) . '/includes/theme-section-packages.php';

$failures = [];
$check = static function (bool $condition, string $message) use (&$failures): void {
    echo ($condition ? 'PASS' : 'FAIL') . ' ' . $message . PHP_EOL;
    if (!$condition) $failures[] = $message;
};

$composition = "\n[[widget:theme_section name=\"landing.hero\" title=\"Hello &amp; welcome\" variant=wide]]\n\t[[widget:theme_section name='landing.cta' url=/contact]]\n";
$parsed = ct_parse_theme_section_composition($composition);
$check(is_array($parsed) && array_column($parsed, 'name') === ['landing.hero', 'landing.cta'], 'composition parser preserves exact section order');
$check(($parsed[0]['attrs']['variant'] ?? '') === 'wide' && ($parsed[1]['attrs']['url'] ?? '') === '/contact', 'composition parser preserves declared attributes');
$check(ct_parse_theme_section_composition('before [[widget:theme_section name="landing.hero"]]') === null, 'mixed source content is rejected');
$check(ct_parse_theme_section_composition('[[widget:recent_posts name="landing.hero"]]') === null, 'unknown executable shortcode is rejected');
$check(ct_parse_theme_section_composition('[[widget:theme_section name="landing.hero" broken]]') === null, 'malformed attributes are rejected rather than ignored');
$check(ct_parse_theme_section_composition('[[widget:theme_section name="landing.hero"]][[widget:theme_section name="landing.hero"]]') === null, 'duplicate v1 section identities are rejected');
$check(ct_parse_theme_section_composition('[[widget:theme_section name="landing.hero" title="[[widget:x]]"]]') === null, 'nested executable attribute content is rejected');
$check(ct_parse_theme_section_composition('[[widget:theme_section name="landing.hero" title="bad ] value"]]') === null, 'composition follows the Core shortcode closing-bracket grammar');
$spacedComposition = ct_parse_theme_section_composition("[[ \nwidget:theme_section name=\"landing.hero\"]]");
$check(is_array($spacedComposition) && ($spacedComposition[0]['name'] ?? '') === 'landing.hero', 'composition accepts Core whitespace after the opening brackets');

$sourceA = [
    ['name' => 'landing.hero', 'attrs' => ['name' => 'landing.hero', 'variant' => 'wide'], 'source_fingerprint' => str_repeat('a', 64)],
    ['name' => 'landing.cta', 'attrs' => ['name' => 'landing.cta'], 'source_fingerprint' => str_repeat('b', 64)],
];
$sourceARekeyed = $sourceA;
$sourceARekeyed[0]['attrs'] = ['variant' => 'wide', 'name' => 'landing.hero'];
$fingerprint = ct_theme_section_composition_fingerprint('example', $sourceA);
$check($fingerprint === ct_theme_section_composition_fingerprint('example', $sourceARekeyed), 'composition fingerprint canonicalizes attribute key order');
$check($fingerprint !== ct_theme_section_composition_fingerprint('example', array_reverse($sourceA)), 'composition fingerprint includes section order');
$changedSource = $sourceA;
$changedSource[0]['source_fingerprint'] = str_repeat('c', 64);
$check($fingerprint !== ct_theme_section_composition_fingerprint('example', $changedSource), 'composition fingerprint includes Core source fingerprints');
$check(ct_theme_section_source_state(null, $fingerprint) === 'unverified', 'rows without metadata are unverified');
$check(ct_theme_section_source_state($fingerprint, $fingerprint) === 'current', 'matching source metadata is current');
$check(ct_theme_section_source_state(str_repeat('0', 64), $fingerprint) === 'stale', 'changed source metadata is stale');

$safeHtml = '<section class="hero" data-layout="wide" aria-label="Hero" style="--gap: 1rem; background-image:url(https://cdn.example/image.png)"><picture><source srcset="/one.webp 1x, /two.webp 2x"><img src="/hero.jpg" alt="Hero" loading="lazy"></picture><svg viewBox="0 0 24 24"><path d="M1 1h2"></path></svg></section>';
$submitted = [
    ['name' => 'landing.hero', 'title' => 'Hallo', 'summary' => 'Zusammenfassung', 'url' => '/de/', 'link_label' => 'Weiter', 'html' => $safeHtml],
    ['name' => 'landing.cta', 'title' => 'Kontakt', 'summary' => 'Sprechen Sie mit uns.', 'url' => 'mailto:hello@example.com', 'link_label' => 'Kontakt', 'html' => "<section>\n  <a href=\"mailto:hello@example.com\">Kontakt</a>\n</section>"],
];
$package = ct_build_theme_section_package('example', $sourceA, $submitted);
$check(($package['sections']['landing.hero']['html'] ?? '') === $safeHtml, 'package build preserves accepted raw HTML bytes');
$check(($package['sections']['landing.hero']['sha256'] ?? '') === hash('sha256', $safeHtml), 'package build calculates section hashes server-side');
$check(($package['source_sha256'] ?? '') === hash('sha256', $submitted[0]['html'] . $submitted[1]['html']), 'package build calculates aggregate hash in order');
$encoded = json_encode($package, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
$decoded = ct_decode_theme_section_package($encoded);
$rebuilt = ct_build_theme_section_package('example', $sourceA, array_map(static function (string $name) use ($decoded): array {
    $section = $decoded['sections'][$name];
    return ['name' => $name, 'html' => $section['html']] + $section['fallback'];
}, ['landing.hero', 'landing.cta']));
$check($decoded !== null && $rebuilt === $package, 'unchanged valid v1 package round trip preserves order and runtime semantics');
$check(ct_theme_section_package_composition($rebuilt) === ct_theme_section_package_composition($package), 'unchanged v1 composition output is stable');

$legacyPackage = $package;
$legacyHtml = '<section onclick="legacyAction()"><h1>Legacy</h1><script>window.legacyPackage = true;</script></section>';
$legacyPackage['sections']['landing.hero']['html'] = $legacyHtml;
$legacyPackage['sections']['landing.hero']['sha256'] = hash('sha256', $legacyHtml);
$legacyPackage['source_sha256'] = hash('sha256', implode('', array_column($legacyPackage['sections'], 'html')));
$legacyEncoded = json_encode($legacyPackage, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
$legacyDecoded = ct_decode_theme_section_package($legacyEncoded);
$check($legacyDecoded !== null, 'hash-valid legacy v1 package containing script and event markup still decodes');
$legacySubmitted = array_map(static function (string $name) use ($legacyDecoded): array {
    $section = $legacyDecoded['sections'][$name];
    return ['name' => $name, 'html' => $section['html']] + $section['fallback'];
}, ['landing.hero', 'landing.cta']);
$legacyRoundTrip = ct_build_theme_section_package('example', $sourceA, $legacySubmitted, $legacyDecoded);
$check($legacyRoundTrip === $legacyPackage, 'unchanged unsafe legacy section bytes can round trip through the builder');
$check(ct_theme_section_package_composition($legacyRoundTrip) === ct_theme_section_package_composition($legacyPackage), 'legacy v1 runtime composition remains stable');
$legacyEditedElsewhere = $legacySubmitted;
$legacyEditedElsewhere[0]['summary'] = 'Updated semantic fallback.';
$legacyEditedElsewhere[1]['html'] = '<section><h2>Safely updated CTA</h2></section>';
$legacyMixedSave = ct_build_theme_section_package('example', $sourceA, $legacyEditedElsewhere, $legacyDecoded);
$check(($legacyMixedSave['sections']['landing.hero']['html'] ?? '') === $legacyHtml
    && ($legacyMixedSave['sections']['landing.hero']['fallback']['summary'] ?? '') === 'Updated semantic fallback.'
    && ($legacyMixedSave['sections']['landing.cta']['html'] ?? '') === $legacyEditedElsewhere[1]['html'],
    'legacy unsafe bytes permit fallback edits and changes to other safe sections');
$legacyChangeRejected = false;
$changedLegacy = $legacySubmitted;
$changedLegacy[0]['html'] .= ' ';
try {
    ct_build_theme_section_package('example', $sourceA, $changedLegacy, $legacyDecoded);
} catch (InvalidArgumentException $e) {
    $legacyChangeRejected = true;
}
$check($legacyChangeRejected, 'changing grandfathered unsafe HTML bytes is rejected');
$newUnsafeRejected = false;
try {
    ct_build_theme_section_package('example', $sourceA, $legacySubmitted);
} catch (InvalidArgumentException $e) {
    $newUnsafeRejected = true;
}
$check($newUnsafeRejected, 'new unsafe package HTML is rejected by the builder');

$unsafe = [
    '<?php echo 1; ?>',
    '<section>[[widget:recent_posts]]</section>',
    '<script>alert(1)</script>',
    '<style>@import "bad";</style>',
    '<iframe src="/x"></iframe>',
    '<object data="/x"></object>',
    '<embed src="/x">',
    '<form action="/x"><input name="x"></form>',
    '<img src="/x" onerror="alert(1)">',
    '<div srcdoc="&lt;script&gt;"></div>',
    '<a formaction="/x">x</a>',
    '<a href="jav&#x61;script:alert(1)">x</a>',
    '<a href="jav&amp;#x61;script:alert(1)">x</a>',
    '<img src="data:text/html;base64,abc">',
    '<img dynsrc="javascript:alert(1)">',
    '<svg><animate attributeName="href" values="javascript:alert(1)"></animate></svg>',
    '<svg><path fill="url(javascript:alert(1))"></path></svg>',
    '<div style="width:expression(alert(1))">x</div>',
    '<div style="background:url(javascript:alert(1))">x</div>',
    '<div style="background:url(jav&amp;#x61;script:alert(1))">x</div>',
    '<div style="background:url(data:text/html;base64,abc)">x</div>',
    '<div style="@import url(/bad.css)">x</div>',
    '<!--><script>alert(1)</script>-->',
    '<!-- harmless -->',
    '<plaintext><img src=x onerror=alert(1)>',
];
foreach ($unsafe as $index => $html) {
    $check(ct_validate_theme_section_html($html) !== null, 'unsafe HTML/CSS/URL case ' . ($index + 1) . ' is rejected');
}
$check(ct_validate_theme_section_html($safeHtml) === null, 'normal structural, responsive-image, SVG, data/ARIA, and CSS-variable markup is accepted');

$largeSource = [];
$largeSubmitted = [];
for ($index = 0; $index < 3; $index++) {
    $name = 'large.section-' . $index;
    $largeSource[] = ['name' => $name, 'source_fingerprint' => str_repeat((string)($index + 1), 64)];
    $largeSubmitted[] = ['name' => $name, 'title' => 'Large', 'summary' => 'Large', 'url' => '', 'link_label' => '', 'html' => '<section>' . str_repeat('x', 700000) . '</section>'];
}
$largePackageRejected = false;
try {
    ct_build_theme_section_package('example', $largeSource, $largeSubmitted);
} catch (InvalidArgumentException $e) {
    $largePackageRejected = str_contains($e->getMessage(), 'too large');
}
$check($largePackageRejected, 'builder rejects packages that exceed the v1 reader aggregate limit');

$longFallbackPackage = $package;
$longFallbackPackage['sections']['landing.hero']['fallback']['title'] = str_repeat('L', 1001);
$longFallbackEncoded = json_encode($longFallbackPackage, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
$longFallbackDecoded = ct_decode_theme_section_package($longFallbackEncoded);
$longFallbackSubmitted = $submitted;
$longFallbackSubmitted[0]['title'] = str_repeat('L', 1001);
$longFallbackRoundTrip = ct_build_theme_section_package('example', $sourceA, $longFallbackSubmitted, $longFallbackDecoded);
$check(($longFallbackRoundTrip['sections']['landing.hero']['fallback']['title'] ?? '') === str_repeat('L', 1001), 'unchanged oversized legacy fallback can round trip');
$longFallbackSubmitted[0]['title'] .= 'x';
$longFallbackChangeRejected = false;
try {
    ct_build_theme_section_package('example', $sourceA, $longFallbackSubmitted, $longFallbackDecoded);
} catch (InvalidArgumentException $e) {
    $longFallbackChangeRejected = true;
}
$check($longFallbackChangeRejected, 'changed oversized legacy fallback is rejected');

$row = ['id' => 1, 'post_id' => 42, 'locale' => 'de', 'content' => $encoded, 'title' => 'A', 'slug' => '', 'meta_description' => 'B', 'status' => 'draft'];
$token = ct_translation_row_state_token($row);
$changedRow = $row;
$changedRow['title'] = 'Concurrent edit';
$check($token !== ct_translation_row_state_token($changedRow), 'optimistic token changes with translation row state');
$check($token === ct_translation_row_state_token($row), 'optimistic token is deterministic');
$sourceConflict = false;
try {
    ct_assert_theme_section_editor_state(str_repeat('a', 64), str_repeat('b', 64), $token, $row);
} catch (RuntimeException $e) {
    $sourceConflict = str_contains($e->getMessage(), 'source Theme Template changed');
}
$check($sourceConflict, 'optimistic lock rejects a source fingerprint changed after page load');
$rowConflict = false;
try {
    ct_assert_theme_section_editor_state(str_repeat('a', 64), str_repeat('a', 64), $token, $changedRow);
} catch (RuntimeException $e) {
    $rowConflict = str_contains($e->getMessage(), 'another editor');
}
$check($rowConflict, 'optimistic lock rejects concurrent translation row changes');
$pdo = (new ReflectionClass(PDO::class))->newInstanceWithoutConstructor();
$check(ct_is_homepage_post($pdo, 42) && !ct_is_homepage_post($pdo, 41), 'homepage identity allows the dedicated empty-slug behavior');

if ($failures !== []) {
    fwrite(STDERR, count($failures) . " assertion(s) failed.\n");
    exit(1);
}
echo "RESULT: ALL PASS\n";
