<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$files = [
    'plugin' => (string)file_get_contents($root . '/plugin.php'),
    'helpers' => (string)file_get_contents($root . '/includes/helpers.php'),
    'media' => (string)file_get_contents($root . '/includes/media.php'),
    'admin' => (string)file_get_contents($root . '/includes/admin.php'),
    'editor' => (string)file_get_contents($root . '/admin/edit.php'),
    'theme_editor' => (string)file_get_contents($root . '/admin/theme-section-edit.php'),
    'save' => (string)file_get_contents($root . '/admin/api/save.php'),
    'delete' => (string)file_get_contents($root . '/admin/api/delete.php'),
    'migration' => (string)file_get_contents($root . '/migrations/0004-localized-media.php'),
    'docs' => (string)file_get_contents($root . '/docs/localized-media.md'),
];
$core = getenv('CORE_ROOT') ?: (getenv('JY_ROOT') ?: dirname(__DIR__, 3) . '/jyavani.lan');
$failures = [];
$checks = 0;
$check = static function (bool $condition, string $message) use (&$failures, &$checks): void {
    $checks++;
    echo ($condition ? 'PASS' : 'FAIL') . ' ' . $message . PHP_EOL;
    if (!$condition) $failures[] = $message;
};

foreach (['ct_media_profiles', 'ct_media_available_locales', 'ct_media_translations', 'ct_post_featured_media'] as $table) {
    $check(str_contains($files['migration'], 'CREATE TABLE IF NOT EXISTS ' . $table)
        && str_contains($files['helpers'], 'ct_ensure_media_schema'), $table . ' has append-only migration and runtime schema coverage');
}
$check(str_contains($files['migration'], "ENUM('inherit','text','decorative')")
    && str_contains($files['migration'], "ENUM('all','selected')")
    && str_contains($files['media'], "Selected availability requires at least one locale"),
    'schema and validation preserve explicit alt and availability states');
$migrationNames = array_map('basename', glob($root . '/migrations/*') ?: []);
sort($migrationNames);
$check($migrationNames === ['0001-post-workflows.sql', '0002-migrate-authored-posts.php', '0003-locale-edit-grants.php', '0004-localized-media.php']
    && str_contains($files['migration'], 'return static function (PDO $pdo): void'),
    'localized media is appended as canonical migration 0004 without replacing migration history');
$check(str_contains($files['media'], "add_action('media_admin_upload_fields'")
    && str_contains($files['media'], "add_action('media_admin_detail_before_fields'")
    && str_contains($files['media'], "add_action('media_admin_detail_after_fields'"),
    'one generic upload hook and both detail hooks cover full and modal Core surfaces');
$check(str_contains($files['media'], "add_filter('media_mutation_metadata'")
    && str_contains($files['media'], "add_action('resource_lifecycle_before_commit'")
    && !str_contains($files['media'], "add_action('resource_lifecycle_committed'"),
    'media writes validate and persist before commit rather than from observers');
$check(str_contains($files['media'], "user_can(\$pdo, \$actorId, \$permission")
    && str_contains($files['media'], "\$actorId !== (int)(\$payload['actor_id']")
    && str_contains($files['helpers'], 'authorization_lock_actor_permissions'),
    'media and translation mutations reauthorize and bind attribution while Core locks are held');
$check(str_contains($files['media'], 'ct_lock_media_extension_state')
    && str_contains($files['media'], 'ct_media_profile_state')
    && str_contains($files['media'], 'ct_media_translation_state')
    && substr_count($files['media'], 'FOR UPDATE') >= 2,
    'profile, availability, and target translations use locked optimistic state');
$check(str_contains($files['media'], 'old_source_locale')
    && str_contains($files['media'], 'already has a media translation')
    && str_contains($files['media'], "metadata_source_locale'], \$locale"),
    'source locale reclassification requires old/new state and source-locale overlays are excluded');
$check(str_contains($files['media'], "add_filter('media_data'")
    && str_contains($files['media'], "status'] !== 'published'")
    && str_contains($files['media'], 'source_fingerprint')
    && str_contains($files['media'], "'decorative' => ''"),
    'runtime overlays only current published metadata and preserves decorative empty alt');
$check(str_contains($files['media'], "add_filter('featured_media'")
    && str_contains($files['media'], 'media_load_live') && str_contains($files['media'], 'media_client_url')
    && str_contains($files['media'], "mode'] === 'none'"),
    'localized featured inherit/media/none resolution uses Core live and public helpers');
$check(str_contains($files['editor'], 'media_picker_query')
    && str_contains($files['editor'], "'content_locale' => \$locale")
    && str_contains($files['editor'], 'featured_alt_override_enabled')
    && str_contains($files['editor'], 'YouTube remains the first display-image source'),
    'post/page editor sends explicit picker context, supports nullable overrides, and states YouTube precedence');
$check(str_contains($files['editor'], "\$post['type'] === 'page' ? 'page' : 'post'")
    && str_contains($files['editor'], 'detail.extensions?.content_translation?.available'),
    'picker validates page/post consumers and immediately consumes locale availability diagnostics');
$check(str_contains($files['editor'], 'media_resolve_featured')
    && str_contains($files['editor'], 'media_post_display_url')
    && str_contains($files['editor'], '<?php if ($featuredUrl): ?>')
    && str_contains($files['editor'], 'renderFeaturedPreview')
    && str_contains($files['editor'], "image.removeAttribute('src')"),
    'translation editor previews inherited source media without emitting an empty image URL');
$check(str_contains($files['editor'], 'pattern="[a-zA-Z0-9_\\/\\-]*"')
    && str_contains($files['theme_editor'], 'pattern="[a-zA-Z0-9_\\/\\-]*"')
    && !str_contains($files['editor'] . $files['theme_editor'], 'pattern="[a-zA-Z0-9_\\-/]*"'),
    'translation slug patterns remain valid under browser RegExp v semantics');
$check(str_contains($files['editor'], '/admin/modal_img/index.php?embedded=1')
    && str_contains((string)file_get_contents($root . '/plugin.json'), '"media-selector"')
    && !str_contains($files['editor'], '/static/js/add/media-selector.js')
    && str_contains($files['editor'], "toolbar.addHandler('image'")
    && str_contains($files['editor'], "[{ color: [] }, { background: [] }]")
    && str_contains($files['editor'], "['link', 'image', 'video']"),
    'translation editor uses the canonical modal route and a full Quill toolbar with media selection');
$check(str_contains($files['media'], 'ct-media-metadata-slot')
    && str_contains($files['media'], 'form.addEventListener("formdata"')
    && str_contains($files['media'], 'slot.appendChild(wrap)')
    && str_contains($files['media'], "\$policy === 'all' ? ' disabled' : ''")
    && str_contains($files['media'], 'input.disabled=all')
    && str_contains($files['media'], 'input.checked=all||selected.has(input.value)'),
    'media details share one locale-switched metadata form and represent all-locale availability as checked disabled controls');
$check(str_contains($files['helpers'], 'ct_translation_editor_state($current, $currentFeatured)')
    && str_contains($files['helpers'], 'ct_save_featured_selection')
    && strpos($files['helpers'], 'ct_save_featured_selection') < strpos($files['helpers'], 'if ($ownsTransaction) $pdo->commit()', strpos($files['helpers'], 'function ct_save_translation_locked')),
    'translation text and featured selection use combined optimistic state in one transaction');
$check(str_contains($files['helpers'], 'DELETE FROM ct_post_featured_media WHERE post_id = ? AND locale = ?')
    && str_contains($files['media'], "operation'] ?? '') !== 'purge'")
    && str_contains($files['media'], "pt.status = 'published'"),
    'translation deletion and media purge cleanup are transactional and published use blocks purge');
$check(str_contains($files['media'], 'p.is_deleted = 0')
    && str_contains($files['media'], "mode = 'media' AND media_id IS NULL")
    && str_contains($files['media'], "translation']['operation'] ?? 'save') === 'delete'"),
    'purge removes deleted-post and null media selections and detail saves support translation deletion');
$check(str_contains($files['admin'], 'localized media state could not be verified')
    && str_contains($files['helpers'], "'version' => 7")
    && str_contains($files['helpers'], "'media_profiles'")
    && str_contains($files['plugin'], "'ct_media_profiles'"),
    'default-locale preflight fails closed and export v7/uninstall cover all media state');
$check(str_contains($files['plugin'], 'ct_localized_media_state_exists')
    && str_contains($files['plugin'], 'Content Translation state could not be verified.')
    && !str_contains($files['plugin'], 'ct_ensure_media_schema($pdo)'),
    'plugin disable/delete preflight blocks media state, fails closed, and performs no DDL');
$check(str_contains($files['docs'], 'independent of the site default language')
    && str_contains($files['docs'], 'No importer is provided')
    && str_contains($files['docs'], 'generic Core media extension contract'),
    'documentation records source-language independence, export scope, and released Core requirement');
$check(str_contains($files['media'], 'function ct_localized_media_supported')
    && str_contains($files['media'], 'if (ct_localized_media_supported())')
    && str_contains($files['editor'], '$localizedMediaSupported'),
    'localized media integration gates hook registration and editor columns behind the Core contract');
$defaultTemplates = '';
foreach (glob($core . '/public/views/themes/default/main/**/*.php') ?: [] as $template) $defaultTemplates .= (string)file_get_contents($template);
$defaultTemplates .= (string)file_get_contents($core . '/public/views/themes/default/main/homepage.php');
$coreMedia = (string)file_get_contents($core . '/cfg/helpers/media_helpers.php');
$check(str_contains($coreMedia, 'media_post_image_alt') && str_contains($coreMedia, 'media_post_image_caption')
    && str_contains($defaultTemplates, 'media_post_image_alt') && str_contains($defaultTemplates, 'media_post_image_caption'),
    'hardened Core default surfaces consume localized featured alt and caption helpers');

if ($failures !== []) {
    fwrite(STDERR, implode('; ', $failures) . PHP_EOL);
    exit(1);
}
echo "Localized media contract passed ({$checks} checks).\n";
