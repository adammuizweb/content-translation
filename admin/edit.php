<?php
declare(strict_types=1);

// Content Translation — translation editor

if (!function_exists('h')) {
    function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
}

$pdo = $GLOBALS['pdo'] ?? null;
if (!$pdo) { echo '<p>Database not available.</p>'; return; }

ct_ensure_schema($pdo);

$base = defined('ADMIN_BASE_PATH') ? ADMIN_BASE_PATH : '/adiwira';
$overviewUrl = $base . '/?page=admin/tools/content-translation';
$returnUrl = function_exists('adiwira_safe_return_to')
    ? adiwira_safe_return_to($_GET['return_to'] ?? null, $overviewUrl)
    : $overviewUrl;
$saveUrl = $base . '/?page=admin/tools/content-translation/api/save&action=api';
$deleteUrl = $base . '/?page=admin/tools/content-translation/api/delete&action=api';

$postId = (int)($_GET['post_id'] ?? 0);
$locale = trim((string)($_GET['locale'] ?? ''));

if ($postId <= 0 || $locale === '') {
    echo '<p>' . __('Missing post_id or locale.') . '</p>';
    return;
}

$localizedMediaSupported = function_exists('ct_localized_media_supported') && ct_localized_media_supported();
$mediaColumns = $localizedMediaSupported ? ', thumbnail_media_id, thumbnail, youtube' : '';
$stmt = $pdo->prepare("SELECT id, type, title, slug, content, meta, status, created_by{$mediaColumns} FROM posts WHERE id = ? AND is_deleted = 0 LIMIT 1");
$stmt->execute([$postId]);
$post = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$post || !ct_user_can_view_post_representation($pdo, $post)) {
    echo '<p>' . __('Post not found.') . ' <a href="' . h($overviewUrl) . '">' . __('Back') . '</a></p>';
    return;
}

$locales = ct_content_locales($pdo);
if (!in_array($locale, $locales, true)) {
    echo '<p>' . __('Locale not available for this source post.') . ' <a href="' . h($overviewUrl) . '">' . __('Back') . '</a></p>';
    return;
}

$sourceLocale = ct_post_source_locale($pdo, $post);
$isSource = $locale === $sourceLocale;
$canEdit = ct_user_can_edit_post_locale($pdo, $post, $locale);
if (!$isSource && $canEdit && $post['type'] === 'theme' && ct_parse_theme_section_composition((string)$post['content']) !== null) {
    $packageEditor = $base . '/?page=admin/tools/content-translation/theme-section-edit&post_id=' . $postId . '&locale=' . urlencode($locale) . '&return_to=' . rawurlencode($returnUrl);
    if (!headers_sent()) {
        header('Location: ' . $packageEditor, true, 302);
        exit;
    }
    echo '<script>window.location.replace(' . json_encode($packageEditor, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . ')</script>';
    echo '<p><a href="' . h($packageEditor) . '">' . h(__('Open Theme Section editor')) . '</a></p>';
    return;
}

$translationRow = $isSource ? null : ct_get_translation($pdo, $postId, $locale);
$sourceMeta = is_string($post['meta'] ?? null) ? json_decode((string)$post['meta'], true) : [];
$translation = $isSource ? [
    'title' => (string)$post['title'],
    'slug' => (string)$post['slug'],
    'content' => (string)$post['content'],
    'meta_description' => is_array($sourceMeta) ? (string)($sourceMeta['meta_tags']['description'] ?? '') : '',
    'status' => ct_post_source_status($pdo, $post),
] : ($translationRow ?? ['title' => '', 'slug' => '', 'content' => '', 'meta_description' => '', 'status' => 'published']);
$usesCodeMirror = $post['type'] === 'theme'
    || ct_content_requires_codemirror((string)$post['content'])
    || ct_content_requires_codemirror((string)$translation['content']);
$publishedTranslation = $isSource ? (ct_source_post_is_public($pdo, $post) ? $translation : null) : ct_get_published_translation($pdo, $postId, $locale);
$previewUrl = ct_post_url((string)($translation['slug'] !== '' ? $translation['slug'] : $post['slug']), $locale);
$isRtl = ct_locale_direction($pdo, $locale) === 'rtl';
$coreEditor = $base . '/?page=' . ($post['type'] === 'page' ? 'admin/pages/edit' : ($post['type'] === 'theme' ? 'admin/themes/edit' : 'admin/posts/edit')) . '&id=' . $postId;
$supportsFeatured = $localizedMediaSupported && !$isSource && in_array($post['type'], ['article', 'page'], true);
$featuredSelection = $supportsFeatured ? ct_featured_selection($pdo, $postId, $locale) : null;
$featuredMode = (string)($featuredSelection['mode'] ?? 'inherit');
$featuredId = (int)($featuredSelection['media_id'] ?? 0);
$featuredRow = $featuredId > 0 && function_exists('media_load_live') ? media_load_live($pdo, $featuredId) : null;
$featuredUrl = $featuredRow && function_exists('media_client_url') ? media_client_url($featuredRow, true) : null;
$featuredCompatible = $featuredMode !== 'media' || ($featuredRow && media_client_url($featuredRow, false) !== null && ct_media_is_available($pdo, $featuredId, $locale));
$mediaConsumer = $post['type'] === 'page' ? 'page' : 'post';
$pickerBaseUrl = $localizedMediaSupported ? $base . '/admin/modal_img/index.php?embedded=1' : '';
$pickerUrl = $localizedMediaSupported ? $pickerBaseUrl . '&' . media_picker_query([
    'surface' => 'admin.content.translation', 'consumer' => $mediaConsumer, 'resource_id' => $postId,
    'field' => 'featured', 'content_locale' => $locale,
]) : '';
?>

<div class="ct-admin ct-editor">
  <div class="ct-header">
    <div>
      <h2><?= $canEdit && !$isSource ? __('Edit Translation') : __('View Translation') ?> — <?= h(strtoupper($locale)) ?></h2>
      <p class="muted">
        <?= h((string)$post['title']) ?>
        <span class="badge"><?= $post['type'] === 'page' ? __('Page') : ($post['type'] === 'theme' ? __('Theme') : __('Post')) ?></span>
      </p>
    </div>
    <div class="ct-header-actions">
      <?php foreach ($locales as $targetLocale): ?>
        <?php if ($targetLocale === $locale) continue; ?>
        <a class="btn" href="<?= h($base . '/?page=admin/tools/content-translation/edit&post_id=' . $postId . '&locale=' . urlencode($targetLocale) . '&return_to=' . rawurlencode($returnUrl)) ?>"><?= h(strtoupper($targetLocale)) ?></a>
      <?php endforeach; ?>
      <a class="btn" href="<?= h($returnUrl) ?>"><?= __('Back') ?></a>
      <?php if ($isSource && $canEdit): ?><a class="btn btn-primary" href="<?= h($coreEditor) ?>"><?= __('Edit Source') ?></a><?php endif; ?>
      <?php if ($publishedTranslation): ?>
        <a class="btn" href="<?= h($previewUrl) ?>" target="_blank" rel="noopener"><?= __('Preview') ?></a>
      <?php endif; ?>
    </div>
  </div>

  <div class="ct-editor-stack">
    <section class="ct-panel ct-translation-panel<?= $isRtl ? ' ct-rtl-editor' : '' ?>" dir="<?= $isRtl ? 'rtl' : 'ltr' ?>">
      <h3><?= $isSource ? __('Canonical source') : __('Translation') ?> (<?= h(strtoupper($locale)) ?>)</h3>
      <?php if (!$canEdit || $isSource): ?>
        <div class="ct-field"><label><?= __('Title') ?></label><div class="ct-readonly"><?= h((string)$translation['title']) ?></div></div>
        <div class="ct-field"><label><?= __('Slug') ?></label><div class="ct-readonly"><code><?= h((string)$translation['slug']) ?></code></div></div>
        <div class="ct-field"><label><?= __('Meta description') ?></label><div class="ct-readonly"><?= h((string)$translation['meta_description']) ?></div></div>
        <div class="ct-field"><label><?= __('Status') ?></label><div class="ct-readonly"><?= h(ucfirst((string)$translation['status'])) ?></div></div>
        <div class="ct-field"><label><?= __('Content') ?></label><pre class="ct-readonly ct-readonly-content ct-source-code"><?= h((string)$translation['content']) ?></pre></div>
      <?php else: ?>
      <form id="ct-form">
        <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="post_id" value="<?= $postId ?>">
        <input type="hidden" name="locale" value="<?= h($locale) ?>">
        <input type="hidden" name="translation_state" value="<?= h($supportsFeatured ? ct_translation_editor_state($translationRow, $featuredSelection) : ct_translation_row_state_token($translationRow)) ?>">

        <div class="ct-field">
          <label for="ct-title"><?= __('Title') ?></label>
          <input type="text" id="ct-title" name="title" value="<?= h((string)$translation['title']) ?>" maxlength="255" dir="<?= $isRtl ? 'rtl' : 'ltr' ?>">
        </div>
        <div class="ct-field">
          <label for="ct-slug"><?= __('Slug') ?> <small class="muted">(<?= __('leave empty to generate from translated title') ?>)</small></label>
          <input type="text" id="ct-slug" name="slug" value="<?= h((string)$translation['slug']) ?>" maxlength="255" pattern="[a-zA-Z0-9_\-/]*">
        </div>
        <div class="ct-field">
          <label for="ct-meta-description"><?= __('Meta description') ?></label>
          <textarea id="ct-meta-description" name="meta_description" rows="3" maxlength="320" dir="<?= $isRtl ? 'rtl' : 'ltr' ?>" placeholder="<?= h(__('Leave empty to use an excerpt of the translated content.')) ?>"><?= h((string)($translation['meta_description'] ?? '')) ?></textarea>
        </div>
        <div class="ct-field">
          <label for="ct-status"><?= __('Status') ?></label>
          <select id="ct-status" name="status">
            <option value="published"<?= ($translation['status'] ?? 'published') === 'published' ? ' selected' : '' ?>><?= __('Published') ?></option>
            <option value="draft"<?= ($translation['status'] ?? 'published') === 'draft' ? ' selected' : '' ?>><?= __('Draft') ?></option>
          </select>
        </div>
        <?php if ($supportsFeatured): ?>
        <fieldset class="ct-featured-panel">
          <legend><?= __('Localized featured media') ?></legend>
          <p class="muted"><?= __('YouTube remains the first display-image source. This selection is used when no valid YouTube thumbnail is present.') ?></p>
          <label><input type="radio" name="featured_mode" value="inherit"<?= $featuredMode === 'inherit' ? ' checked' : '' ?>> <?= __('Inherit source thumbnail') ?></label>
          <label><input type="radio" name="featured_mode" value="media"<?= $featuredMode === 'media' ? ' checked' : '' ?>> <?= __('Choose media for this locale') ?></label>
          <label><input type="radio" name="featured_mode" value="none"<?= $featuredMode === 'none' ? ' checked' : '' ?>> <?= __('No thumbnail') ?></label>
          <input type="hidden" id="ct-featured-media-id" name="featured_media_id" value="<?= $featuredId ?: '' ?>">
          <div class="ct-featured-preview" id="ct-featured-preview"<?= $featuredUrl ? '' : ' hidden' ?>>
            <img src="<?= h((string)$featuredUrl) ?>" alt="">
            <span><?= $featuredRow ? h((string)($featuredRow['filename'] ?? $featuredRow['title'] ?? '')) : '' ?></span>
          </div>
          <button type="button" class="btn" id="ct-featured-choose"><?= __('Open media picker') ?></button>
          <p id="ct-featured-warning" class="ct-featured-warning"<?= $featuredCompatible ? ' hidden' : '' ?>><?= __('This media is unavailable, private, or deleted for the selected locale. It may remain in a draft but cannot be published.') ?></p>
          <label class="ct-check"><input type="checkbox" name="featured_alt_override_enabled" value="1"<?= $featuredSelection && $featuredSelection['alt_override'] !== null ? ' checked' : '' ?>> <?= __('Override alt text at this use site') ?></label>
          <input type="text" name="featured_alt_override" value="<?= h((string)($featuredSelection['alt_override'] ?? '')) ?>" maxlength="4096">
          <label class="ct-check"><input type="checkbox" name="featured_caption_override_enabled" value="1"<?= $featuredSelection && $featuredSelection['caption_override'] !== null ? ' checked' : '' ?>> <?= __('Override caption at this use site') ?></label>
          <textarea name="featured_caption_override" rows="2"><?= h((string)($featuredSelection['caption_override'] ?? '')) ?></textarea>
        </fieldset>
        <?php endif; ?>
        <div class="ct-field">
          <label><?= __('Content') ?></label>
          <?php if ($usesCodeMirror): ?>
            <p class="muted ct-editor-hint"><?= __('Complex HTML detected. CodeMirror preserves the source markup.') ?></p>
            <textarea id="ct-codemirror" name="content" dir="ltr"><?= h((string)$translation['content']) ?></textarea>
          <?php else: ?>
            <div id="ct-quill" class="adam-quill" dir="<?= $isRtl ? 'rtl' : 'ltr' ?>"><?= (string)$translation['content'] ?></div>
          <?php endif; ?>
        </div>

        <div class="ct-actions">
          <button type="submit" class="btn btn-primary"><?= __('Save Translation') ?></button>
          <button type="button" id="ct-delete" class="btn btn-danger"><?= __('Delete Translation') ?></button>
        </div>
      </form>
      <?php endif; ?>
    </section>

    <details class="ct-panel ct-source-panel">
      <summary><?= __('Original') ?> (<?= h(strtoupper($sourceLocale)) ?>)</summary>
      <div class="ct-source-panel__body">
        <div class="ct-field">
          <label><?= __('Title') ?></label>
          <div class="ct-readonly"><?= h((string)$post['title']) ?></div>
        </div>
        <div class="ct-field">
          <label><?= __('Slug') ?></label>
          <div class="ct-readonly"><code><?= h((string)$post['slug']) ?></code></div>
        </div>
        <div class="ct-field">
          <label><?= __('Content') ?></label>
          <?php if ($usesCodeMirror): ?>
            <pre class="ct-readonly ct-readonly-content ct-source-code"><?= h((string)$post['content']) ?></pre>
          <?php else: ?>
            <pre class="ct-readonly ct-readonly-content"><?= h((string)$post['content']) ?></pre>
          <?php endif; ?>
        </div>
      </div>
    </details>
  </div>
</div>

<?php if ($canEdit && !$isSource): ?>
<div id="ct-delete-modal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,.5); align-items:center; justify-content:center; z-index:5000;">
  <div style="background:var(--adam-card,#fff); color:inherit; padding:2rem; border-radius:8px; max-width:400px; width:90%;">
    <h3 style="margin-top:0;"><?= __('Delete Translation') ?></h3>
    <p><?= __('Delete this translation? This cannot be undone.') ?></p>
    <div style="display:flex; gap:10px; justify-content:flex-end;">
      <button type="button" id="ct-delete-cancel" class="btn"><?= __('Cancel') ?></button>
      <button type="button" id="ct-delete-confirm" class="btn btn-danger"><?= __('Delete') ?></button>
    </div>
  </div>
</div>

<script>
(function(){
  const form = document.getElementById('ct-form');
  let quill = null;
  let codeMirror = null;
  const pickerContext = <?= json_encode(['consumer' => $mediaConsumer, 'resource_id' => $postId, 'field' => 'featured', 'content_locale' => $locale], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
  const inlinePickerContext = <?= json_encode(['surface' => 'admin.content.translation', 'consumer' => $mediaConsumer, 'resource_id' => $postId, 'field' => 'content', 'content_locale' => $locale], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
  const fullToolbar = [
    [{ header: [1, 2, 3, 4, 5, 6, false] }],
    ['bold', 'italic', 'underline', 'strike'],
    [{ color: [] }, { background: [] }],
    [{ script: 'sub' }, { script: 'super' }],
    [{ list: 'ordered' }, { list: 'bullet' }],
    [{ indent: '-1' }, { indent: '+1' }],
    [{ align: [] }],
    ['blockquote', 'code-block'],
    ['link', 'image', 'video'],
    [{ size: ['small', false, 'large', 'huge'] }],
    ['clean']
  ];

  if (document.getElementById('ct-codemirror') && window.CodeMirror) {
    codeMirror = CodeMirror.fromTextArea(document.getElementById('ct-codemirror'), {
      mode: 'htmlmixed',
      lineNumbers: true,
      styleActiveLine: true,
      matchBrackets: true,
      autoCloseBrackets: true,
      autoCloseTags: true,
      lineWrapping: true,
      theme: 'dracula',
      foldGutter: true,
      gutters: ['CodeMirror-linenumbers', 'CodeMirror-foldgutter']
    });
    codeMirror.setSize('100%', '58vh');
  } else if (window.Quill) {
    quill = new Quill('#ct-quill', {
      theme: 'snow',
      modules: { toolbar: fullToolbar },
      placeholder: window.QUILL_PLACEHOLDER || <?= json_encode(__('Write article content here...')) ?>
    });
    const toolbar = quill.getModule('toolbar');
    if (toolbar && typeof toolbar.addHandler === 'function') {
      toolbar.addHandler('image', function(){
        const range = quill.getSelection() || { index: quill.getLength(), length: 0 };
        if (typeof window.openMediaSelector !== 'function') return;
        window.openMediaSelector({
          url: <?= json_encode($pickerBaseUrl, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
          context: inlinePickerContext,
          maxWidth: '980px'
        }).then(function(detail){
          if (!detail) return;
          const url = String(detail.protected_url || detail.url || '');
          if (!url) return;
          quill.insertEmbed(range.index, 'image', url, 'user');
          quill.setSelection(range.index + 1, 0);
          setTimeout(function(){
            const images = Array.from(quill.root.querySelectorAll('img')).filter(function(image){ return image.getAttribute('src') === url; });
            const image = images[images.length - 1];
            if (!image) return;
            if (detail.alt) image.setAttribute('alt', String(detail.alt));
            if (detail.title) image.setAttribute('title', String(detail.title));
            if (detail.caption) image.setAttribute('data-caption', String(detail.caption));
          }, 0);
        }).catch(function(error){ console.warn('[content-translation] media picker failed', error); });
      });
    }
  }

  function notify(type, msg) {
    if (window.NewNotifToast && typeof window.NewNotifToast.show === 'function') {
      window.NewNotifToast.show({ message: msg, type: type });
      return;
    }
    const toast = window.safeToast || window.showToast;
    if (typeof toast === 'function') toast(msg, type);
  }

  function getContent() {
    if (codeMirror) return codeMirror.getValue();
    return quill ? quill.root.innerHTML : '';
  }

  function selectFeatured(detail) {
    if (!detail || !detail.id || !detail.context || !form.elements.featured_media_id) return;
    const context = detail.context;
    if (context.consumer !== pickerContext.consumer || Number(context.resource_id) !== pickerContext.resource_id
        || context.field !== pickerContext.field || context.content_locale !== pickerContext.content_locale) return;
    form.elements.featured_media_id.value = String(detail.id);
    form.querySelector('[name="featured_mode"][value="media"]').checked = true;
    const preview = document.getElementById('ct-featured-preview');
    preview.querySelector('img').src = detail.protected_url || detail.url || '';
    preview.querySelector('span').textContent = detail.filename || detail.title || ('Media #' + detail.id);
    preview.hidden = false;
    const localeAvailable = detail.extensions?.content_translation?.available !== false;
    document.getElementById('ct-featured-warning').hidden = localeAvailable && detail.visibility === 'public' && detail.storage_disk === 'public' && detail.access_scope === 'public';
  }
  document.getElementById('ct-featured-choose')?.addEventListener('click', function(){
    const url = <?= json_encode($pickerUrl, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    if (typeof window.openMediaSelector !== 'function') return;
    window.openMediaSelector({ url: url, maxWidth: '980px' })
      .then(selectFeatured)
      .catch(function(error){ console.warn('[content-translation] featured media picker failed', error); });
  });
  document.addEventListener('media:insert', function(event){ selectFeatured(event.detail); });
  window.addEventListener('message', function(event){ if (event.origin === window.location.origin && event.data?.type === 'media:insert') selectFeatured(event.data.detail); });

  form.addEventListener('submit', async function(e) {
    e.preventDefault();
    const fd = new FormData(form);
    fd.set('content', getContent());
    try {
      const res = await fetch('<?= $saveUrl ?>', { method: 'POST', body: fd, credentials: 'same-origin' });
      const data = await res.json();
      if (data.success && data.translation_state) form.elements.translation_state.value = data.translation_state;
      notify(data.success ? 'success' : 'error', data.success ? (data.message || '<?= __('Translation saved.') ?>') : (data.error || '<?= __('Save failed.') ?>'));
    } catch (err) {
      notify('error', '<?= __('Network error.') ?>');
    }
  });

  const modal = document.getElementById('ct-delete-modal');
  function openModal() {
    modal.style.display = 'flex';
    document.body.style.overflow = 'hidden';
  }
  function closeModal() {
    modal.style.display = 'none';
    document.body.style.overflow = '';
  }
  modal.addEventListener('click', function(e){ if (e.target === modal) closeModal(); });
  document.addEventListener('keydown', function(e){ if (e.key === 'Escape') closeModal(); });
  document.getElementById('ct-delete').addEventListener('click', openModal);
  document.getElementById('ct-delete-cancel').addEventListener('click', closeModal);

  document.getElementById('ct-delete-confirm').addEventListener('click', async function() {
    const fd = new FormData();
    fd.set('csrf_token', form.querySelector('[name=csrf_token]').value);
    fd.set('post_id', '<?= $postId ?>');
     fd.set('locale', '<?= h($locale) ?>');
     fd.set('translation_state', form.querySelector('[name=translation_state]').value);
    try {
      const res = await fetch('<?= $deleteUrl ?>', { method: 'POST', body: fd, credentials: 'same-origin' });
      const data = await res.json();
      if (data.success) {
        window.location.href = <?= json_encode($returnUrl, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
      } else {
        closeModal();
        notify('error', data.error || '<?= __('Delete failed.') ?>');
      }
    } catch (err) {
      closeModal();
      notify('error', '<?= __('Network error.') ?>');
    }
  });
})();
</script>
<?php endif; ?>
