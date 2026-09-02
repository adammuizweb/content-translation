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
$postsUrl = $base . '/?page=admin/tools/content-translation/posts';
$pagesUrl = $base . '/?page=admin/tools/content-translation/pages';
$themesUrl = $base . '/?page=admin/tools/content-translation/themes';
$editUrl = $base . '/?page=admin/tools/content-translation/edit';
$themeFileEditUrl = $base . '/?page=admin/tools/content-translation/theme-file-edit';
$settingsUrl = $base . '/?page=admin/tools/content-translation/settings';
$route = trim((string)($_GET['page'] ?? ''), '/');
$section = substr($route, strrpos($route, '/') + 1);

$locales = ct_enabled_locales($pdo);
$defaultLocale = function_exists('content_default_locale') ? content_default_locale() : (function_exists('default_locale') ? default_locale() : 'en');
$canManageThemeTranslations = ct_user_is_site_owner($pdo)
    && user_can($pdo, ct_current_user_id(), 'core.themes.manage');

if ($section === 'content-translation'):
?>
<div class="ct-admin ct-hub">
  <div class="ct-header">
    <div><h2><?= __('Content Translation') ?></h2><p class="muted"><?= __('Choose what you want to translate. Content is only published after its reviewed translation is saved.') ?></p></div>
    <a class="btn" href="<?= h($settingsUrl) ?>"><?= __('Settings') ?></a>
  </div>
  <?php if (empty($locales)): ?><div class="ct-flash ct-flash-warning"><?= __('No translation locales enabled.') ?> <a href="<?= h($settingsUrl) ?>"><?= __('Configure locales') ?></a></div><?php endif; ?>
  <section class="ct-hub-grid">
    <a class="ct-hub-card ct-hub-card--primary" href="<?= h($postsUrl) ?>"><i>01</i><strong><?= __('Posts') ?></strong><span><?= __('Translate article title, slug, content, and SEO description.') ?></span><b><?= __('Manage posts') ?> →</b></a>
    <a class="ct-hub-card ct-hub-card--primary" href="<?= h($pagesUrl) ?>"><i>02</i><strong><?= __('Pages') ?></strong><span><?= __('Translate standalone pages with the same reviewed workflow.') ?></span><b><?= __('Manage pages') ?> →</b></a>
    <?php if ($canManageThemeTranslations): ?>
    <a class="ct-hub-card ct-hub-card--primary" href="<?= h($themesUrl) ?>"><i>03</i><strong><?= __('Theme Partials') ?></strong><span><?= __('Translate database-backed theme content and its metadata.') ?></span><b><?= __('Manage theme partials') ?> →</b></a>
    <a class="ct-hub-card ct-hub-card--primary" href="<?= h($base . '/?page=admin/tools/content-translation/theme-sections') ?>"><i>04</i><strong><?= __('Theme Sections') ?></strong><span><?= __('Translate locked Theme Template compositions section by section.') ?></span><b><?= __('Manage theme sections') ?> →</b></a>
    <a class="ct-hub-card ct-hub-card--primary" href="<?= h($base . '/?page=admin/tools/content-translation/theme-files') ?>"><i>05</i><strong><?= __('Theme Files') ?></strong><span><?= __('Translate declared text fields rendered by active theme files.') ?></span><b><?= __('Manage theme files') ?> →</b></a>
    <a class="ct-hub-card ct-hub-card--primary" href="<?= h($base . '/?page=admin/themes/customize') ?>"><i>06</i><strong><?= __('Theme Zones') ?></strong><span><?= __('Open Customize to translate declared gadget text while layout and behavior stay shared.') ?></span><b><?= __('Open Customize') ?> →</b></a>
    <a class="ct-hub-card ct-hub-card--primary" href="<?= h($base . '/?page=admin/tools/content-translation/theme-strings') ?>"><i>07</i><strong><?= __('Theme UI Strings') ?></strong><span><?= __('Translate literal interface strings discovered from physical theme PHP source.') ?></span><b><?= __('Manage theme strings') ?> →</b></a>
    <?php endif; ?>
    <a class="ct-hub-card ct-hub-card--primary" href="<?= h($base . '/?page=admin/shortcodes/index&tab=presets') ?>"><i>08</i><strong><?= __('Shortcode Presets') ?></strong><span><?= __('Open a source preset, then choose a language beside its heading settings. Query and layout configuration stay shared.') ?></span><b><?= __('Open Shortcode Presets') ?> →</b></a>
    <a class="ct-hub-card" href="<?= h($base . '/?page=admin/categories/index') ?>"><i>09</i><strong><?= __('Categories') ?></strong><span><?= __('Open a category, then choose its translation language in the editor.') ?></span><b><?= __('Open categories') ?> →</b></a>
    <a class="ct-hub-card" href="<?= h($base . '/?page=admin/menus/index') ?>"><i>10</i><strong><?= __('Menus') ?></strong><span><?= __('Translate navigation labels and manual URLs from the menu editor.') ?></span><b><?= __('Open menus') ?> →</b></a>
    <a class="ct-hub-card" href="<?= h($base . '/?page=admin/sidebar/index') ?>"><i>11</i><strong><?= __('Sidebar') ?></strong><span><?= __('Translate widget titles and supported widget text in each sidebar zone.') ?></span><b><?= __('Open sidebar') ?> →</b></a>
    <a class="ct-hub-card" href="<?= h($base . '/?page=admin/settings/site') ?>"><i>12</i><strong><?= __('Site Identity') ?></strong><span><?= __('Set localized site title and description for homepage and metadata.') ?></span><b><?= __('Open site settings') ?> →</b></a>
    <a class="ct-hub-card" href="<?= h($base . '/?page=admin/profile/index') ?>"><i>13</i><strong><?= __('Author Profiles') ?></strong><span><?= __('Translate the author bio from each user profile.') ?></span><b><?= __('Open profiles') ?> →</b></a>
    <a class="ct-hub-card" href="<?= h($settingsUrl) ?>"><i>14</i><strong><?= __('Languages & Sitemap') ?></strong><span><?= __('Enable locales, choose sitemap languages, and download a backup.') ?></span><b><?= __('Open settings') ?> →</b></a>
  </section>
  <aside class="ct-hub-tip"><strong><?= __('How it works') ?></strong><span><?= __('Default-language content stays unchanged. A locale URL and sitemap entry appear only after a reviewed translation is published.') ?></span></aside>
</div>
<?php return; endif;

if ($section === 'theme-files'):
    if (!$canManageThemeTranslations) { http_response_code(404); return; }
    $resources = ct_theme_file_resources($pdo);
    $homepageResource = ct_homepage_theme_file_resource($pdo);
    if ($homepageResource && !isset($resources[$homepageResource['id']])) {
        $resources += ct_theme_file_resources($pdo, (string)$homepageResource['theme_folder']);
    }
    $statuses = ct_theme_file_translation_statuses($pdo, $resources);
?>
<div class="ct-admin">
  <div class="ct-header">
    <div>
      <h2><?= __('Theme Files') ?></h2>
      <p class="muted"><?= __('File-backed resources declared by the active theme and the theme assigned to the homepage. A published locale must contain every declared translatable field.') ?></p>
    </div>
    <a class="btn" href="<?= h($selfUrl) ?>"><?= __('Back') ?></a>
  </div>
  <?php if (!empty($_GET['flash'])): ?><div class="ct-flash"><?= h((string)$_GET['flash']) ?></div><?php endif; ?>
  <?php if (empty($locales)): ?>
    <div class="ct-flash ct-flash-warning"><?= __('No translation locales enabled.') ?> <a href="<?= h($settingsUrl) ?>"><?= __('Configure locales') ?></a></div>
  <?php endif; ?>
  <table class="ct-table">
    <thead><tr><th><?= __('Resource') ?></th><th><?= __('Slot') ?></th><th><?= __('Fields') ?></th><th class="ct-translations-col"><?= __('Translations') ?></th></tr></thead>
    <tbody>
      <?php if (empty($resources)): ?>
        <tr><td colspan="4" class="muted"><?= __('The active theme does not declare any file-backed translatable resources.') ?></td></tr>
      <?php endif; ?>
      <?php foreach ($resources as $resource): ?>
        <tr>
          <td><strong><?= h((string)$resource['label']) ?></strong><br><small class="muted"><?= h((string)$resource['theme_folder']) ?></small></td>
          <td><code><?= h((string)$resource['slot_key']) ?></code></td>
          <td><?= h(implode(', ', array_map(fn(array $field): string => (string)$field['label'], $resource['fields']))) ?></td>
          <td class="ct-translations-col">
            <select class="ct-translation-select" aria-label="<?= h(__('Translations')) ?>" onchange="if(this.value) window.location.href=this.value">
              <option value=""><?= __('Choose language…') ?></option>
              <?php foreach ($locales as $locale): ?>
                <?php $status = $statuses[$resource['id']][$locale] ?? null; ?>
                <?php $label = strtoupper($locale) . ' — ' . ($status === 'draft' ? __('Draft') : ($status === 'published' ? __('Published') : ($status === 'incomplete' ? __('Incomplete') : __('Add')))); ?>
                <?php $url = $themeFileEditUrl . '&theme_folder=' . urlencode((string)$resource['theme_folder']) . '&slot_key=' . urlencode((string)$resource['slot_key']) . '&locale=' . urlencode($locale); ?>
                <option value="<?= h($url) ?>"><?= h($label) ?></option>
              <?php endforeach; ?>
            </select>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php return; endif;

$q = trim((string)($_GET['q'] ?? ''));
$sectionTypes = ['posts' => 'article', 'pages' => 'page', 'themes' => 'theme'];
$typeFilter = $sectionTypes[$section] ?? trim((string)($_GET['type'] ?? ''));
$pageNum = max(1, (int)($_GET['p'] ?? 1));
$perPage = 20;
$offset = ($pageNum - 1) * $perPage;

$where = "p.is_deleted = 0 AND p.type IN ('article','page','theme')";
$params = [];
if ($typeFilter !== '' && in_array($typeFilter, ['article', 'page', 'theme'], true)) {
    $where .= " AND p.type = :ct_type";
    $params[':ct_type'] = $typeFilter;
}
if ($q !== '') {
    $where .= " AND (p.title LIKE :ct_title OR p.slug LIKE :ct_slug)";
    $params[':ct_title'] = '%' . $q . '%';
    $params[':ct_slug'] = '%' . $q . '%';
}
$listPermission = $typeFilter === 'article' ? 'core.posts.read' : ($typeFilter === 'page' ? 'core.pages.read' : 'core.theme_content.read');
$ownerScope = function_exists('authorization_owner_scope_condition')
    ? authorization_owner_scope_condition($pdo, ct_current_user_id(), $listPermission, 'p.created_by', 'ct_list')
    : null;
if ($typeFilter === 'theme' && !ct_user_is_site_owner($pdo)) $ownerScope = null;
$where .= $ownerScope === null ? ' AND 1=0' : ' AND (' . $ownerScope['sql'] . ')';
if ($ownerScope !== null) $params = array_merge($params, $ownerScope['params']);

if ($typeFilter === 'theme') {
    $batchSize = 250;
    $cursorUpdated = '9999-12-31 23:59:59';
    $cursorId = PHP_INT_MAX;
    $total = 0;
    $posts = [];
    $lastMatches = [];
    $contentStmt = $pdo->prepare('SELECT content FROM posts WHERE id = ? LIMIT 1');
    do {
        $listStmt = $pdo->prepare("SELECT p.id, p.type, p.title, p.slug, p.meta, p.status, p.created_by, p.updated_at,
                CASE WHEN LOCATE('widget:theme_section', p.content) > 0 THEN 1 ELSE 0 END AS package_candidate
            FROM posts p WHERE $where AND (p.updated_at < :ct_cursor_before OR (p.updated_at = :ct_cursor_equal AND p.id < :ct_cursor_id))
            ORDER BY p.updated_at DESC, p.id DESC LIMIT $batchSize");
        $listStmt->execute(array_merge($params, [':ct_cursor_before' => $cursorUpdated, ':ct_cursor_equal' => $cursorUpdated, ':ct_cursor_id' => $cursorId]));
        $candidates = $listStmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($candidates as $post) {
            $packageComposed = false;
            if ((int)$post['package_candidate'] === 1) {
                $contentStmt->execute([(int)$post['id']]);
                $packageComposed = ct_parse_theme_section_composition((string)$contentStmt->fetchColumn()) !== null;
            }
            if ($packageComposed) continue;
            unset($post['package_candidate']);
            if ($total >= $offset && count($posts) < $perPage) $posts[] = $post;
            $lastMatches[] = $post;
            if (count($lastMatches) > $perPage) array_shift($lastMatches);
            $total++;
        }
        if ($candidates !== []) {
            $lastCandidate = $candidates[count($candidates) - 1];
            $cursorUpdated = (string)$lastCandidate['updated_at'];
            $cursorId = (int)$lastCandidate['id'];
        }
    } while (count($candidates) === $batchSize);
    $totalPages = max(1, (int)ceil($total / $perPage));
    $boundedPage = min($pageNum, $totalPages);
    if ($boundedPage !== $pageNum) {
        $desiredOffset = ($boundedPage - 1) * $perPage;
        $lastStart = max(0, $total - count($lastMatches));
        $posts = array_slice($lastMatches, max(0, $desiredOffset - $lastStart), $perPage);
        $pageNum = $boundedPage;
    }
} else {
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM posts p WHERE $where");
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();
    $listStmt = $pdo->prepare("SELECT p.id, p.type, p.title, p.slug, p.meta, p.status, p.created_by FROM posts p WHERE $where ORDER BY p.updated_at DESC LIMIT $perPage OFFSET $offset");
    $listStmt->execute($params);
    $posts = $listStmt->fetchAll(PDO::FETCH_ASSOC);
    $totalPages = max(1, (int)ceil($total / $perPage));
}

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
              <?php foreach (ct_content_locales($pdo) as $locale): ?>
                <?php $isSource = $locale === ct_post_source_locale($pdo, $post); ?>
                <?php $status = $isSource ? ct_post_source_status($pdo, $post) : ($statuses[(int)$post['id']][$locale] ?? null); ?>
                <?php $canEditLocale = ct_user_can_edit_post_locale($pdo, $post, $locale); ?>
                <?php $label = strtoupper($locale) . ' — ' . ($canEditLocale ? ($status === 'draft' ? __('Draft') : (($isSource || $status !== null) ? __('Edit') : __('Add'))) : __('View')); ?>
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
          <a href="<?= h($base . '/?page=' . rawurlencode($route) . '&p=' . $i . ($q !== '' ? '&q=' . urlencode($q) : '') . ($typeFilter !== '' ? '&type=' . urlencode($typeFilter) : '')) ?>"><?= $i ?></a>
        <?php endif; ?>
      <?php endfor; ?>
    </div>
  <?php endif; ?>
</div>
