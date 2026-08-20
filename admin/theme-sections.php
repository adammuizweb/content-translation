<?php
declare(strict_types=1);

if (!function_exists('h')) {
    function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
}

$pdo = $GLOBALS['pdo'] ?? null;
if (!$pdo instanceof PDO) { echo '<p>' . h(__('Database not available.')) . '</p>'; return; }
if (!ct_user_can_workspace($pdo) || !ct_user_is_site_owner($pdo)) { http_response_code(404); return; }
ct_ensure_schema($pdo);

$base = defined('ADMIN_BASE_PATH') ? ADMIN_BASE_PATH : '/adiwira';
$backUrl = $base . '/?page=admin/tools/content-translation';
$selfUrl = $base . '/?page=admin/tools/content-translation/theme-sections';
$editUrl = $base . '/?page=admin/tools/content-translation/theme-section-edit';
$locales = ct_enabled_locales($pdo);
$qInput = $_GET['q'] ?? '';
$q = is_scalar($qInput) ? trim((string)$qInput) : '';
$q = function_exists('mb_substr') ? mb_substr($q, 0, 100, 'UTF-8') : substr($q, 0, 100);
$pageInput = $_GET['p'] ?? '1';
$pageValue = is_scalar($pageInput) ? (string)$pageInput : '1';
$requestedPage = preg_match('/\A[1-9][0-9]{0,5}\z/', $pageValue) === 1 ? min(100000, (int)$pageValue) : 1;
$perPage = 20;
$requestedOffset = ($requestedPage - 1) * $perPage;
$batchSize = 250;
$candidateCursor = PHP_INT_MAX;
$total = 0;
$posts = [];
$lastMatches = [];
$where = "type = 'theme' AND is_deleted = 0 AND LOCATE('widget:theme_section', content) > 0 AND id < ?";
$searchParams = [];
$contentStmt = $pdo->prepare('SELECT content FROM posts WHERE id = ? LIMIT 1');
if ($q !== '') {
    $where .= ' AND (title LIKE ? OR slug LIKE ?)';
    $searchParams = ['%' . $q . '%', '%' . $q . '%'];
}
do {
    $stmt = $pdo->prepare("SELECT id, type, title, slug, status FROM posts WHERE {$where} ORDER BY id DESC LIMIT {$batchSize}");
    $stmt->execute(array_merge([$candidateCursor], $searchParams));
    $candidates = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($candidates as $post) {
        $contentStmt->execute([(int)$post['id']]);
        $post['content'] = (string)$contentStmt->fetchColumn();
        if (ct_parse_theme_section_composition($post['content']) === null) continue;
        if ($total >= $requestedOffset && count($posts) < $perPage) $posts[] = $post;
        $lastMatches[] = $post;
        if (count($lastMatches) > $perPage) array_shift($lastMatches);
        $total++;
    }
    if ($candidates !== []) $candidateCursor = (int)$candidates[count($candidates) - 1]['id'];
} while (count($candidates) === $batchSize);

$totalPages = max(1, (int)ceil($total / $perPage));
$pageNum = min($requestedPage, $totalPages);
if ($pageNum !== $requestedPage) {
    $desiredOffset = ($pageNum - 1) * $perPage;
    $lastStart = max(0, $total - count($lastMatches));
    $posts = array_slice($lastMatches, max(0, $desiredOffset - $lastStart), $perPage);
}
?>
<div class="ct-admin">
  <div class="ct-header">
    <div>
      <h2><?= __('Theme Sections') ?></h2>
      <p class="muted"><?= __('Package-composed Theme Templates. Section identity and order come from the source template and cannot be edited here.') ?></p>
    </div>
    <a class="btn" href="<?= h($backUrl) ?>"><?= __('Back') ?></a>
  </div>

  <?php if (empty($locales)): ?>
    <div class="ct-flash ct-flash-warning"><?= __('No translation locales enabled.') ?></div>
  <?php endif; ?>

  <form method="get" class="ct-toolbar">
    <input type="hidden" name="page" value="admin/tools/content-translation/theme-sections">
    <input type="search" name="q" value="<?= h($q) ?>" placeholder="<?= h(__('Search title or slug…')) ?>">
    <button type="submit" class="btn btn-primary"><?= __('Filter') ?></button>
  </form>

  <table class="ct-table ct-theme-sections-table">
    <thead><tr><th><?= __('Theme Template') ?></th><th><?= __('Composition') ?></th><th class="ct-translations-col"><?= __('Translations') ?></th></tr></thead>
    <tbody>
      <?php if ($posts === []): ?><tr><td colspan="3" class="muted"><?= __('No package-composed Theme Templates found.') ?></td></tr><?php endif; ?>
      <?php foreach ($posts as $post): ?>
        <?php
          $parsed = ct_parse_theme_section_composition((string)$post['content']) ?? [];
          $resource = ct_theme_section_source_resource($pdo, $post, false);
          $names = array_map(static fn(array $section): string => (string)$section['name'], $parsed);
        ?>
        <tr>
          <td><strong><?= h((string)$post['title']) ?></strong><br><code><?= h((string)$post['slug']) ?></code></td>
          <td>
            <?php if ($resource === null): ?>
              <span class="ct-source-state ct-source-state--stale"><?= __('Unavailable') ?></span>
              <small class="muted"><?= __('A referenced Theme Section is not currently registered.') ?></small>
            <?php else: ?>
              <span class="ct-section-order"><?= h(implode(' → ', $names)) ?></span>
            <?php endif; ?>
          </td>
          <td class="ct-translations-col">
            <?php if ($resource !== null): ?>
              <select class="ct-translation-select" aria-label="<?= h(__('Translations')) ?>" onchange="if(this.value) window.location.href=this.value">
                <option value=""><?= __('Choose language…') ?></option>
                <?php foreach ($locales as $locale): ?>
                  <?php
                    $translation = ct_get_translation($pdo, (int)$post['id'], $locale);
                    $saved = $translation ? ct_theme_section_saved_source_fingerprint($pdo, (int)$post['id'], $locale) : null;
                    $sourceState = ct_theme_section_source_state($saved, (string)$resource['source_fingerprint']);
                    $status = $translation ? (string)($translation['status'] ?? 'published') : __('Add');
                    $stateLabel = $sourceState === 'current' ? __('Current') : ($sourceState === 'stale' ? __('Stale source') : __('Unverified source'));
                    $label = strtoupper($locale) . ' — ' . ($translation ? __($status === 'draft' ? 'Draft' : 'Published') : $status) . ' / ' . $stateLabel;
                  ?>
                  <option value="<?= h($editUrl . '&post_id=' . (int)$post['id'] . '&locale=' . urlencode($locale)) ?>"><?= h($label) ?></option>
                <?php endforeach; ?>
              </select>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <?php if ($totalPages > 1): ?>
    <nav class="ct-pagination" aria-label="<?= h(__('Theme Sections pages')) ?>">
      <?php for ($page = 1; $page <= $totalPages; $page++): ?>
        <?php if ($page === $pageNum): ?>
          <span class="ct-page-current"><?= $page ?></span>
        <?php else: ?>
          <a href="<?= h($selfUrl . '&p=' . $page . ($q !== '' ? '&q=' . rawurlencode($q) : '')) ?>"><?= $page ?></a>
        <?php endif; ?>
      <?php endfor; ?>
    </nav>
  <?php endif; ?>
</div>
