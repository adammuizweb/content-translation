<?php
declare(strict_types=1);

if (!function_exists('h')) {
    function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
}

$pdo = $GLOBALS['pdo'] ?? null;
if (!$pdo instanceof PDO) { echo '<p>' . h(__('Database not available.')) . '</p>'; return; }
if (!function_exists('current_user_role') || current_user_role($pdo) !== 'admin') {
    http_response_code(403);
    echo '<p>' . h(__('Admin role required.')) . '</p>';
    return;
}
ct_ensure_schema($pdo);

$base = defined('ADMIN_BASE_PATH') ? ADMIN_BASE_PATH : '/adiwira';
$listUrl = $base . '/?page=admin/tools/content-translation/shortcodes';
$saveUrl = $base . '/?page=admin/tools/content-translation/api/shortcode-save&action=api';
$presetInput = $_GET['preset_id'] ?? '0';
$presetId = is_scalar($presetInput) ? (int)$presetInput : 0;
$localeInput = $_GET['locale'] ?? '';
$locale = is_scalar($localeInput) ? trim((string)$localeInput) : '';
if ($presetId <= 0 || !in_array($locale, ct_enabled_locales($pdo), true)) {
    echo '<p>' . h(__('Preset or locale is not available.')) . ' <a href="' . h($listUrl) . '">' . h(__('Back')) . '</a></p>';
    return;
}
$stmt = $pdo->prepare("SELECT id, title, slug, status, meta FROM posts WHERE id = ? AND type = 'sc_preset' AND is_deleted = 0 LIMIT 1");
$stmt->execute([$presetId]);
$preset = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$preset) {
    echo '<p>' . h(__('Shortcode Preset not found.')) . ' <a href="' . h($listUrl) . '">' . h(__('Back')) . '</a></p>';
    return;
}
$decodedConfig = json_decode((string)($preset['meta'] ?? ''), true);
$config = function_exists('shortcode_preset_config_loaded')
    ? shortcode_preset_config_loaded((string)($preset['meta'] ?? ''), $preset, $pdo)
    : (is_array($decodedConfig) ? $decodedConfig : []);
$sourceKickerMode = ct_shortcode_preset_kicker_mode($config);
$sourceKicker = $sourceKickerMode === 'custom' && is_scalar($config['kicker'] ?? null) ? trim((string)$config['kicker']) : '';
$translation = ct_get_shortcode_preset_translation($pdo, $presetId, $locale);
$overrides = is_array($translation['overrides'] ?? null) ? $translation['overrides'] : [];
$sourceState = ct_shortcode_preset_source_state_token($preset);
$translationState = ct_shortcode_preset_translation_state_token($translation);
$storageError = ct_schema_error($pdo);
$isRtl = ct_locale_direction($pdo, $locale) === 'rtl';
?>
<div class="ct-admin ct-editor">
  <div class="ct-header">
    <div>
      <h2><?= __('Shortcode Preset Translation') ?> - <?= h(strtoupper($locale)) ?></h2>
      <p class="muted"><?= h((string)$preset['title']) ?> <code>[[widget:<?= h((string)$preset['slug']) ?>]]</code></p>
    </div>
    <a class="btn" href="<?= h($listUrl) ?>"><?= __('Back') ?></a>
  </div>

  <?php if ($translation && empty($translation['overrides_valid'])): ?>
    <div class="ct-flash ct-flash-error"><?= __('The stored preset text override is invalid. Save a valid draft or delete this translation; invalid data is never applied at runtime.') ?></div>
  <?php endif; ?>
  <?php if ($storageError !== null): ?>
    <div class="ct-flash ct-flash-error"><?= __('Preset translation storage is unavailable. Source preset behavior is unchanged; check the server error log before retrying.') ?></div>
  <?php endif; ?>
  <?php if ($sourceKickerMode === 'automatic'): ?>
    <div class="ct-flash ct-flash-warning"><?= __('This preset has no explicitly configured source kicker. Fetched Posts or Pages are translated through their existing Content Translation resources. You may still add a localized kicker when the localized presentation needs one.') ?></div>
  <?php endif; ?>

  <div id="ct-shortcode-notification" class="ct-flash" role="status" aria-live="polite" hidden></div>

  <div class="ct-editor-stack">
    <form id="ct-shortcode-form" class="ct-panel ct-translation-panel<?= $isRtl ? ' ct-rtl-editor' : '' ?>" dir="<?= $isRtl ? 'rtl' : 'ltr' ?>">
      <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="preset_id" value="<?= $presetId ?>">
      <input type="hidden" name="locale" value="<?= h($locale) ?>">
      <input type="hidden" name="source_state" value="<?= h($sourceState) ?>">
      <input type="hidden" name="translation_state" value="<?= h($translationState) ?>">
      <input type="hidden" name="intent" value="save">
      <h3><?= __('Translation') ?> (<?= h(strtoupper($locale)) ?>)</h3>
      <div class="ct-field">
        <label for="ct-preset-title"><?= __('Translated management title') ?></label>
        <input id="ct-preset-title" name="title" maxlength="191" value="<?= h((string)($translation['title'] ?? '')) ?>">
        <small class="muted"><?= __('Used to identify this preset translation in administration; it does not replace fetched post or page titles.') ?></small>
      </div>
      <div class="ct-field">
        <label for="ct-preset-kicker"><?= __('Localized kicker / heading') ?></label>
        <input id="ct-preset-kicker" name="kicker" maxlength="255" value="<?= h((string)($overrides['kicker'] ?? '')) ?>">
        <small class="muted"><?= __('Only this text key can be overlaid. Leaving it empty keeps the source preset heading behavior.') ?></small>
      </div>
      <div class="ct-field">
        <label for="ct-preset-status"><?= __('Status') ?></label>
        <select id="ct-preset-status" name="status" class="ct-field-select">
          <option value="draft"<?= ($translation['status'] ?? 'draft') === 'draft' ? ' selected' : '' ?>><?= __('Draft') ?></option>
          <option value="published"<?= ($translation['status'] ?? '') === 'published' ? ' selected' : '' ?>><?= __('Published') ?></option>
        </select>
      </div>
      <div class="ct-actions">
        <button type="submit" class="btn btn-primary"><?= __('Save Translation') ?></button>
        <button type="button" id="ct-shortcode-delete" class="btn btn-danger"<?= $translation ? '' : ' hidden' ?>><?= __('Delete Translation') ?></button>
      </div>
    </form>

    <section class="ct-panel ct-source-panel">
      <h3><?= __('Source preset') ?></h3>
      <div class="ct-field"><label><?= __('Management title') ?></label><div class="ct-readonly"><?= h((string)$preset['title']) ?></div></div>
      <div class="ct-field"><label><?= __('Source heading') ?></label><div class="ct-readonly"><?php if ($sourceKickerMode === 'custom'): ?><?= h($sourceKicker) ?><?php elseif ($sourceKickerMode === 'hidden'): ?><span class="muted"><?= __('Hidden') ?></span><?php else: ?><span class="muted"><?= __('Automatic category heading') ?></span><?php endif; ?></div></div>
      <p class="muted"><?= __('Category, post type, author, limits, ordering, layout, wrapper, and all unknown extension configuration remain controlled by the source preset and cannot be translated here.') ?></p>
    </section>
  </div>
</div>
<script>
(function(){
  const form = document.getElementById('ct-shortcode-form');
  const notification = document.getElementById('ct-shortcode-notification');
  function notify(type, message) {
    if (notification) {
      notification.className = 'ct-flash ct-flash-' + (type === 'error' ? 'error' : 'success');
      notification.textContent = message;
      notification.hidden = false;
    }
    if (window.NewNotifToast && typeof window.NewNotifToast.show === 'function') return window.NewNotifToast.show({type:type,message:message});
    const toast = window.safeToast || window.showToast;
    if (typeof toast === 'function') toast(message, type);
  }
  function confirmDelete() {
    if (window.NewNotifConfirm && typeof window.NewNotifConfirm.danger === 'function') {
      return window.NewNotifConfirm.danger({
        title: <?= json_encode(__('Delete translation')) ?>,
        message: <?= json_encode(__('Delete this translation? This cannot be undone.')) ?>,
        confirmText: <?= json_encode(__('Delete')) ?>,
        cancelText: <?= json_encode(__('Cancel')) ?>,
        focus: 'cancel'
      });
    }
    notify('error', <?= json_encode(__('Confirmation dialog is unavailable. Translation was not deleted.')) ?>);
    return Promise.resolve(false);
  }
  async function submit(body) {
    const response = await fetch(<?= json_encode($saveUrl) ?>, {method:'POST',body:body,credentials:'same-origin'});
    return response.json();
  }
  form.addEventListener('submit', async function(event){
    event.preventDefault();
    try {
      const data = await submit(new FormData(form));
      if (!data.success) return notify('error', data.error || <?= json_encode(__('Save failed.')) ?>);
      form.elements.translation_state.value = data.translation_state;
      document.getElementById('ct-shortcode-delete').hidden = false;
      notify('success', data.message || <?= json_encode(__('Translation saved.')) ?>);
    } catch (error) { notify('error', <?= json_encode(__('Network error.')) ?>); }
  });
  const deleteButton = document.getElementById('ct-shortcode-delete');
  if (deleteButton) deleteButton.addEventListener('click', async function(){
    try {
      if (!await confirmDelete()) return;
    } catch (error) {
      notify('error', <?= json_encode(__('Confirmation dialog is unavailable. Translation was not deleted.')) ?>);
      return;
    }
    const body = new FormData();
    body.set('csrf_token', form.elements.csrf_token.value);
    body.set('preset_id', form.elements.preset_id.value);
    body.set('locale', form.elements.locale.value);
    body.set('source_state', form.elements.source_state.value);
    body.set('translation_state', form.elements.translation_state.value);
    body.set('intent', 'delete');
    try {
      const data = await submit(body);
      if (!data.success) return notify('error', data.error || <?= json_encode(__('Delete failed.')) ?>);
      window.location.href = <?= json_encode($listUrl . '&notice=translation_deleted') ?>;
    } catch (error) { notify('error', <?= json_encode(__('Network error.')) ?>); }
  });
})();
</script>
