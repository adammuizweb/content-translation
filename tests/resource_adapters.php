<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$failures = [];
$check = static function (bool $ok, string $message) use (&$failures): void {
    echo ($ok ? 'PASS' : 'FAIL') . ' ' . $message . PHP_EOL;
    if (!$ok) $failures[] = $message;
};

$GLOBALS['resourceAdapterFilterCalls'] = 0;
$GLOBALS['resourceAdapterCurrentMode'] = 'none';
$GLOBALS['resourceAdapterAlternateCalls'] = 0;
$GLOBALS['resourceAdapterAlternateMode'] = 'valid';
$GLOBALS['resourceAdapterWorkspace'] = true;
$GLOBALS['resourceAdapterGrant'] = true;
$GLOBALS['resourceAdapterGrantLocks'] = [];

function content_default_locale(): string { return 'en'; }
function ct_content_locales(PDO $pdo): array { return ['en', 'de', 'fr']; }
function ct_enabled_locales(PDO $pdo): array { return ['de', 'fr']; }
function ct_user_can_workspace(PDO $pdo, ?int $userId = null): bool { return !empty($GLOBALS['resourceAdapterWorkspace']); }
function ct_user_has_locale_edit_grant(PDO $pdo, int $userId, string $locale, bool $lock = false): bool {
    $GLOBALS['resourceAdapterGrantLocks'][] = $lock;
    return !empty($GLOBALS['resourceAdapterGrant']);
}
function apply_filters(string $hook, mixed $value): mixed {
    if ($hook !== 'content_translation_resource_adapters') return $value;
    $GLOBALS['resourceAdapterFilterCalls']++;
    return $GLOBALS['resourceAdapterFixtures'];
}

$library = [
    'api_version' => 1,
    'key' => 'example-library',
    'resolve' => static function (PDO $pdo, string $locale, string $path): ?array {
        if ($path === 'library/throws') throw new RuntimeException('resolver failure');
        if ($path === 'library/missing') return ['available' => false];
        if ($path === 'library/bad-route') return ['available' => true, 'resource_id' => 8, 'route_path' => '/unsafe'];
        if ($path === 'library/trailing-route') return ['available' => true, 'resource_id' => 9, 'route_path' => 'library/source/'];
        if ($path === 'library/long-id') return ['available' => true, 'resource_id' => str_repeat('x', CT_RESOURCE_IDENTITY_LIMIT + 1), 'route_path' => 'library/source'];
        if ($path === 'library/item' || $path === 'shared/item') {
            return ['available' => true, 'resource_id' => 7, 'route_path' => 'library/source'];
        }
        return null;
    },
    'current' => static function (PDO $pdo): ?array {
        if ($GLOBALS['resourceAdapterCurrentMode'] === 'throws') throw new RuntimeException('current failure');
        if ($GLOBALS['resourceAdapterCurrentMode'] === 'malformed') return [];
        return in_array($GLOBALS['resourceAdapterCurrentMode'], ['one', 'multiple'], true) ? ['resource_id' => 7] : null;
    },
    'alternates' => static function (PDO $pdo, array $context): array {
        $GLOBALS['resourceAdapterAlternateCalls']++;
        if ($context['resource_id'] === 'throws') throw new RuntimeException('alternate failure');
        if ($GLOBALS['resourceAdapterAlternateMode'] === 'invalid') {
            return [['locale' => 'en', 'path' => '/library/item/', 'query' => '?page=2']];
        }
        return [
            ['locale' => 'en', 'path' => '/library/item/', 'query' => 'view=grid&page=2'],
            ['locale' => 'de', 'path' => '/de/bibliothek/artikel/', 'query' => 'ansicht=raster&page=2'],
        ];
    },
];
$catalog = [
    'api_version' => 1,
    'key' => 'example-catalog',
    'resolve' => static fn(PDO $pdo, string $locale, string $path): ?array => $path === 'shared/item'
        ? ['available' => true, 'resource_id' => 'shared', 'route_path' => 'catalog/source']
        : null,
    'current' => static fn(PDO $pdo): ?array => $GLOBALS['resourceAdapterCurrentMode'] === 'multiple'
        ? ['resource_id' => 'shared']
        : null,
    'alternates' => static fn(PDO $pdo, array $context): array => [],
];
$GLOBALS['resourceAdapterFixtures'] = [$library, $catalog];

require $root . '/includes/resource-adapters.php';

final class ResourceAdapterPdo extends PDO {
    public function __construct() {}
}
$pdo = new ResourceAdapterPdo();

$check(ct_resource_adapter_supports('alternate-query')
    && ct_resource_adapter_supports('locking-locale-grant')
    && !ct_resource_adapter_supports('Alternate-query')
    && !ct_resource_adapter_supports('unknown'),
    'capability discovery advertises only the exact stable adapter extensions');
$check($GLOBALS['resourceAdapterFilterCalls'] === 0, 'adapter registration remains lazy until first use');
$registered = ct_resource_adapters();
$check(array_keys($registered) === ['example-library', 'example-catalog'] && $GLOBALS['resourceAdapterFilterCalls'] === 1,
    'API v1 adapters normalize by stable key and the registry loads once');
$check(ct_normalize_resource_adapters([$library, $library]) === [], 'duplicate adapter keys reject the complete registry');
$malformed = $library;
$malformed['resolve'] = 'not_callable';
$check(ct_normalize_resource_adapters([$malformed]) === [], 'malformed callbacks reject the complete registry');
$wrongVersion = $library;
$wrongVersion['api_version'] = 2;
$check(ct_normalize_resource_adapters([$wrongVersion]) === [], 'unsupported adapter API versions fail closed');
$check(ct_normalize_resource_adapters(array_fill(0, CT_RESOURCE_ADAPTER_LIMIT + 1, $library)) === [], 'adapter count is bounded');

$check(ct_resource_adapter_resolve($pdo, 'de', 'ordinary-post') === null, 'unowned paths fall through');
$resolved = ct_resource_adapter_resolve($pdo, 'de', 'library/item');
$check(($resolved['available'] ?? false) === true
    && ($resolved['resource_id'] ?? null) === '7'
    && ($resolved['route_path'] ?? null) === 'library/source',
    'owned available paths return bounded identity and normalized internal route');
$check((ct_resource_adapter_resolve($pdo, 'de', 'library/trailing-route')['route_path'] ?? null) === 'library/source',
    'internal locale-free route paths remain normalized without a trailing slash');
$unavailable = ct_resource_adapter_resolve($pdo, 'de', 'library/missing');
$check(is_array($unavailable) && ($unavailable['available'] ?? true) === false, 'owned unavailable paths remain distinguishable from unowned paths');
$check((ct_resource_adapter_resolve($pdo, 'de', 'library/bad-route')['available'] ?? true) === false,
    'available results with non-internal routes fail closed');
$check((ct_resource_adapter_resolve($pdo, 'de', 'library/long-id')['available'] ?? true) === false,
    'available results with oversized resource identities fail closed');
$check((ct_resource_adapter_resolve($pdo, 'de', 'library/throws')['available'] ?? true) === false,
    'resolver exceptions fail closed');
$check((ct_resource_adapter_resolve($pdo, 'de', 'shared/item')['available'] ?? true) === false,
    'multiple path owners fail closed');

$GLOBALS['resourceAdapterCurrentMode'] = 'none';
$check(ct_resource_adapter_current($pdo) === null, 'current discovery returns null when no adapter matches');
$GLOBALS['resourceAdapterCurrentMode'] = 'one';
$current = ct_resource_adapter_current($pdo);
$check(($current['adapter_key'] ?? '') === 'example-library' && ($current['locale'] ?? '') === 'en',
    'current discovery creates default-locale resource context');
$GLOBALS['resourceAdapterCurrentMode'] = 'multiple';
$check((ct_resource_adapter_current($pdo)['valid'] ?? true) === false, 'multiple current adapters fail closed');
$GLOBALS['resourceAdapterCurrentMode'] = 'throws';
$check((ct_resource_adapter_current($pdo)['valid'] ?? true) === false, 'current callback exceptions fail closed');

$validRows = ct_resource_adapter_validate_alternates($pdo, [
    ['locale' => 'en', 'path' => '/library/item/'],
    ['locale' => 'de', 'path' => '/de/bibliothek/artikel/', 'query' => 'view=grid&page=2'],
], 'de');
$check(($validRows[0]['path'] ?? '') === '/library/item/'
    && !array_key_exists('query', $validRows[0])
    && ($validRows[1]['path'] ?? '') === '/de/bibliothek/artikel/'
    && ($validRows[1]['query'] ?? '') === 'view=grid&page=2'
    && ($validRows[1]['active'] ?? false) === true,
    'alternate rows preserve public trailing slashes, optional query state, and the active locale');
$check(ct_resource_adapter_compose_path('/de/bibliothek/artikel/', 'view=grid&page=2') === '/de/bibliothek/artikel/?view=grid&page=2'
    && ct_resource_adapter_compose_path('/library/item/', null) === '/library/item/',
    'public path composition adds only a present query string');
$check(ct_resource_adapter_validate_alternates($pdo, [['locale' => 'xx', 'path' => '/xx/item']], 'xx') === null,
    'alternate locales must be configured content locales');
$check(ct_resource_adapter_validate_alternates($pdo, [
    ['locale' => 'en', 'path' => '/one'],
    ['locale' => 'en', 'path' => '/two'],
], 'en') === null, 'duplicate alternate locales fail closed');
$check(ct_resource_adapter_validate_alternates($pdo, [
    ['locale' => 'en', 'path' => '/same'],
    ['locale' => 'de', 'path' => '/same'],
], 'en') === null, 'duplicate alternate paths fail closed');
$check(ct_resource_adapter_validate_alternates($pdo, [
    ['locale' => 'en', 'path' => '/same'],
    ['locale' => 'de', 'path' => '/same/'],
], 'en') === null, 'alternate paths differing only by a trailing slash are canonical duplicates');
$check(ct_resource_adapter_validate_alternates($pdo, [
    ['locale' => 'en', 'path' => '/same/'],
    ['locale' => 'de', 'path' => '/%73ame/'],
], 'en') === null, 'percent-encoded alternate paths with the same route identity are canonical duplicates');
$check(ct_resource_adapter_validate_alternates($pdo, [['locale' => 'en', 'path' => 'https://example.invalid/item']], 'en') === null,
    'alternate paths must be root-relative');
$check(ct_resource_adapter_validate_alternates($pdo, [['locale' => 'en', 'path' => '/../item']], 'en') === null,
    'unsafe alternate traversal fails closed');
foreach (['?page=2', 'page=2 bad', "page=2\n", 'page=2\\bad', 'page=2#bad', 'page=%2'] as $unsafeQuery) {
    $check(ct_resource_adapter_validate_alternates($pdo, [['locale' => 'en', 'path' => '/item/', 'query' => $unsafeQuery]], 'en') === null,
        'unsafe or malformed alternate query fails closed: ' . json_encode($unsafeQuery));
}
$check(ct_resource_adapter_validate_alternates($pdo, [[
    'locale' => 'en',
    'path' => '/item/',
    'query' => str_repeat('a', CT_RESOURCE_QUERY_LIMIT + 1),
]], 'en') === null, 'alternate query length is bounded');
$check(ct_resource_adapter_validate_alternates($pdo, [['locale' => 'de', 'path' => '/de/item']], 'en') === null,
    'alternate rows must include the active locale');
$check(ct_resource_adapter_validate_alternates($pdo, array_fill(0, CT_RESOURCE_ALTERNATE_LIMIT + 1, [
    'locale' => 'en',
    'path' => '/item',
]), 'en') === null, 'alternate row count is bounded');
$check(ct_resource_adapter_alternates($pdo, [
    'valid' => true,
    'available' => true,
    'adapter_key' => 'example-library',
    'resource_id' => 'throws',
    'locale' => 'en',
    'route_path' => null,
]) === null, 'alternate callback exceptions fail closed');

$GLOBALS['resourceAdapterCurrentMode'] = 'one';
unset($GLOBALS['ct_resource_adapter_context_checked'], $GLOBALS['ct_resource_adapter_context']);
$GLOBALS['resourceAdapterAlternateCalls'] = 0;
$metadataA = ct_resource_adapter_metadata_context($pdo);
$metadataB = ct_resource_adapter_metadata_context($pdo);
$check(($metadataA['alternate_rows'] ?? null) === ($metadataB['alternate_rows'] ?? null)
    && $GLOBALS['resourceAdapterAlternateCalls'] === 1,
    'metadata and switcher consumers share one validated alternate-row set per request');
$GLOBALS['resourceAdapterAlternateMode'] = 'invalid';
unset($GLOBALS['ct_resource_adapter_context_checked'], $GLOBALS['ct_resource_adapter_context']);
$check(ct_resource_adapter_metadata_context($pdo) === null,
    'invalid alternates do not claim metadata precedence, leaving built-in fallbacks eligible');
$GLOBALS['resourceAdapterAlternateMode'] = 'valid';
$GLOBALS['resourceAdapterCurrentMode'] = 'malformed';
unset($GLOBALS['ct_resource_adapter_context_checked'], $GLOBALS['ct_resource_adapter_context']);
$check(ct_resource_adapter_metadata_context($pdo) === null,
    'malformed current callbacks leave built-in metadata fallbacks eligible');
$GLOBALS['resourceAdapterCurrentMode'] = 'multiple';
unset($GLOBALS['ct_resource_adapter_context_checked'], $GLOBALS['ct_resource_adapter_context']);
$check(ct_resource_adapter_metadata_context($pdo) === null,
    'ambiguous current callbacks leave built-in metadata fallbacks eligible');
$GLOBALS['resourceAdapterCurrentMode'] = 'throws';
unset($GLOBALS['ct_resource_adapter_context_checked'], $GLOBALS['ct_resource_adapter_context']);
$check(ct_resource_adapter_metadata_context($pdo) === null,
    'throwing current callbacks leave built-in metadata fallbacks eligible');
$GLOBALS['ct_request_locale'] = 'de';
$GLOBALS['resourceAdapterCurrentMode'] = 'throws';
unset($GLOBALS['ct_resource_adapter_context_checked'], $GLOBALS['ct_resource_adapter_context']);
$check(ct_resource_adapter_metadata_context($pdo) === null,
    'default-resource discovery does not claim unrelated alternate-locale requests');
unset($GLOBALS['ct_request_locale'], $GLOBALS['ct_resource_adapter_context_checked'], $GLOBALS['ct_resource_adapter_context']);

$GLOBALS['resourceAdapterGrantLocks'] = [];
$check(ct_external_resource_locale_edit_granted($pdo, 'de', 7)
    && $GLOBALS['resourceAdapterGrantLocks'] === [false],
    'authority helper performs an ordinary locale-grant check by default');
$GLOBALS['resourceAdapterGrantLocks'] = [];
$check(ct_external_resource_locale_edit_granted($pdo, 'de', 7, true)
    && $GLOBALS['resourceAdapterGrantLocks'] === [true],
    'authority helper forwards locking locale-grant checks');
$check(!ct_external_resource_locale_edit_granted($pdo, 'en', 7), 'authority helper rejects the default locale');
$GLOBALS['resourceAdapterWorkspace'] = false;
$check(!ct_external_resource_locale_edit_granted($pdo, 'de', 7), 'authority helper requires CT workspace access');
$GLOBALS['resourceAdapterWorkspace'] = true;
$GLOBALS['resourceAdapterGrant'] = false;
$check(!ct_external_resource_locale_edit_granted($pdo, 'de', 7), 'authority helper requires a CT locale grant');

$bootstrap = (string)file_get_contents($root . '/plugin.php');
$frontend = (string)file_get_contents($root . '/includes/frontend.php');
$resourceInclude = strpos($bootstrap, "includes/resource-adapters.php");
$frontendInclude = strpos($bootstrap, "includes/frontend.php");
$categoryRouting = strpos($frontend, '$categoryMatch =');
$adapterRouting = strpos($frontend, 'ct_resource_adapter_resolve($pdo, $first, $rest)');
$postRouting = strpos($frontend, '$t = ct_find_translation_by_slug');
$check($resourceInclude !== false && $frontendInclude !== false && $resourceInclude < $frontendInclude,
    'bootstrap loads resource adapters before frontend hooks');
$check($categoryRouting !== false && $adapterRouting !== false && $postRouting !== false
    && $categoryRouting < $adapterRouting && $adapterRouting < $postRouting,
    'frontend resolves adapters after built-in category handling and before final post lookup');
$check(str_contains($frontend, "\$GLOBALS['ct_request_locale'] = \$first;")
    && str_contains($frontend, "\$GLOBALS['ct_resource_adapter_context'] = \$resourceContext;")
    && str_contains($frontend, 'ct_render_not_found()'),
    'frontend retains generic context and uses the existing cosmetic 404 path');
$check(substr_count($frontend, 'ct_resource_adapter_metadata_context($pdo)') === 3
    && substr_count($frontend, 'ct_resource_adapter_compose_path(') === 4
    && str_contains($frontend, "count(\$rows) < 2")
    && !str_contains($frontend, "'url' => \$row['path']"),
    'canonical, hreflang, and switcher consistently compose validated alternate paths and queries');

if ($failures !== []) {
    fwrite(STDERR, count($failures) . " assertion(s) failed.\n");
    exit(1);
}
echo "Resource adapter contract passed.\n";
