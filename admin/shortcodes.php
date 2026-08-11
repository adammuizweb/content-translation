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
$hubUrl = $base . '/?page=admin/tools/content-translation';
$selfUrl = $base . '/?page=admin/tools/content-translation/shortcodes';
$editUrl = $base . '/?page=admin/tools/content-translation/shortcode-edit';
$repairUrl = $base . '/?page=admin/tools/content-translation/api/shortcode-save&action=api';
$settingsUrl = $base . '/?page=admin/tools/content-translation/settings';
$noticeInput = $_GET['notice'] ?? '';
$noticeCode = is_scalar($noticeInput) ? (string)$noticeInput : '';
$notices = [
    'translation_deleted' => __('Translation deleted.'),
    'orphan_translations_removed' => __('Orphan translations removed.'),
];
$notice = $notices[$noticeCode] ?? '';
$locales = ct_enabled_locales($pdo);
$qInput = $_GET['q'] ?? '';
$q = is_scalar($qInput) ? trim((string)$qInput) : '';
$q = ct_shortcode_preset_text_slice($q, 100);
$pageInput = $_GET['p'] ?? '1';
$pageValue = is_scalar($pageInput) ? (string)$pageInput : '1';
$requestedPage = preg_match('/\A[1-9][0-9]{0,5}\z/', $pageValue) === 1 ? min(100000, (int)$pageValue) : 1;
$perPage = 20;

$where = "p.type = 'sc_preset' AND p.is_deleted = 0";
$params = [];
if ($q !== '') {
    $where .= " AND (p.title LIKE :search ESCAPE '!' OR p.slug LIKE :search ESCAPE '!')";
    $params[':search'] = ct_shortcode_preset_like_pattern($q);
}
$countStmt = $pdo->prepare("SELECT COUNT(*) FROM posts p WHERE {$where}");
$countStmt->execute($params);
$total = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($total / $perPage));
$pageNum = min($requestedPage, $totalPages);
$offset = ($pageNum - 1) * $perPage;
$listStmt = $pdo->prepare("SELECT p.id, p.title, p.slug, p.status, p.meta, p.updated_at FROM posts p WHERE {$where} ORDER BY p.updated_at DESC, p.id DESC LIMIT :limit OFFSET :offset");
foreach ($params as $key => $value) $listStmt->bindValue($key, $value, PDO::PARAM_STR);
$listStmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$listStmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$listStmt->execute();
$presets = $listStmt->fetchAll(PDO::FETCH_ASSOC);
$statuses = ct_shortcode_preset_translation_statuses($pdo, array_column($presets, 'id'));
$storageError = ct_schema_error($pdo);
$orphanCount = $storageError === null ? ct_shortcode_preset_orphan_count($pdo) : 0;
$storageError = ct_schema_error($pdo);
$sourceConfig = static function (array $preset) use ($pdo): array {
    if (function_exists('shortcode_preset_config_loaded')) {
        return shortcode_preset_config_loaded((string)($preset['meta'] ?? ''), $preset, $pdo);
    }
    $decoded = json_decode((string)($preset['meta'] ?? ''), true);
    return is_array($decoded) ? $decoded : [];
};
?>
<div class="ct-admin">
  <div class="ct-header">
    <div>
      <h2><?= __('Shortcode Presets') ?></h2>
      <p class="muted"><?= __('Translate preset management titles and the optional kicker shown above fetched content. Query and layout settings always remain source-controlled.') ?></p>
    </div>
    <a class="btn" href="<?= h($hubUrl) ?>"><?= __('Back') ?></a>
  </div>

  <?php if (empty($locales)): ?>
    <div class="ct-flash ct-flash-warning"><?= __('No translation locales enabled.') ?> <a href="<?= h($settingsUrl) ?>"><?= __('Configure locales') ?></a></div>
  <?php endif; ?>
  <?php if ($storageError !== null): ?>
    <div class="ct-flash ct-flash-error"><?= __('Preset translation storage is unavailable. Source preset behavior is unchanged; check the server error log before retrying.') ?></div>
  <?php elseif ($orphanCount > 0): ?>
    <div class="ct-flash ct-flash-warning">
      <?= h(sprintf(__('%d orphan preset translation(s) were found.'), $orphanCount)) ?>
      <button type="button" class="btn" id="ct-repair-preset-orphans"><?= __('Remove orphan translations') ?></button>
    </div>
  <?php endif; ?>
  <?php if ($notice !== ''): ?><div class="ct-flash" role="status" aria-live="polite"><?= h($notice) ?></div><?php endif; ?>

  <form method="get" class="ct-toolbar">
    <input type="hidden" name="page" value="admin/tools/content-translation/shortcodes">
    <input type="search" name="q" maxlength="100" value="<?= h($q) ?>" placeholder="<?= h(__('Search preset title or slug…')) ?>">
    <button type="submit" class="btn btn-primary"><?= __('Filter') ?></button>
    <?php if ($q !== ''): ?><a class="btn" href="<?= h($selfUrl) ?>"><?= __('Reset') ?></a><?php endif; ?>
  </form>

  <div class="ct-table-scroll">
    <table class="ct-table ct-preset-table">
      <thead><tr><th><?= __('Preset') ?></th><th><?= __('Source heading') ?></th><th><?= __('Source status') ?></th><th><?= __('Locale translations') ?></th></tr></thead>
      <tbody>
        <?php if ($presets === []): ?><tr><td colspan="4" class="muted"><?= __('No Shortcode Presets found.') ?></td></tr><?php endif; ?>
        <?php foreach ($presets as $preset): ?>
          <?php
            $config = $sourceConfig($preset);
            $sourceMode = ct_shortcode_preset_kicker_mode($config);
            $sourceKicker = $sourceMode === 'custom' && is_scalar($config['kicker'] ?? null) ? trim((string)$config['kicker']) : '';
          ?>
          <tr>
            <td><strong><?= h((string)$preset['title']) ?></strong><br><code>[[widget:<?= h((string)$preset['slug']) ?>]]</code></td>
            <td>
              <?php if ($sourceMode === 'custom'): ?><?= h($sourceKicker) ?>
              <?php elseif ($sourceMode === 'hidden'): ?><span class="muted"><?= __('Hidden') ?></span>
              <?php else: ?><span class="muted"><?= __('Automatic') ?></span><?php endif; ?>
            </td>
            <td><span class="badge"><?= h(__(ucfirst((string)$preset['status']))) ?></span></td>
            <td>
              <div class="ct-preset-locales">
                <?php foreach ($locales as $locale): ?>
                  <?php
                    $summary = $statuses[(int)$preset['id']][$locale] ?? null;
                    $status = is_array($summary) ? ($summary['status'] ?? null) : null;
                    $translatedTitle = is_array($summary) ? trim((string)($summary['title'] ?? '')) : '';
                    $statusLabel = $status === 'published' ? __('Published') . ' / ' . __('Edit') : ($status === 'draft' ? __('Draft') . ' / ' . __('Edit') : ($status === 'incomplete' ? __('Incomplete') . ' / ' . __('Edit') : __('Add')));
                    $url = $editUrl . '&preset_id=' . (int)$preset['id'] . '&locale=' . rawurlencode($locale);
                  ?>
                  <a class="ct-preset-locale ct-preset-locale--<?= h($status ?? 'empty') ?>" href="<?= h($url) ?>"><strong><?= h(strtoupper($locale)) ?></strong><span><?= $translatedTitle !== '' ? h($translatedTitle) . '<br>' : '' ?><?= h($statusLabel) ?></span></a>
                <?php endforeach; ?>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <?php if ($totalPages > 1): ?>
    <nav class="ct-pagination" aria-label="<?= h(__('Shortcode Preset pages')) ?>">
      <?php if ($pageNum > 1): ?><a href="<?= h($selfUrl . '&p=' . ($pageNum - 1) . ($q !== '' ? '&q=' . rawurlencode($q) : '')) ?>"><?= __('Previous') ?></a><?php endif; ?>
      <span class="ct-page-current"><?= h(sprintf(__('Page %d of %d'), $pageNum, $totalPages)) ?></span>
      <?php if ($pageNum < $totalPages): ?><a href="<?= h($selfUrl . '&p=' . ($pageNum + 1) . ($q !== '' ? '&q=' . rawurlencode($q) : '')) ?>"><?= __('Next') ?></a><?php endif; ?>
    </nav>
  <?php endif; ?>
</div>
<?php if ($orphanCount > 0): ?>
<script>
(function(){
  const button = document.getElementById('ct-repair-preset-orphans');
  if (!button) return;
  button.addEventListener('click', async function(){
    button.disabled = true;
    const body = new FormData();
    body.set('csrf_token', <?= json_encode(csrf_token()) ?>);
    body.set('intent', 'repair_orphans');
    try {
      const response = await fetch(<?= json_encode($repairUrl) ?>, {method:'POST',body:body,credentials:'same-origin'});
      const data = await response.json();
      if (!data.success) throw new Error(data.error || <?= json_encode(__('Orphan cleanup failed.')) ?>);
      window.location.href = <?= json_encode($selfUrl . '&notice=orphan_translations_removed') ?>;
    } catch (error) {
      button.disabled = false;
      const toast = window.safeToast || window.showToast;
      if (typeof toast === 'function') toast(error.message || <?= json_encode(__('Orphan cleanup failed.')) ?>, 'error');
    }
  });
})();
</script>
<?php endif; ?>
