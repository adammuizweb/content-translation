<?php
declare(strict_types=1);

// Content Translation — overview

if (!function_exists('h')) {
    function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
}

$pdo = $GLOBALS['pdo'] ?? null;
if (!$pdo) { echo '<p>Database not available.</p>'; return; }

ct_ensure_schema($pdo);

$base = defined('ADMIN_BASE_PATH') ? ADMIN_BASE_PATH : '/adiwira';
$selfUrl = $base . '/?page=admin/tools/content-translation';
$editUrl = $base . '/?page=admin/tools/content-translation/edit';
$settingsUrl = $base . '/?page=admin/tools/content-translation/settings';

$locales = ct_enabled_locales($pdo);
$defaultLocale = function_exists('default_locale') ? default_locale() : 'en';

$q = trim((string)($_GET['q'] ?? ''));
$typeFilter = trim((string)($_GET['type'] ?? ''));
$pageNum = max(1, (int)($_GET['p'] ?? 1));
$perPage = 20;
$offset = ($pageNum - 1) * $perPage;

$where = "is_deleted = 0 AND type IN ('article','page')";
$params = [];
if ($typeFilter !== '' && in_array($typeFilter, ['article', 'page'], true)) {
    $where .= " AND type = ?";
    $params[] = $typeFilter;
}
if ($q !== '') {
    $where .= " AND (title LIKE ? OR slug LIKE ?)";
    $params[] = '%' . $q . '%';
    $params[] = '%' . $q . '%';
}

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM posts WHERE $where");
$countStmt->execute($params);
$total = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($total / $perPage));

$listStmt = $pdo->prepare("SELECT id, type, title, slug, status FROM posts WHERE $where ORDER BY updated_at DESC LIMIT $perPage OFFSET $offset");
$listStmt->execute($params);
$posts = $listStmt->fetchAll(PDO::FETCH_ASSOC);

$statuses = ct_translation_statuses($pdo, array_map(fn($p) => (int)$p['id'], $posts));

$flash = $_GET['flash'] ?? '';
$flashType = $_GET['flash_type'] ?? 'success';
?>

<div class="ct-admin">
  <div class="ct-header">
    <div>
      <h2><?= __('Content Translation') ?></h2>
      <p class="muted"><?= __('Manage manual translations for posts and pages. Default locale:') ?> <strong><?= h(strtoupper($defaultLocale)) ?></strong></p>
    </div>
    <a class="btn" href="<?= h($settingsUrl) ?>"><?= __('Settings') ?></a>
  </div>

  <?php if ($flash): ?>
    <div class="ct-flash ct-flash-<?= h($flashType) ?>"><?= h($flash) ?></div>
  <?php endif; ?>

  <?php if (empty($locales)): ?>
    <div class="ct-flash ct-flash-warning">
      <?= __('No translation locales enabled.') ?>
      <a href="<?= h($settingsUrl) ?>"><?= __('Configure locales') ?></a>
    </div>
  <?php endif; ?>

  <form method="get" class="ct-toolbar">
    <input type="hidden" name="page" value="admin/tools/content-translation">
    <input type="search" name="q" value="<?= h($q) ?>" placeholder="<?= __('Search title or slug…') ?>">
    <select name="type">
      <option value=""><?= __('All types') ?></option>
      <option value="article" <?= $typeFilter === 'article' ? 'selected' : '' ?>><?= __('Posts') ?></option>
      <option value="page" <?= $typeFilter === 'page' ? 'selected' : '' ?>><?= __('Pages') ?></option>
    </select>
    <button type="submit" class="btn btn-primary"><?= __('Filter') ?></button>
  </form>

  <table class="ct-table">
    <thead>
      <tr>
        <th><?= __('Title') ?></th>
        <th><?= __('Type') ?></th>
        <th><?= __('Slug') ?></th>
        <?php foreach ($locales as $locale): ?>
          <th class="ct-locale-col"><?= h(strtoupper($locale)) ?></th>
        <?php endforeach; ?>
      </tr>
    </thead>
    <tbody>
      <?php if (empty($posts)): ?>
        <tr><td colspan="<?= 3 + count($locales) ?>" class="muted"><?= __('No content found.') ?></td></tr>
      <?php endif; ?>
      <?php foreach ($posts as $post): ?>
        <tr>
          <td>
            <strong><?= h((string)$post['title']) ?></strong>
            <?php if (($post['status'] ?? '') !== 'published'): ?>
              <span class="badge"><?= h((string)$post['status']) ?></span>
            <?php endif; ?>
          </td>
          <td><?= $post['type'] === 'page' ? __('Page') : __('Post') ?></td>
          <td><code><?= h((string)$post['slug']) ?></code></td>
          <?php foreach ($locales as $locale): ?>
            <?php $has = !empty($statuses[(int)$post['id']][$locale]); ?>
            <td class="ct-locale-col">
              <a class="ct-status <?= $has ? 'ct-status-done' : 'ct-status-empty' ?>"
                 href="<?= h($editUrl . '&post_id=' . (int)$post['id'] . '&locale=' . urlencode($locale)) ?>">
                <?= $has ? __('Edit') : __('Add') ?>
              </a>
            </td>
          <?php endforeach; ?>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>

  <?php if ($totalPages > 1): ?>
    <div class="ct-pagination">
      <?php for ($i = 1; $i <= $totalPages; $i++): ?>
        <?php if ($i === $pageNum): ?>
          <span class="ct-page-current"><?= $i ?></span>
        <?php else: ?>
          <a href="<?= h($selfUrl . '&p=' . $i . ($q !== '' ? '&q=' . urlencode($q) : '') . ($typeFilter !== '' ? '&type=' . urlencode($typeFilter) : '')) ?>"><?= $i ?></a>
        <?php endif; ?>
      <?php endfor; ?>
    </div>
  <?php endif; ?>
</div>
