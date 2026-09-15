<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$core = getenv('CORE_ROOT') ?: getenv('JY_ROOT');
if ($core === false || $core === '') {
    $consumerRoot = dirname($root, 2);
    if (is_file($consumerRoot . '/cfg/helpers/hooks.php')) {
        $core = $consumerRoot;
    } else {
        $candidate = $root;
        $core = '';
        for ($depth = 0; $depth < 6; $depth++) {
            $candidate = dirname($candidate);
            if (is_file($candidate . '/jyavani.lan/cfg/helpers/hooks.php')) {
                $core = $candidate . '/jyavani.lan';
                break;
            }
        }
    }
}
if ($core === '') throw new RuntimeException('Compatible Core test root not found.');
$publicFixture = sys_get_temp_dir() . '/ct-media-public-' . bin2hex(random_bytes(8));
mkdir($publicFixture . '/static/img', 0770, true);
define('PUBLIC_PATH', $publicFixture);
$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true);
file_put_contents($publicFixture . '/static/img/one.png', $png);
file_put_contents($publicFixture . '/static/img/two.png', $png);
register_shutdown_function(static function () use ($publicFixture): void {
    @unlink($publicFixture . '/static/img/one.png');
    @unlink($publicFixture . '/static/img/two.png');
    @rmdir($publicFixture . '/static/img');
    @rmdir($publicFixture . '/static');
    @rmdir($publicFixture);
});
require_once $core . '/cfg/helpers/hooks.php';
require_once $core . '/cfg/helpers/resource_lifecycle.php';
require_once $core . '/cfg/helpers/asset_lifecycle.php';
require_once $core . '/cfg/helpers/media_helpers.php';

function content_default_locale(): string { return 'en'; }
function content_locale_presets(): array { return ['en' => 'English', 'id' => 'Indonesian', 'de' => 'German']; }
function __(string $source): string { return $source; }
function ct_content_locales(PDO $pdo): array { return ['en', 'id', 'de']; }
function ct_post_source_locale(PDO $pdo, array $post): string { return 'id'; }
function ct_translation_row_state_token(?array $row): string { return hash('sha256', json_encode($row ?? ['missing' => true])); }
function ct_current_user_id(): int { return 7; }
function ct_user_can_workspace(PDO $pdo, ?int $userId = null): bool { return true; }
function user_can(PDO $pdo, int $userId, string $permission, array $context = []): bool { return true; }
function ct_user_has_locale_edit_grant(PDO $pdo, int $userId, string $locale, bool $lock = false): bool {
    $GLOBALS['ct_test_grants'][] = [$locale, $lock];
    return true;
}
require_once dirname(__DIR__) . '/includes/media.php';

$failures = [];
$checks = 0;
$check = static function (bool $condition, string $message) use (&$failures, &$checks): void {
    $checks++;
    echo ($condition ? 'PASS' : 'FAIL') . ' ' . $message . PHP_EOL;
    if (!$condition) $failures[] = $message;
};
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE media (id INTEGER PRIMARY KEY, url TEXT, filename TEXT, mime TEXT, ext TEXT, size INTEGER, width INTEGER, height INTEGER, title TEXT, alt TEXT, caption TEXT, credit TEXT, target_url TEXT, target_attribute TEXT, visibility TEXT, storage_disk TEXT, storage_path TEXT, access_scope TEXT, is_downloadable INTEGER, user_id INTEGER, is_deleted INTEGER)');
$pdo->exec('CREATE TABLE ct_media_profiles (media_id INTEGER PRIMARY KEY, metadata_source_locale TEXT, availability_policy TEXT, source_fingerprint TEXT, created_by INTEGER, updated_by INTEGER, created_at TEXT, updated_at TEXT)');
$pdo->exec('CREATE TABLE ct_media_available_locales (media_id INTEGER, locale TEXT, PRIMARY KEY(media_id, locale))');
$pdo->exec('CREATE TABLE ct_media_translations (media_id INTEGER, locale TEXT, title TEXT NULL, alt TEXT NULL, alt_mode TEXT, caption TEXT NULL, credit TEXT NULL, status TEXT, source_fingerprint TEXT, PRIMARY KEY(media_id, locale))');
$pdo->exec('CREATE TABLE ct_media_aliases (media_id INTEGER, locale TEXT, slug TEXT, created_by INTEGER, updated_by INTEGER, created_at TEXT, updated_at TEXT, PRIMARY KEY(media_id, locale), UNIQUE(locale, slug))');
$pdo->exec('CREATE TABLE ct_post_featured_media (post_id INTEGER, locale TEXT, role TEXT, mode TEXT, media_id INTEGER NULL, alt_override TEXT NULL, caption_override TEXT NULL, source_fingerprint TEXT, updated_by INTEGER, created_at TEXT, updated_at TEXT, PRIMARY KEY(post_id, locale, role))');
$pdo->exec('CREATE TABLE posts (id INTEGER PRIMARY KEY, is_deleted INTEGER)');
$pdo->exec('CREATE TABLE post_translations (post_id INTEGER, locale TEXT, status TEXT)');
$pdo->exec("INSERT INTO media VALUES (1, '/media/one.jpg', 'one.jpg', 'image/jpeg', 'jpg', 1, 10, 10, 'Asli', 'Alt asli', 'Caption asli', 'Credit asli', NULL, NULL, 'public', 'public', 'one.png', 'public', 1, 1, 0)");
$row = media_load_live($pdo, 1);
$fingerprint = ct_media_source_fingerprint($row);
$pdo->prepare("INSERT INTO ct_media_profiles VALUES (1, 'id', 'all', ?, 1, 1, NULL, NULL)")->execute([$fingerprint]);
$pdo->prepare("INSERT INTO ct_media_translations VALUES (1, 'de', NULL, NULL, 'decorative', '', NULL, 'published', ?)")->execute([$fingerprint]);

$check(ct_localized_media_state_exists($pdo), 'plugin state preflight detects localized media without running schema DDL');
$check(ct_media_is_available($pdo, 1, 'de'), 'all-locale profile is available');
$projected = media_filter_data($pdo, $row, ['content_locale' => 'de']);
$check($projected['title'] === 'Asli' && $projected['alt'] === '' && $projected['caption'] === '', 'published overlay preserves null fallback and explicit empty/decorative values');
$pdo->exec("UPDATE ct_media_profiles SET availability_policy = 'selected'");
$pdo->exec("INSERT INTO ct_media_available_locales VALUES (1, 'id')");
$check(!ct_media_is_available($pdo, 1, 'de') && ct_media_is_available($pdo, 1, 'id'), 'selected availability is locale-specific');
$unavailable = media_filter_data($pdo, $row, ['content_locale' => 'de']);
$check($unavailable['alt'] === 'Alt asli'
    && $unavailable['extensions']['content_translation']['state'] === 'unavailable'
    && $unavailable['extensions']['content_translation']['state_label'] === 'Unavailable',
    'unavailable locale receives no metadata overlay and a localized diagnostic state');
$pdo->exec("UPDATE ct_media_available_locales SET locale = 'de'");
$pdo->exec("UPDATE ct_media_translations SET source_fingerprint = '" . str_repeat('0', 64) . "'");
$stale = media_filter_data($pdo, $row, ['content_locale' => 'de']);
$check($stale['alt'] === 'Alt asli' && $stale['extensions']['content_translation']['state'] === 'stale', 'stale published metadata falls back to the original with a stale diagnostic');
$pdo->prepare('UPDATE ct_media_translations SET source_fingerprint = ?')->execute([$fingerprint]);
$ready = media_filter_data($pdo, $row, ['content_locale' => 'de']);
$check($ready['extensions']['content_translation']['state'] === 'ready'
    && $ready['extensions']['content_translation']['locale_name'] === 'German (de)', 'current published metadata reports a named ready state');
$pdo->exec("UPDATE ct_media_translations SET status = 'draft'");
$check(media_filter_data($pdo, $row, ['content_locale' => 'de'])['extensions']['content_translation']['state'] === 'draft', 'draft metadata has a distinct picker diagnostic');
$pdo->exec("UPDATE ct_media_translations SET status = 'published'");

$post = ['id' => 8, 'thumbnail_media_id' => 1, 'thumbnail' => '/legacy.jpg', 'youtube' => 'https://youtu.be/abcdefghijk'];
$source = media_filter_data($pdo, $row, ['content_locale' => 'de']);
$pdo->prepare("INSERT INTO ct_post_featured_media VALUES (8, 'de', 'featured', 'none', NULL, NULL, NULL, ?, 1, NULL, NULL)")->execute([ct_featured_source_fingerprint($post)]);
$check(apply_filters('featured_media', $source, $post, ['content_locale' => 'de'], $pdo) === null, 'none mode suppresses featured media');
$pdo->exec("UPDATE ct_post_featured_media SET mode = 'inherit'");
$check(apply_filters('featured_media', $source, $post, ['content_locale' => 'de'], $pdo)['id'] === 1, 'inherit mode preserves Core featured media');
$pdo->exec("UPDATE ct_media_available_locales SET locale = 'id'");
$check(apply_filters('featured_media', $source, $post, ['content_locale' => 'de'], $pdo) === null, 'inherit mode enforces target-locale availability');
$pdo->exec("UPDATE ct_media_available_locales SET locale = 'de'");
$pdo->exec("UPDATE ct_post_featured_media SET mode = 'media', media_id = 1, alt_override = '', caption_override = 'Use-site'");
$selected = apply_filters('featured_media', null, $post, ['content_locale' => 'de'], $pdo);
$check($selected['id'] === 1 && $selected['alt'] === '' && $selected['caption'] === 'Use-site', 'media mode applies nullable use-site overrides');
$check(apply_filters('featured_media', $source, $post, ['content_locale' => 'id'], $pdo) === $source, 'the actual post source locale bypasses localized featured selections');
$pdo->exec("UPDATE media SET visibility = 'private', storage_disk = 'private', access_scope = 'editorial'");
$check(apply_filters('featured_media', null, $post, ['content_locale' => 'de'], $pdo) === null, 'private selected media is rejected at runtime');
$pdo->exec("UPDATE media SET visibility = 'public', storage_disk = 'public', access_scope = 'public', is_deleted = 1");
$check(apply_filters('featured_media', null, $post, ['content_locale' => 'de'], $pdo) === null, 'trashed selected media is rejected at runtime');

$selection = ct_featured_selection($pdo, 8, 'de');
$state = ct_translation_editor_state(['id' => 2, 'title' => 'Text'], $selection);
$changed = $selection;
$changed['caption_override'] = 'Other';
$check($state !== ct_translation_editor_state(['id' => 2, 'title' => 'Text'], $changed), 'featured selection participates in optimistic editor state');
$pdo->exec("UPDATE media SET is_deleted = 0");
$pdo->exec("INSERT INTO posts VALUES (8, 0)");
$pdo->exec("INSERT INTO post_translations VALUES (8, 'de', 'published')");
$blocked = false;
try {
    do_action('resource_lifecycle_before_mutation', ['resource' => 'media', 'operation' => 'purge', 'items' => [['id' => 1]]], new ResourceLifecycleDatabase($pdo));
} catch (RuntimeException $error) {
    $blocked = str_contains($error->getMessage(), 'published localized representation');
}
$check($blocked, 'purge is blocked while published localized content actively selects media');
$beforeTrash = ct_media_profile($pdo, 1);
do_action('resource_lifecycle_before_mutation', ['resource' => 'media', 'operation' => 'trash', 'items' => [['id' => 1]]], new ResourceLifecycleDatabase($pdo));
do_action('resource_lifecycle_before_mutation', ['resource' => 'media', 'operation' => 'restore', 'items' => [['id' => 1]]], new ResourceLifecycleDatabase($pdo));
$check(ct_media_profile($pdo, 1) === $beforeTrash, 'trash retains and restore resumes localized media state');

$row = media_load_live($pdo, 1);
$profile = ct_media_profile($pdo, 1);
$available = ct_media_available_locales($pdo, 1);
$translation = ct_media_translation($pdo, 1, 'de');
$fields = [
    'metadata_source_locale' => 'en', 'availability_policy' => 'selected', 'available_locales' => ['de'],
    'profile_state' => ct_media_profile_state($profile, $available), 'translation_locale' => 'de',
    'translation_state' => ct_media_translation_state($translation), 'translation_operation' => 'save',
    'alt_mode' => 'decorative', 'translation_status' => 'draft',
];
$GLOBALS['ct_test_grants'] = [];
$pdo->beginTransaction();
$metadata = media_mutation_metadata($pdo, 'update', $row, ['media_extension' => ['content-translation' => $fields]], ['content_locale' => 'de']);
$pdo->rollBack();
$grantLocales = array_column($GLOBALS['ct_test_grants'], 0);
$check(in_array('id', $grantLocales, true) && in_array('en', $grantLocales, true) && in_array('de', $grantLocales, true)
    && array_filter($GLOBALS['ct_test_grants'], static fn(array $grant): bool => $grant[1]) !== [],
    'source reclassification reauthorizes old source, new source, and target locale under transaction locks');

$pdo->exec("INSERT INTO media VALUES (2, '/media/two.jpg', 'two.jpg', 'image/jpeg', 'jpg', 1, 10, 10, 'Deutsch', 'Alt', '', '', NULL, NULL, 'public', 'public', 'two.png', 'public', 1, 1, 0)");
$newRow = media_load_live($pdo, 2);
$GLOBALS['ct_test_grants'] = [];
$pdo->beginTransaction();
$contextualCreate = media_mutation_metadata($pdo, 'create', $newRow, [], [
    'surface' => 'admin.content.translation', 'content_locale' => 'de',
]);
$pdo->rollBack();
$contextualGrants = array_column($GLOBALS['ct_test_grants'], 0);
$check($contextualCreate['content_translation']['profile']['metadata_source_locale'] === 'de'
    && $contextualCreate['content_translation']['profile']['availability_policy'] === 'all'
    && $contextualGrants === ['de'],
    'contextual upload uses only the content locale and does not require an unrelated default-locale grant');

$pdo->prepare("INSERT INTO ct_media_profiles VALUES (2, 'de', 'all', ?, 7, 7, NULL, NULL)")->execute([ct_media_source_fingerprint($newRow)]);
$response = media_mutation_response($pdo, 'update', $newRow, ['surface' => 'admin.content.translation', 'content_locale' => 'id']);
$tokens = $response['extensions']['content_translation'] ?? [];
$check(preg_match('/^[a-f0-9]{64}$/', (string)($tokens['profile_state'] ?? '')) === 1
    && preg_match('/^[a-f0-9]{64}$/', (string)($tokens['translation_state'] ?? '')) === 1
    && ($response['media']['extensions']['content_translation']['state'] ?? null) === 'source_fallback',
    'mutation response returns refreshed profile and active translation tokens alongside refreshed media');

$aliasFields = [
    'media_alias_locale' => 'id', 'media_alias_slug' => 'perpustakaan-kampus',
    'media_alias_state' => ct_media_alias_state(null),
];
$pdo->beginTransaction();
$aliasMetadata = media_mutation_metadata($pdo, 'update', $newRow, ['media_extension' => ['content-translation' => $aliasFields]], [
    'surface' => 'admin.content.translation', 'content_locale' => 'id',
]);
$check(($aliasMetadata['core_fields']['title'] ?? null) === 'Deutsch'
    && ($aliasMetadata['core_fields']['alt'] ?? null) === 'Alt',
    'contextual target saves restore source metadata server-side rather than trusting browser fields');
do_action('resource_lifecycle_before_commit', [
    'resource' => 'media', 'operation' => 'update', 'actor_id' => 7,
    'metadata' => $aliasMetadata, 'items' => [['after' => $newRow]],
], new ResourceLifecycleDatabase($pdo));
$aliasProjection = media_filter_data($pdo, $newRow, ['content_locale' => 'id']);
$check((ct_media_alias($pdo, 2, 'id')['slug'] ?? '') === 'perpustakaan-kampus'
    && $aliasProjection['url'] === '/id/media/perpustakaan-kampus/'
    && ($aliasProjection['extensions']['content_translation']['permalink'] ?? '') === $aliasProjection['url'],
    'localized media alias keeps one media identity while projecting a language-specific URL');
$pdo->rollBack();

$unmanagedAliasRejected = false;
try {
    ct_media_mutation_payload($pdo, $aliasFields, [], array_replace($newRow, ['storage_path' => 'missing.png']), [
        'profile' => ct_media_profile($pdo, 2), 'available_locales' => [], 'translations' => [], 'aliases' => [],
    ]);
} catch (InvalidArgumentException $error) {
    $unmanagedAliasRejected = str_contains($error->getMessage(), 'locally managed public image');
}
$check($unmanagedAliasRejected, 'media aliases reject external, missing, or otherwise unmanaged public images');
$unavailableAliasRejected = false;
try {
    ct_media_mutation_payload($pdo, $aliasFields, [], $newRow, [
        'profile' => array_replace(ct_media_profile($pdo, 2), ['availability_policy' => 'selected']),
        'available_locales' => [], 'translations' => [], 'aliases' => [],
    ]);
} catch (InvalidArgumentException $error) {
    $unavailableAliasRejected = str_contains($error->getMessage(), 'available in that language');
}
$check($unavailableAliasRejected, 'new media aliases require effective availability in their language');
$legacyExternal = array_replace($newRow, ['url' => 'https://cdn.example/image.png', 'storage_path' => 'missing.png']);
$check(ct_media_legacy_alias_redirect_url($legacyExternal) === 'https://cdn.example/image.png'
    && ct_media_legacy_alias_redirect_url(array_replace($legacyExternal, ['url' => '/static/img/missing.png'])) === null,
    'legacy compatibility redirects only absolute public HTTP media and never unresolved local paths');

$standaloneResponse = media_mutation_response($pdo, 'update', $newRow, ['surface' => 'admin.media.detail'], [], [
    'content_translation' => ['translation' => ['locale' => 'id']],
]);
$check(($standaloneResponse['extensions']['content_translation']['translation_locale'] ?? null) === 'id'
    && preg_match('/^[a-f0-9]{64}$/', (string)($standaloneResponse['extensions']['content_translation']['translation_state'] ?? '')) === 1,
    'standalone metadata saves refresh the submitted translation locale token');

$deleteAndAlias = $aliasFields + [
    'translation_locale' => 'id', 'translation_state' => ct_media_translation_state(null),
    'translation_operation' => 'delete',
];
$deletePayload = ct_media_mutation_payload($pdo, $deleteAndAlias, [], $newRow, [
    'profile' => ct_media_profile($pdo, 2), 'available_locales' => [], 'translations' => [], 'aliases' => [],
]);
$check(($deletePayload['translation']['operation'] ?? '') === 'delete' && isset($deletePayload['alias']),
    'translation deletion does not discard a media alias mutation from the same form');

$staleProfile = $fields;
$staleProfile['profile_state'] = str_repeat('0', 64);
$pdo->beginTransaction();
$staleProfileRejected = false;
try {
    media_mutation_metadata($pdo, 'update', $row, ['media_extension' => ['content-translation' => $staleProfile]], ['content_locale' => 'de']);
} catch (RuntimeException $error) {
    $staleProfileRejected = str_contains($error->getMessage(), 'profile was changed');
}
$pdo->rollBack();
$check($staleProfileRejected, 'stale media profile and availability submissions are rejected');

$staleTranslation = $fields;
$staleTranslation['translation_state'] = str_repeat('0', 64);
$pdo->beginTransaction();
$staleTranslationRejected = false;
try {
    media_mutation_metadata($pdo, 'update', $row, ['media_extension' => ['content-translation' => $staleTranslation]], ['content_locale' => 'de']);
} catch (RuntimeException $error) {
    $staleTranslationRejected = str_contains($error->getMessage(), 'translation was changed');
}
$pdo->rollBack();
$check($staleTranslationRejected, 'stale media translation submissions are rejected');

$conflict = $fields;
$conflict['metadata_source_locale'] = 'de';
$conflict['translation_locale'] = 'en';
$conflict['translation_state'] = ct_media_translation_state(null);
$pdo->beginTransaction();
$sourceConflictRejected = false;
try {
    media_mutation_metadata($pdo, 'update', $row, ['media_extension' => ['content-translation' => $conflict]], ['content_locale' => 'en']);
} catch (RuntimeException $error) {
    $sourceConflictRejected = str_contains($error->getMessage(), 'already has a media translation');
}
$pdo->rollBack();
$check($sourceConflictRejected, 'a locale with an existing translation cannot become the metadata source');

$pdo->exec("UPDATE ct_media_profiles SET metadata_source_locale = 'de'");
$check(media_filter_data($pdo, $row, ['content_locale' => 'de'])['alt'] === 'Alt asli', 'the current metadata source locale never overlays its translation row');
$pdo->exec("UPDATE ct_media_profiles SET metadata_source_locale = 'id'");
$deleteFields = ['translation_locale' => 'de', 'translation_state' => ct_media_translation_state($translation), 'translation_operation' => 'delete'];
$pdo->beginTransaction();
$deleteMetadata = media_mutation_metadata($pdo, 'update', $row, ['media_extension' => ['content-translation' => $deleteFields]], ['content_locale' => 'de']);
do_action('resource_lifecycle_before_commit', [
    'resource' => 'media', 'operation' => 'update', 'actor_id' => 7,
    'metadata' => $deleteMetadata, 'items' => [['after' => $row]],
], new ResourceLifecycleDatabase($pdo));
$deletedInsideTransaction = ct_media_translation($pdo, 1, 'de') === null && media_load_live($pdo, 1) !== null;
$pdo->rollBack();
$check($deletedInsideTransaction, 'translation delete runs in the Core transaction without deleting Core media metadata');
$pdo->exec('UPDATE posts SET is_deleted = 1 WHERE id = 8');
$pdo->prepare("INSERT INTO ct_post_featured_media VALUES (9, 'de', 'featured', 'media', NULL, NULL, NULL, ?, 1, NULL, NULL)")->execute([str_repeat('0', 64)]);
$pdo->beginTransaction();
do_action('resource_lifecycle_before_mutation', ['resource' => 'media', 'operation' => 'purge', 'items' => [['id' => 1]]], new ResourceLifecycleDatabase($pdo));
$nullSelection = $pdo->query("SELECT 1 FROM ct_post_featured_media WHERE mode = 'media' AND media_id IS NULL LIMIT 1")->fetchColumn();
$trashedSelectionCleaned = ct_featured_selection($pdo, 8, 'de') === null && ct_media_profile($pdo, 1) === null && !$nullSelection;
$pdo->rollBack();
$check($trashedSelectionCleaned, 'purge removes a trashed post selection even when its translation remains published');

if ($failures !== []) {
    fwrite(STDERR, implode('; ', $failures) . PHP_EOL);
    exit(1);
}
echo "Localized media runtime passed ({$checks} checks).\n";
