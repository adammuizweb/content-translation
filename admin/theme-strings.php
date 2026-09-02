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
$editorBase = $base . '/?page=admin/tools/content-translation/theme-string-edit';
?>
<div class="ct-admin">
  <div class="ct-header"><div><h2><?= __('Theme UI Strings') ?></h2><p class="muted"><?= __('Literal theme strings are discovered from physical PHP source. URLs and layout configuration remain shared.') ?></p></div><a class="btn" href="<?= h($base . '/?page=admin/tools/content-translation') ?>"><?= __('Back') ?></a></div>
  <form method="get" class="ct-panel"><input type="hidden" name="page" value="admin/tools/content-translation/theme-strings"><label><?= __('Theme') ?> <select name="theme_folder" onchange="this.form.submit()"><?php foreach ($themes as $theme): ?><option value="<?= h((string)$theme['folder_name']) ?>" <?= hash_equals($folder, (string)$theme['folder_name']) ? 'selected' : '' ?>><?= h((string)$theme['name']) ?> (<?= h((string)$theme['folder_name']) ?>)</option><?php endforeach; ?></select></label></form>
  <?php if ($error !== null): ?><div class="ct-flash ct-flash-error"><?= h(__($error)) ?></div><?php endif; ?>
  <table class="ct-table"><thead><tr><th><?= __('Source') ?></th><th><?= __('Scope') ?></th><th><?= __('Translations') ?></th></tr></thead><tbody>
    <?php if ($resources === []): ?><tr><td colspan="3" class="muted"><?= __('No literal theme UI strings were discovered.') ?></td></tr><?php endif; ?>
    <?php foreach ($resources as $resource): ?><tr><td><strong><?= h($resource['source']) ?></strong></td><td><code><?= h($resource['scope']) ?></code></td><td><select class="ct-translation-select" onchange="if(this.value)window.location.href=this.value"><option value=""><?= __('Choose language...') ?></option><?php foreach ($locales as $locale): ?><?php $status = $statuses[$resource['source_hash']][$locale] ?? 'Add'; $url = $editorBase . '&theme_folder=' . rawurlencode($folder) . '&source_hash=' . rawurlencode($resource['source_hash']) . '&locale=' . rawurlencode($locale); ?><option value="<?= h($url) ?>"><?= h(strtoupper($locale) . ' - ' . __(ucfirst($status))) ?></option><?php endforeach; ?></select></td></tr><?php endforeach; ?>
  </tbody></table>
</div>
