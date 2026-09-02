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
$overviewUrl = $base . '/?page=admin/themes/customize';
$returnInput = $_POST['return_to'] ?? $_GET['return_to'] ?? null;
$returnUrl = function_exists('adiwira_safe_return_to') ? adiwira_safe_return_to($returnInput, $overviewUrl) : $overviewUrl;
$scalar = static fn(mixed $value): string => is_scalar($value) ? trim((string)$value) : '';
$itemId = (int)$scalar($_POST['item_id'] ?? $_GET['item_id'] ?? '0');
$locale = $scalar($_POST['locale'] ?? $_GET['locale'] ?? '');
$resource = ct_theme_zone_resource($pdo, $itemId);
$editorUrl = $base . '/?' . http_build_query([
    'page' => 'admin/tools/content-translation/theme-zone-edit',
    'item_id' => $itemId,
    'locale' => $locale,
    'return_to' => $returnUrl,
]);
$redirect = static function (string $url): never { header('Location: ' . $url, true, 303); exit; };

if (!$resource || !in_array($locale, ct_enabled_locales($pdo), true)) {
    echo '<div class="ct-admin"><div class="ct-flash ct-flash-error">' . h(__('Theme Zone resource or locale is not available.')) . '</div><a class="btn" href="' . h($returnUrl) . '">' . h(__('Back')) . '</a></div>';
    return;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!function_exists('csrf_check') || !csrf_check($scalar($_POST['csrf_token'] ?? ''))) {
        $redirect($editorUrl . '&error=' . rawurlencode(__('Invalid CSRF token.')));
    }
    try {
        $intent = $scalar($_POST['intent'] ?? '');
        if ($intent === 'delete') {
            ct_delete_theme_zone_translation($pdo, $itemId, $locale, $scalar($_POST['translation_state'] ?? ''));
            $redirect($returnUrl);
        }
        if ($intent !== 'save') throw new InvalidArgumentException(__('Invalid action.'));
        ct_save_theme_zone_translation($pdo, $itemId, $locale, [
            'values' => is_array($_POST['values'] ?? null) ? $_POST['values'] : [],
            'status' => $scalar($_POST['status'] ?? ''),
            'source_fingerprint' => $scalar($_POST['source_fingerprint'] ?? ''),
            'translation_state' => $scalar($_POST['translation_state'] ?? ''),
        ]);
        $redirect($editorUrl . '&flash=' . rawurlencode(__('Translation saved.')));
    } catch (Throwable $error) {
        $redirect($editorUrl . '&error=' . rawurlencode(__($error->getMessage())));
    }
}

$translation = ct_get_theme_zone_translation($pdo, $itemId, $locale);
$values = is_array($translation['values'] ?? null) ? $translation['values'] : [];
$translationState = ct_theme_zone_translation_state($translation);
$isRtl = ct_locale_direction($pdo, $locale) === 'rtl';
?>
<div class="ct-admin ct-editor<?= $isRtl ? ' ct-rtl-editor' : '' ?>" dir="<?= $isRtl ? 'rtl' : 'ltr' ?>">
  <div class="ct-header">
    <div><h2><?= __('Theme Zone Translation') ?> - <?= h(strtoupper($locale)) ?></h2><p class="muted"><code>#<?= $itemId ?></code> <?= h($resource['theme_folder'] . ' / ' . $resource['zone_slug'] . ' / ' . $resource['position']) ?></p></div>
    <a class="btn" href="<?= h($returnUrl) ?>"><?= __('Back') ?></a>
  </div>
  <?php if (!empty($_GET['flash'])): ?><div class="ct-flash"><?= h((string)$_GET['flash']) ?></div><?php endif; ?>
  <?php if (!empty($_GET['error'])): ?><div class="ct-flash ct-flash-error"><?= h((string)$_GET['error']) ?></div><?php endif; ?>
  <?php if ($translation && !hash_equals((string)$resource['source_fingerprint'], (string)($translation['source_fingerprint'] ?? ''))): ?><div class="ct-flash ct-flash-warning"><?= __('The source text changed. Review every field before republishing.') ?></div><?php endif; ?>
  <form method="post" action="<?= h($editorUrl) ?>" class="ct-panel ct-translation-panel">
    <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="item_id" value="<?= $itemId ?>">
    <input type="hidden" name="locale" value="<?= h($locale) ?>">
    <input type="hidden" name="return_to" value="<?= h($returnUrl) ?>">
    <input type="hidden" name="source_fingerprint" value="<?= h($resource['source_fingerprint']) ?>">
    <input type="hidden" name="translation_state" value="<?= h($translationState) ?>">
    <input type="hidden" name="intent" value="save">
    <?php foreach ($resource['schema'] as $key => $field): ?>
      <div class="ct-field"><label for="ct-zone-<?= h($key) ?>"><?= h($field['label']) ?></label>
        <?php if ($field['control'] === 'html' || $field['control'] === 'textarea'): ?><textarea id="ct-zone-<?= h($key) ?>" name="values[<?= h($key) ?>]" rows="7"><?= h((string)($values[$key] ?? '')) ?></textarea><?php else: ?><input id="ct-zone-<?= h($key) ?>" type="text" name="values[<?= h($key) ?>]" value="<?= h((string)($values[$key] ?? '')) ?>"><?php endif; ?>
        <div class="ct-source-value"><strong><?= __('Source') ?>:</strong> <?= h((string)$resource['source_values'][$key]) ?></div>
      </div>
    <?php endforeach; ?>
    <div class="ct-field"><label for="ct-zone-status"><?= __('Status') ?></label><select id="ct-zone-status" name="status"><option value="draft" <?= ($translation['status'] ?? 'draft') === 'draft' ? 'selected' : '' ?>><?= __('Draft') ?></option><option value="published" <?= ($translation['status'] ?? '') === 'published' ? 'selected' : '' ?>><?= __('Published') ?></option></select></div>
    <div class="ct-actions"><button class="btn btn-primary" type="submit"><?= __('Save Translation') ?></button></div>
  </form>
  <?php if ($translation): ?><form method="post" action="<?= h($editorUrl) ?>" class="ct-delete-form"><input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>"><input type="hidden" name="item_id" value="<?= $itemId ?>"><input type="hidden" name="locale" value="<?= h($locale) ?>"><input type="hidden" name="return_to" value="<?= h($returnUrl) ?>"><input type="hidden" name="translation_state" value="<?= h($translationState) ?>"><input type="hidden" name="intent" value="delete"><button class="btn btn-danger" type="submit"><?= __('Delete Translation') ?></button></form><?php endif; ?>
</div>
