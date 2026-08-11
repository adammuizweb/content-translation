<?php
declare(strict_types=1);

if (!function_exists('h')) {
    function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
}

$pdo = $GLOBALS['pdo'] ?? null;
if (!$pdo instanceof PDO) { echo '<p>' . h(__('Database not available.')) . '</p>'; return; }
ct_ensure_schema($pdo);

$base = defined('ADMIN_BASE_PATH') ? ADMIN_BASE_PATH : '/adiwira';
$overviewUrl = $base . '/?page=admin/tools/content-translation/theme-sections';
$listUrl = function_exists('adiwira_safe_return_to')
    ? adiwira_safe_return_to($_GET['return_to'] ?? null, $overviewUrl)
    : $overviewUrl;
$saveUrl = $base . '/?page=admin/tools/content-translation/api/theme-section-save&action=api';
$deleteUrl = $base . '/?page=admin/tools/content-translation/api/delete&action=api';
$postId = (int)($_GET['post_id'] ?? 0);
$locale = trim((string)($_GET['locale'] ?? ''));
if ($postId <= 0 || !in_array($locale, ct_enabled_locales($pdo), true)) {
    echo '<p>' . h(__('Invalid Theme Template or locale.')) . ' <a href="' . h($listUrl) . '">' . h(__('Back')) . '</a></p>';
    return;
}

$stmt = $pdo->prepare("SELECT id, type, title, slug, content, status FROM posts WHERE id = ? AND type = 'theme' AND is_deleted = 0 LIMIT 1");
$stmt->execute([$postId]);
$post = $stmt->fetch(PDO::FETCH_ASSOC);
$source = $post ? ct_theme_section_source_resource($pdo, $post) : null;
if (!$post || $source === null) {
    echo '<p>' . h(__('Theme Section composition is unavailable or contains an unregistered section.')) . ' <a href="' . h($listUrl) . '">' . h(__('Back')) . '</a></p>';
    return;
}

$translation = ct_get_translation($pdo, $postId, $locale);
$hasTranslation = $translation !== null;
$package = $translation ? ct_decode_theme_section_package((string)$translation['content']) : null;
$packageNames = $package ? array_keys((array)$package['sections']) : [];
$sourceNames = array_map(static fn(array $section): string => (string)$section['name'], (array)$source['sections']);
$focusInput = $_GET['section'] ?? '';
$focusSection = is_scalar($focusInput) ? trim((string)$focusInput) : '';
if (!in_array($focusSection, $sourceNames, true)) $focusSection = '';
$identityMatches = $package === null || ($packageNames === $sourceNames && (string)$package['theme_folder'] === (string)$source['theme_folder']);
$savedFingerprint = ct_theme_section_saved_source_fingerprint($pdo, $postId, $locale);
$sourceState = ct_theme_section_source_state($savedFingerprint, (string)$source['source_fingerprint']);
$stateLabel = $sourceState === 'current' ? __('Current') : ($sourceState === 'stale' ? __('Stale source') : __('Unverified source'));
$isHomepage = ct_is_homepage_post($pdo, $postId);
$isRtl = ct_locale_direction($pdo, $locale) === 'rtl';
$translation ??= ['title' => '', 'slug' => '', 'meta_description' => '', 'status' => 'draft'];
$translationState = ct_translation_row_state_token(ct_get_translation($pdo, $postId, $locale));
$previewShell = function_exists('theme_section_preview_document_shell')
    ? theme_section_preview_document_shell($pdo, ['locale' => $locale, 'post_id' => $postId, 'theme_folder' => (string)$source['theme_folder']])
    : ['before' => '', 'after' => ''];
?>
<div class="ct-admin ct-package-editor<?= $isRtl ? ' ct-rtl-editor' : '' ?>" dir="<?= $isRtl ? 'rtl' : 'ltr' ?>">
  <div class="ct-header">
    <div>
      <h2><?= __('Theme Section Translation') ?> — <?= h(strtoupper($locale)) ?></h2>
      <p class="muted"><?= h((string)$post['title']) ?> · <?= h((string)$source['theme_folder']) ?></p>
    </div>
    <div class="ct-header-actions">
      <span id="ct-source-state" class="ct-source-state ct-source-state--<?= h($sourceState) ?>"><?= h($stateLabel) ?></span>
      <a class="btn" href="<?= h($listUrl) ?>"><?= __('Back') ?></a>
    </div>
  </div>

  <?php if (!$identityMatches): ?>
    <div class="ct-flash ct-flash-error"><?= __('The existing v1 package has a different theme owner, section identity, or order. It remains untouched; restore the source composition or replace the translation deliberately outside this editor.') ?></div>
  <?php elseif ($package === null && trim((string)($translation['content'] ?? '')) !== ''): ?>
    <div class="ct-flash ct-flash-warning"><?= __('The current translation is plain content, not a valid v1 package. Saving here will replace it with a server-built package.') ?></div>
  <?php endif; ?>
  <?php if ($focusSection !== ''): ?>
    <div class="ct-focus-notice"><?= __('Opened from the source renderer. The matching section is highlighted below:') ?> <code><?= h($focusSection) ?></code></div>
  <?php endif; ?>

  <form id="ct-theme-section-form">
    <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="post_id" value="<?= $postId ?>">
    <input type="hidden" name="locale" value="<?= h($locale) ?>">
    <input type="hidden" name="source_fingerprint" value="<?= h((string)$source['source_fingerprint']) ?>">
    <input type="hidden" name="translation_state" value="<?= h($translationState) ?>">

    <section class="ct-panel ct-package-identity">
      <h3><?= __('Page metadata') ?></h3>
      <div class="ct-package-fields">
        <div class="ct-field"><label for="ct-title"><?= __('Translated title') ?></label><input id="ct-title" name="title" maxlength="255" value="<?= h((string)$translation['title']) ?>"></div>
        <div class="ct-field"><label for="ct-slug"><?= __('Translated slug') ?><?php if ($isHomepage): ?> <small class="muted"><?= __('Homepage may remain empty') ?></small><?php endif; ?></label><input id="ct-slug" name="slug" maxlength="255" pattern="[a-zA-Z0-9_\-/]*" value="<?= h((string)$translation['slug']) ?>"></div>
        <div class="ct-field ct-package-field-wide"><label for="ct-meta"><?= __('Meta description') ?></label><textarea id="ct-meta" name="meta_description" rows="3" maxlength="320"><?= h((string)$translation['meta_description']) ?></textarea></div>
        <div class="ct-field"><label for="ct-status"><?= __('Status') ?></label><select id="ct-status" name="status"><option value="draft"<?= ($translation['status'] ?? '') === 'draft' ? ' selected' : '' ?>><?= __('Draft') ?></option><option value="published"<?= ($translation['status'] ?? '') === 'published' ? ' selected' : '' ?>><?= __('Published') ?></option></select></div>
      </div>
    </section>

    <div class="ct-package-sections">
      <?php foreach ((array)$source['sections'] as $index => $section): ?>
        <?php
          $name = (string)$section['name'];
          $translatedSection = $package['sections'][$name] ?? null;
          $sourceFallback = (array)$section['fallback'];
          $fallback = is_array($translatedSection) ? (array)$translatedSection['fallback'] : ['title' => '', 'summary' => '', 'url' => '', 'link_label' => ''];
          $translatedHtml = is_array($translatedSection) ? (string)$translatedSection['html'] : (string)$section['source_html'];
          $sanitizePreview = static fn(string $html): string => function_exists('theme_section_preview_sanitize_html')
              ? theme_section_preview_sanitize_html($html)
              : (preg_replace(['~<script\b[^>]*>.*?</script\s*>~is', '~</?script\b[^>]*>~is'], '', $html) ?? '');
          $sourcePreview = $previewShell['before'] . $sanitizePreview((string)$section['source_html']) . $previewShell['after'];
          $translatedPreview = $previewShell['before'] . $sanitizePreview($translatedHtml) . $previewShell['after'];
        ?>
        <article id="ct-package-section-<?= $index ?>" class="ct-package-section<?= $name === $focusSection ? ' ct-package-section--focused' : '' ?>" data-section-index="<?= $index ?>" data-section-name="<?= h($name) ?>">
          <header><span><?= sprintf(__('Section %d'), $index + 1) ?></span><strong><?= h($name) ?></strong><code><?= h(substr((string)$section['source_fingerprint'], 0, 12)) ?></code></header>
          <input type="hidden" name="sections[<?= $index ?>][name]" value="<?= h($name) ?>">
          <div class="ct-package-columns">
            <section class="ct-package-side ct-package-source">
              <h4><?= __('Source') ?></h4>
              <?php foreach (['title' => __('Title'), 'summary' => __('Summary'), 'url' => __('URL'), 'link_label' => __('Link label')] as $field => $label): ?>
                <div class="ct-field"><label><?= h($label) ?></label><div class="ct-readonly"><?= nl2br(h((string)($sourceFallback[$field] ?? ''))) ?></div></div>
              <?php endforeach; ?>
              <label class="ct-preview-label"><?= __('Safe source preview') ?></label>
              <iframe class="ct-section-preview" sandbox="allow-same-origin" title="<?= h(__('Source section preview')) ?>" srcdoc="<?= h($sourcePreview) ?>"></iframe>
            </section>
            <section class="ct-package-side ct-package-translation">
              <h4><?= __('Translation') ?></h4>
              <div class="ct-field"><label><?= __('Title') ?></label><input name="sections[<?= $index ?>][title]" value="<?= h((string)$fallback['title']) ?>" required></div>
              <div class="ct-field"><label><?= __('Summary') ?></label><textarea name="sections[<?= $index ?>][summary]" rows="3" required><?= h((string)$fallback['summary']) ?></textarea></div>
              <div class="ct-field"><label><?= __('URL') ?></label><input name="sections[<?= $index ?>][url]" value="<?= h((string)$fallback['url']) ?>"></div>
              <div class="ct-field"><label><?= __('Link label') ?></label><input name="sections[<?= $index ?>][link_label]" value="<?= h((string)$fallback['link_label']) ?>"></div>
              <label class="ct-preview-label"><?= __('Safe translated preview') ?></label>
              <iframe class="ct-section-preview ct-translated-preview" sandbox="allow-same-origin" title="<?= h(__('Translated section preview')) ?>" srcdoc="<?= h($translatedPreview) ?>"></iframe>
              <details class="ct-advanced-html">
                <summary><?= __('Advanced translated HTML') ?></summary>
                <p class="muted"><?= __('Raw bytes are preserved when accepted. Unsafe HTML, URLs, and CSS are rejected on save.') ?></p>
                <textarea class="ct-section-html" name="sections[<?= $index ?>][html]" dir="ltr"><?= h($translatedHtml) ?></textarea>
              </details>
            </section>
          </div>
        </article>
      <?php endforeach; ?>
    </div>
    <div class="ct-actions">
      <button type="submit" class="btn btn-primary"<?= !$identityMatches ? ' disabled' : '' ?>><?= __('Save Theme Section translation') ?></button>
      <?php if ($hasTranslation): ?><button type="button" id="ct-theme-section-delete" class="btn btn-danger"><?= __('Delete Translation') ?></button><?php endif; ?>
    </div>
  </form>
</div>

<script>
(function(){
  const form = document.getElementById('ct-theme-section-form');
  const previewBefore = <?= json_encode($previewShell['before'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
  const previewAfter = <?= json_encode($previewShell['after'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
  const previewDocument = function(html) {
    const documentPreview = new DOMParser().parseFromString(previewBefore + html + previewAfter, 'text/html');
    documentPreview.querySelectorAll('script').forEach(function(script){ script.remove(); });
    return '<!doctype html>' + documentPreview.documentElement.outerHTML;
  };
  const focusedSection = document.querySelector('.ct-package-section--focused');
  if (focusedSection) window.setTimeout(function(){ focusedSection.scrollIntoView({behavior:'smooth',block:'start'}); }, 120);
  const editors = [];
  form.querySelectorAll('.ct-section-html').forEach(function(textarea){
    let editor = null;
    if (window.CodeMirror) {
      editor = CodeMirror.fromTextArea(textarea, {mode:'htmlmixed',lineNumbers:true,lineWrapping:true,matchBrackets:true,autoCloseTags:true,theme:'dracula'});
      editor.setSize('100%', '320px');
    }
    const preview = textarea.closest('.ct-package-translation').querySelector('.ct-translated-preview');
    const update = function(){ preview.srcdoc = previewDocument(editor ? editor.getValue() : textarea.value); };
    if (editor) editor.on('change', update); else textarea.addEventListener('input', update);
    editors.push({textarea:textarea, editor:editor});
  });
  function notify(type, message) {
    if (window.NewNotifToast && typeof window.NewNotifToast.show === 'function') return window.NewNotifToast.show({message:message,type:type});
    const toast = window.safeToast || window.showToast;
    if (typeof toast === 'function') toast(message, type);
  }
  form.addEventListener('submit', async function(event){
    event.preventDefault();
    editors.forEach(function(item){ if (item.editor) item.textarea.value = item.editor.getValue(); });
    try {
      const response = await fetch(<?= json_encode($saveUrl) ?>, {method:'POST',body:new FormData(form),credentials:'same-origin'});
      const data = await response.json();
      if (!data.success) return notify('error', data.error || <?= json_encode(__('Save failed.')) ?>);
      form.elements.translation_state.value = data.translation_state;
      form.elements.source_fingerprint.value = data.source_fingerprint;
      const state = document.getElementById('ct-source-state');
      state.className = 'ct-source-state ct-source-state--current';
      state.textContent = <?= json_encode(__('Current')) ?>;
      notify('success', data.message || <?= json_encode(__('Translation saved.')) ?>);
    } catch (error) {
      notify('error', <?= json_encode(__('Network error.')) ?>);
    }
  });
  const deleteButton = document.getElementById('ct-theme-section-delete');
  if (deleteButton) deleteButton.addEventListener('click', async function(){
    if (!window.confirm(<?= json_encode(__('Delete this translation? This cannot be undone.')) ?>)) return;
    const body = new FormData();
    body.set('csrf_token', form.elements.csrf_token.value);
    body.set('post_id', form.elements.post_id.value);
    body.set('locale', form.elements.locale.value);
    body.set('translation_state', form.elements.translation_state.value);
    try {
      const response = await fetch(<?= json_encode($deleteUrl) ?>, {method:'POST',body:body,credentials:'same-origin'});
      const data = await response.json();
      if (!data.success) return notify('error', data.error || <?= json_encode(__('Delete failed.')) ?>);
      window.location.href = <?= json_encode($listUrl) ?>;
    } catch (error) {
      notify('error', <?= json_encode(__('Network error.')) ?>);
    }
  });
})();
</script>
