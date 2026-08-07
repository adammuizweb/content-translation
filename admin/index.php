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
$route = trim((string)($_GET['page'] ?? ''), '/');
$section = substr($route, strrpos($route, '/') + 1);

$locales = ct_enabled_locales($pdo);
$defaultLocale = function_exists('content_default_locale') ? content_default_locale() : (function_exists('default_locale') ? default_locale() : 'en');

if ($section === 'content-translation'):
?>
<div class="ct-admin ct-hub">
  <div class="ct-header">
    <div><h2><?= __('Content Translation') ?></h2><p class="muted"><?= __('Choose what you want to translate. Content is only published after its reviewed translation is saved.') ?></p></div>
    <a class="btn" href="<?= h($settingsUrl) ?>"><?= __('Settings') ?></a>
  </div>
  <?php if (empty($locales)): ?><div class="ct-flash ct-flash-warning"><?= __('No translation locales enabled.') ?> <a href="<?= h($settingsUrl) ?>"><?= __('Configure locales') ?></a></div><?php endif; ?>
  <section class="ct-hub-grid">
    <a class="ct-hub-card ct-hub-card--primary" href="<?= h($selfUrl . '/posts') ?>"><i>01</i><strong><?= __('Posts') ?></strong><span><?= __('Translate article title, slug, content, and SEO description.') ?></span><b><?= __('Manage posts') ?> →</b></a>
    <a class="ct-hub-card ct-hub-card--primary" href="<?= h($selfUrl . '/pages') ?>"><i>02</i><strong><?= __('Pages') ?></strong><span><?= __('Translate standalone pages with the same reviewed workflow.') ?></span><b><?= __('Manage pages') ?> →</b></a>
    <a class="ct-hub-card ct-hub-card--primary" href="<?= h($selfUrl . '/themes') ?>"><i>03</i><strong><?= __('Theme Partials') ?></strong><span><?= __('Translate database-backed theme content and its metadata.') ?></span><b><?= __('Manage theme partials') ?> →</b></a>
    <a class="ct-hub-card" href="<?= h($base . '/?page=admin/categories/index') ?>"><i>04</i><strong><?= __('Categories') ?></strong><span><?= __('Open a category, then choose its translation language in the editor.') ?></span><b><?= __('Open categories') ?> →</b></a>
    <a class="ct-hub-card" href="<?= h($base . '/?page=admin/menus/index') ?>"><i>05</i><strong><?= __('Menus') ?></strong><span><?= __('Translate navigation labels and manual URLs from the menu editor.') ?></span><b><?= __('Open menus') ?> →</b></a>
    <a class="ct-hub-card" href="<?= h($base . '/?page=admin/sidebar/index') ?>"><i>06</i><strong><?= __('Sidebar') ?></strong><span><?= __('Translate widget titles and supported widget text in each sidebar zone.') ?></span><b><?= __('Open sidebar') ?> →</b></a>
    <a class="ct-hub-card" href="<?= h($base . '/?page=admin/settings/site') ?>"><i>07</i><strong><?= __('Site Identity') ?></strong><span><?= __('Set localized site title and description for homepage and metadata.') ?></span><b><?= __('Open site settings') ?> →</b></a>
    <a class="ct-hub-card" href="<?= h($base . '/?page=admin/profile/index') ?>"><i>08</i><strong><?= __('Author Profiles') ?></strong><span><?= __('Translate the author bio from each user profile.') ?></span><b><?= __('Open profiles') ?> →</b></a>
    <a class="ct-hub-card" href="<?= h($settingsUrl) ?>"><i>09</i><strong><?= __('Languages & Sitemap') ?></strong><span><?= __('Enable locales, choose sitemap languages, and download a backup.') ?></span><b><?= __('Open settings') ?> →</b></a>
  </section>
  <aside class="ct-hub-tip"><strong><?= __('How it works') ?></strong><span><?= __('Default-language content stays unchanged. A locale URL and sitemap entry appear only after a reviewed translation is published.') ?></span></aside>
</div>
<?php return; endif;

$q = trim((string)($_GET['q'] ?? ''));
$sectionTypes = ['posts' => 'article', 'pages' => 'page', 'themes' => 'theme'];
$typeFilter = $sectionTypes[$section] ?? trim((string)($_GET['type'] ?? ''));
$pageNum = max(1, (int)($_GET['p'] ?? 1));
$perPage = 20;
$offset = ($pageNum - 1) * $perPage;

$where = "is_deleted = 0 AND type IN ('article','page','theme')";
$params = [];
if ($typeFilter !== '' && in_array($typeFilter, ['article', 'page', 'theme'], true)) {
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
      <h2><?= $typeFilter === 'article' ? __('Posts') : ($typeFilter === 'page' ? __('Pages') : __('Theme Partials')) ?></h2>
      <p class="muted"><?= __('Manage reviewed translations for site content. Default locale:') ?> <strong><?= h(strtoupper($defaultLocale)) ?></strong></p>
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
    <input type="hidden" name="page" value="<?= h($route) ?>">
    <input type="search" name="q" value="<?= h($q) ?>" placeholder="<?= __('Search title or slug…') ?>">
    <select name="type">
      <option value=""><?= __('All types') ?></option>
      <option value="article" <?= $typeFilter === 'article' ? 'selected' : '' ?>><?= __('Posts') ?></option>
      <option value="page" <?= $typeFilter === 'page' ? 'selected' : '' ?>><?= __('Pages') ?></option>
      <option value="theme" <?= $typeFilter === 'theme' ? 'selected' : '' ?>><?= __('Themes') ?></option>
    </select>
    <button type="submit" class="btn btn-primary"><?= __('Filter') ?></button>
  </form>

  <table class="ct-table">
    <thead>
      <tr>
        <th><?= __('Title') ?></th>
        <th><?= __('Type') ?></th>
        <th><?= __('Slug') ?></th>
        <th class="ct-translations-col"><?= __('Translations') ?></th>
      </tr>
    </thead>
    <tbody>
      <?php if (empty($posts)): ?>
        <tr><td colspan="4" class="muted"><?= __('No content found.') ?></td></tr>
      <?php endif; ?>
      <?php foreach ($posts as $post): ?>
        <tr>
          <td>
            <strong><?= h((string)$post['title']) ?></strong>
            <?php if (($post['status'] ?? '') !== 'published'): ?>
              <span class="badge"><?= h((string)$post['status']) ?></span>
            <?php endif; ?>
          </td>
          <td><?= $post['type'] === 'page' ? __('Page') : ($post['type'] === 'theme' ? __('Theme') : __('Post')) ?></td>
          <td><code><?= h((string)$post['slug']) ?></code></td>
          <td class="ct-translations-col">
            <select class="ct-translation-select" aria-label="<?= h(__('Translations')) ?>" onchange="if(this.value) window.location.href=this.value">
              <option value=""><?= __('Choose language…') ?></option>
              <?php foreach ($locales as $locale): ?>
                <?php $status = $statuses[(int)$post['id']][$locale] ?? null; ?>
                <?php $label = strtoupper($locale) . ' — ' . ($status === 'draft' ? __('Draft') : ($status !== null ? __('Edit') : __('Add'))); ?>
                <option value="<?= h($editUrl . '&post_id=' . (int)$post['id'] . '&locale=' . urlencode($locale)) ?>"><?= h($label) ?></option>
              <?php endforeach; ?>
            </select>
          </td>
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
