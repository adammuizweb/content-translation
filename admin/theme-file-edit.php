<?php
declare(strict_types=1);

// Content Translation - file-backed theme translation editor

if (!function_exists('h')) {
    function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
}

$pdo = $GLOBALS['pdo'] ?? null;
if (!$pdo instanceof PDO) { echo '<p>' . __('Database not available.') . '</p>'; return; }
if (!ct_user_can_workspace($pdo) || !user_can($pdo, ct_current_user_id(), 'core.themes.manage')) { http_response_code(404); return; }

ct_ensure_schema($pdo);
$base = defined('ADMIN_BASE_PATH') ? ADMIN_BASE_PATH : '/adiwira';
$listUrl = $base . '/?page=admin/tools/content-translation/theme-files';
$editorBaseUrl = $base . '/?page=admin/tools/content-translation/theme-file-edit';
$themeFolder = trim((string)($_GET['theme_folder'] ?? $_POST['theme_folder'] ?? ''));
$slotKey = trim((string)($_GET['slot_key'] ?? $_POST['slot_key'] ?? ''));
$locale = trim((string)($_GET['locale'] ?? $_POST['locale'] ?? ''));
$resource = ct_theme_file_resource($pdo, $themeFolder, $slotKey);
$editorUrl = $editorBaseUrl
    . '&theme_folder=' . urlencode($themeFolder)
    . '&slot_key=' . urlencode($slotKey)
    . '&locale=' . urlencode($locale);

if (!$resource || !in_array($locale, ct_enabled_locales($pdo), true)) {
    echo '<div class="ct-admin"><div class="ct-flash ct-flash-error">' . h(__('Theme file resource or locale is not available.')) . '</div><a class="btn" href="' . h($listUrl) . '">' . h(__('Back')) . '</a></div>';
    return;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $redirect = static function (string $url): never {
        header('Location: ' . $url, true, 303);
        exit;
    };
    if (!function_exists('csrf_check') || !csrf_check((string)($_POST['csrf_token'] ?? ''))) {
        $redirect($editorUrl . '&error=' . urlencode(__('Invalid CSRF token.')));
    }

    try {
        if (($_POST['intent'] ?? '') === 'delete') {
            if (!ct_delete_theme_file_translation($pdo, $themeFolder, $slotKey, $locale)) {
                throw new RuntimeException(__('Delete failed.'));
            }
            $redirect($listUrl . '&flash=' . urlencode(__('Translation deleted.')));
        }
        if (($_POST['intent'] ?? '') !== 'save') throw new InvalidArgumentException(__('Invalid action.'));
        if (!ct_save_theme_file_translation($pdo, $themeFolder, $slotKey, $locale, [
            'values' => $_POST['values'] ?? [],
            'seo_title' => $_POST['seo_title'] ?? '',
            'meta_description' => $_POST['meta_description'] ?? '',
            'status' => $_POST['status'] ?? '',
        ])) {
            throw new RuntimeException(__('Save failed.'));
        }
        $redirect($editorUrl . '&flash=' . urlencode(__('Translation saved.')));
    } catch (Throwable $e) {
        $redirect($editorUrl . '&error=' . urlencode(__($e->getMessage())));
    }
}

$translation = ct_get_theme_file_translation($pdo, $themeFolder, $slotKey, $locale);
$values = is_array($translation['values'] ?? null) ? $translation['values'] : [];
$sourceValues = ct_theme_file_source_values($pdo, $resource);
$missingSourceFields = array_keys(array_filter(
    $sourceValues,
    static fn(mixed $value): bool => !is_scalar($value) || trim((string)$value) === ''
));
$defaultLocale = function_exists('content_default_locale') ? content_default_locale() : (function_exists('default_locale') ? default_locale() : 'en');
$isRtl = ct_locale_direction($pdo, $locale) === 'rtl';
$formUrl = $editorUrl . '&action=save';
$displaySource = static function (mixed $value): string {
    if (is_bool($value)) return $value ? __('Enabled') : __('Disabled');
    if ($value === null || $value === '') return __('Not set');
    if (is_scalar($value)) return (string)$value;
    $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return is_string($json) ? $json : __('Not set');
};
?>

<div class="ct-admin ct-editor">
  <div class="ct-header">
    <div>
      <h2><?= __('Edit Theme File Translation') ?> - <?= h(strtoupper($locale)) ?></h2>
      <p class="muted"><?= h((string)$resource['label']) ?> <code><?= h($themeFolder . ':' . $slotKey) ?></code></p>
    </div>
    <a class="btn" href="<?= h($listUrl) ?>"><?= __('Back') ?></a>
  </div>

  <?php if (!empty($_GET['flash'])): ?><div class="ct-flash"><?= h((string)$_GET['flash']) ?></div><?php endif; ?>
  <?php if (!empty($_GET['error'])): ?><div class="ct-flash ct-flash-error"><?= h((string)$_GET['error']) ?></div><?php endif; ?>
  <?php if ($translation && empty($translation['values_valid'])): ?>
    <div class="ct-flash ct-flash-error"><?= __('The stored field values contain invalid JSON. Save a valid draft or delete this translation.') ?></div>
  <?php endif; ?>
  <?php if ($missingSourceFields): ?>
    <div class="ct-flash ct-flash-warning"><?= __('Some source values are not saved in Theme Customize and cannot be displayed here. Save the source-language Customizer values before reviewing this translation.') ?></div>
  <?php endif; ?>

  <form method="post" action="<?= h($formUrl) ?>" class="ct-panel ct-translation-panel<?= $isRtl ? ' ct-rtl-editor' : '' ?>" dir="<?= $isRtl ? 'rtl' : 'ltr' ?>">
    <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="theme_folder" value="<?= h($themeFolder) ?>">
    <input type="hidden" name="slot_key" value="<?= h($slotKey) ?>">
    <input type="hidden" name="locale" value="<?= h($locale) ?>">
    <input type="hidden" name="intent" value="save">

    <p class="muted"><?= __('All fields below are required before this resource can be published, preventing source-language text from leaking into a localized page.') ?></p>
    <?php foreach ($resource['fields'] as $fieldKey => $field): ?>
      <?php $controlId = 'ct-theme-field-' . substr(hash('sha256', $fieldKey), 0, 12); ?>
      <div class="ct-field">
        <label for="<?= h($controlId) ?>"><?= h((string)$field['label']) ?></label>
        <?php if (($field['type'] ?? '') === 'textarea'): ?>
          <textarea id="<?= h($controlId) ?>" name="values[<?= h($fieldKey) ?>]" rows="6" dir="<?= $isRtl ? 'rtl' : 'ltr' ?>"><?= h((string)($values[$fieldKey] ?? '')) ?></textarea>
        <?php else: ?>
          <input type="text" id="<?= h($controlId) ?>" name="values[<?= h($fieldKey) ?>]" value="<?= h((string)($values[$fieldKey] ?? '')) ?>" dir="<?= $isRtl ? 'rtl' : 'ltr' ?>">
        <?php endif; ?>
        <div class="ct-source-value"><strong><?= h(strtoupper($defaultLocale)) ?>:</strong> <?= h($displaySource($sourceValues[$fieldKey] ?? '')) ?></div>
      </div>
    <?php endforeach; ?>

    <div class="ct-field">
      <label for="ct-theme-seo-title"><?= __('SEO title') ?></label>
      <input type="text" id="ct-theme-seo-title" name="seo_title" maxlength="255" value="<?= h((string)($translation['seo_title'] ?? '')) ?>" dir="<?= $isRtl ? 'rtl' : 'ltr' ?>">
      <small class="muted"><?= __('Leave empty to use the default homepage document title.') ?></small>
    </div>
    <div class="ct-field">
      <label for="ct-theme-meta-description"><?= __('Meta description') ?></label>
      <textarea id="ct-theme-meta-description" name="meta_description" maxlength="320" rows="3" dir="<?= $isRtl ? 'rtl' : 'ltr' ?>"><?= h((string)($translation['meta_description'] ?? '')) ?></textarea>
      <small class="muted"><?= __('Leave empty to use the localized site description.') ?></small>
    </div>
    <div class="ct-field">
      <label for="ct-theme-status"><?= __('Status') ?></label>
      <select id="ct-theme-status" name="status" class="ct-field-select">
        <option value="draft" <?= ($translation['status'] ?? 'draft') === 'draft' ? 'selected' : '' ?>><?= __('Draft') ?></option>
        <option value="published" <?= ($translation['status'] ?? '') === 'published' ? 'selected' : '' ?>><?= __('Published') ?></option>
      </select>
    </div>
    <div class="ct-actions"><button type="submit" class="btn btn-primary"><?= __('Save Translation') ?></button></div>
  </form>

  <?php if ($translation): ?>
    <form method="post" action="<?= h($formUrl) ?>" class="ct-delete-form" onsubmit="return confirm(<?= h(json_encode(__('Delete this translation? This cannot be undone.'))) ?>)">
      <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="theme_folder" value="<?= h($themeFolder) ?>">
      <input type="hidden" name="slot_key" value="<?= h($slotKey) ?>">
      <input type="hidden" name="locale" value="<?= h($locale) ?>">
      <input type="hidden" name="intent" value="delete">
      <button type="submit" class="btn btn-danger"><?= __('Delete Translation') ?></button>
    </form>
  <?php endif; ?>
</div>
