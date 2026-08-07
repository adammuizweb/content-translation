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
$saveUrl = $base . '/?page=admin/tools/content-translation/api/save&action=api';
$deleteUrl = $base . '/?page=admin/tools/content-translation/api/delete&action=api';

$postId = (int)($_GET['post_id'] ?? 0);
$locale = trim((string)($_GET['locale'] ?? ''));

if ($postId <= 0 || $locale === '') {
    echo '<p>' . __('Missing post_id or locale.') . '</p>';
    return;
}

$locales = ct_enabled_locales($pdo);
if (!in_array($locale, $locales, true)) {
    echo '<p>' . __('Locale not enabled.') . ' <a href="' . h($overviewUrl) . '">' . __('Back') . '</a></p>';
    return;
}

$stmt = $pdo->prepare("SELECT id, type, title, slug, content, status FROM posts WHERE id = ? AND is_deleted = 0 LIMIT 1");
$stmt->execute([$postId]);
$post = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$post) {
    echo '<p>' . __('Post not found.') . ' <a href="' . h($overviewUrl) . '">' . __('Back') . '</a></p>';
    return;
}

$translation = ct_get_translation($pdo, $postId, $locale) ?? ['title' => '', 'slug' => '', 'content' => '', 'meta_description' => ''];
$defaultLocale = function_exists('content_default_locale') ? content_default_locale() : (function_exists('default_locale') ? default_locale() : 'en');
$usesCodeMirror = $post['type'] === 'theme'
    || ct_content_requires_codemirror((string)$post['content'])
    || ct_content_requires_codemirror((string)$translation['content']);
$publishedTranslation = ct_get_published_translation($pdo, $postId, $locale);
$previewUrl = ct_post_url((string)($translation['slug'] !== '' ? $translation['slug'] : $post['slug']), $locale);
$isRtl = ct_locale_direction($pdo, $locale) === 'rtl';
?>

<div class="ct-admin ct-editor">
  <div class="ct-header">
    <div>
      <h2><?= __('Edit Translation') ?> — <?= h(strtoupper($locale)) ?></h2>
      <p class="muted">
        <?= h((string)$post['title']) ?>
        <span class="badge"><?= $post['type'] === 'page' ? __('Page') : ($post['type'] === 'theme' ? __('Theme') : __('Post')) ?></span>
      </p>
    </div>
    <div class="ct-header-actions">
      <a class="btn" href="<?= h($overviewUrl) ?>"><?= __('Back') ?></a>
      <?php if ($publishedTranslation): ?>
        <a class="btn" href="<?= h($previewUrl) ?>" target="_blank" rel="noopener"><?= __('Preview') ?></a>
      <?php endif; ?>
    </div>
  </div>

  <div class="ct-editor-stack">
    <section class="ct-panel ct-translation-panel<?= $isRtl ? ' ct-rtl-editor' : '' ?>" dir="<?= $isRtl ? 'rtl' : 'ltr' ?>">
      <h3><?= __('Translation') ?> (<?= h(strtoupper($locale)) ?>)</h3>
      <form id="ct-form">
        <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="post_id" value="<?= $postId ?>">
        <input type="hidden" name="locale" value="<?= h($locale) ?>">

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
    </section>

    <details class="ct-panel ct-source-panel">
      <summary><?= __('Original') ?> (<?= h(strtoupper($defaultLocale)) ?>)</summary>
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
            <div class="ct-readonly ct-readonly-content"><?= (string)$post['content'] ?></div>
          <?php endif; ?>
        </div>
      </div>
    </details>
  </div>
</div>

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
      modules: { toolbar: [
        [{ header: [1, 2, 3, false] }],
        ['bold', 'italic', 'underline', 'strike'],
        [{ list: 'ordered' }, { list: 'bullet' }],
        ['blockquote', 'code-block', 'link', 'image'],
        ['clean']
      ] }
    });
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

  form.addEventListener('submit', async function(e) {
    e.preventDefault();
    const fd = new FormData(form);
    fd.set('content', getContent());
    try {
      const res = await fetch('<?= $saveUrl ?>', { method: 'POST', body: fd, credentials: 'same-origin' });
      const data = await res.json();
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
    try {
      const res = await fetch('<?= $deleteUrl ?>', { method: 'POST', body: fd, credentials: 'same-origin' });
      const data = await res.json();
      if (data.success) {
        window.location.href = '<?= $overviewUrl ?>';
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
