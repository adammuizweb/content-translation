<?php
declare(strict_types=1);

if (!function_exists('h')) {
    function h(string $value): string { return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
}
$pdo = $GLOBALS['pdo'] ?? null;
if (!$pdo instanceof PDO) { echo '<p>' . h(__('Database not available.')) . '</p>'; return; }
if (!ct_user_can_workspace($pdo) || !ct_user_is_site_owner($pdo)
    || !user_can($pdo, ct_current_user_id(), 'core.themes.manage')) { http_response_code(404); return; }
ct_ensure_schema($pdo);
$base = defined('ADMIN_BASE_PATH') ? ADMIN_BASE_PATH : '/adiwira';
$scalar = static fn(mixed $value): string => is_scalar($value) ? trim((string)$value) : '';
$folder = $scalar($_GET['theme_folder'] ?? '');
if ($folder === '' && function_exists('get_active_theme_folder')) $folder = (string)get_active_theme_folder($pdo);
$returnUrl = function_exists('adiwira_safe_return_to')
    ? adiwira_safe_return_to($_GET['return_to'] ?? null, $base . '/?page=admin/tools/content-translation')
    : $base . '/?page=admin/tools/content-translation';
$themes = $pdo->query('SELECT folder_name, name FROM themes ORDER BY is_active DESC, name ASC')->fetchAll(PDO::FETCH_ASSOC);
$error = null;
try { $resources = ct_theme_string_resources($pdo, $folder); }
catch (Throwable $exception) { $resources = []; $error = $exception->getMessage(); }
$statuses = [];
if ($resources !== []) {
    $stmt = $pdo->prepare('SELECT source_hash, locale, status FROM ct_theme_string_translations WHERE theme_folder = ?');
    $stmt->execute([$folder]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) $statuses[(string)$row['source_hash']][(string)$row['locale']] = (string)$row['status'];
}
$locales = ct_enabled_locales($pdo);
$sourceLocale = function_exists('content_default_locale') ? content_default_locale() : 'en';
$editorBase = $base . '/?page=admin/tools/content-translation/theme-string-edit';
$listUrl = $base . '/?' . http_build_query([
    'page' => 'admin/tools/content-translation/theme-strings',
    'theme_folder' => $folder,
    'return_to' => $returnUrl,
], '', '&', PHP_QUERY_RFC3986);
?>
<div class="ct-admin">
  <div class="ct-header"><div><h2><?= __('PHP Interface Strings') ?></h2><p class="muted"><?= __('This screen translates fixed labels and fallback messages written as __() or _e() calls in physical theme PHP. It does not translate text entered in Customize gadgets.') ?></p></div><a class="btn" href="<?= h($returnUrl) ?>"><?= __('Back') ?></a></div>
  <form method="get" class="ct-panel"><input type="hidden" name="page" value="admin/tools/content-translation/theme-strings"><input type="hidden" name="return_to" value="<?= h($returnUrl) ?>"><label><?= __('Theme') ?> <select name="theme_folder" onchange="this.form.submit()"><?php foreach ($themes as $theme): ?><option value="<?= h((string)$theme['folder_name']) ?>" <?= hash_equals($folder, (string)$theme['folder_name']) ? 'selected' : '' ?>><?= h((string)$theme['name']) ?> (<?= h((string)$theme['folder_name']) ?>)</option><?php endforeach; ?></select></label></form>
  <?php if ($error !== null): ?><div class="ct-flash ct-flash-error"><?= h(__($error)) ?></div><?php endif; ?>
  <?php if ($locales === []): ?><div class="ct-flash ct-flash-warning"><?= __('No translation locales enabled.') ?></div><?php endif; ?>
  <div class="ct-workflow-note"><strong><?= __('Looking for footer or header content?') ?></strong><span><?= __('Open Themes → Customize. Text stored in an HTML, title, image, or search gadget is translated from the locale badges on that gadget.') ?></span></div>
  <div class="ct-table-scroll"><table class="ct-table ct-theme-strings-table"><thead><tr><th><?= __('Fixed PHP source text') ?></th><th><?= __('Scope') ?></th><th><?= __('Languages') ?></th></tr></thead><tbody>
    <?php if ($resources === []): ?><tr><td colspan="3" class="muted"><?= __('No literal theme UI strings were discovered.') ?></td></tr><?php endif; ?>
    <?php foreach ($resources as $resource): ?><tr><td><strong><?= h($resource['source']) ?></strong></td><td><code><?= h($resource['scope']) ?></code></td><td><div class="ct-locale-chips"><span class="ct-locale-chip ct-locale-chip--source"><b><?= h(strtoupper($sourceLocale)) ?></b><small><?= __('Source') ?></small></span><?php foreach ($locales as $locale): ?><?php $status = $statuses[$resource['source_hash']][$locale] ?? 'empty'; $url = $editorBase . '&' . http_build_query(['theme_folder' => $folder, 'source_hash' => $resource['source_hash'], 'locale' => $locale, 'return_to' => $listUrl], '', '&', PHP_QUERY_RFC3986); ?><a class="ct-locale-chip ct-locale-chip--<?= h($status) ?>" href="<?= h($url) ?>"><b><?= h(strtoupper($locale)) ?></b><small><?= h(ct_admin_translation_status_label($status)) ?></small></a><?php endforeach; ?></div></td></tr><?php endforeach; ?>
  </tbody></table></div>
</div>
