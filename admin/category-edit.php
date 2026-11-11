<?php
declare(strict_types=1);

$pdo = $GLOBALS['pdo'] ?? null;
if (!$pdo instanceof PDO) { echo '<p>Database not available.</p>'; return; }
ct_ensure_schema($pdo);
$base = defined('ADMIN_BASE_PATH') ? ADMIN_BASE_PATH : '/adiwira';
$returnTo = ct_category_translation_return_to(
    $_POST['return_to'] ?? $_GET['return_to'] ?? null,
    $base . '/?page=admin/categories/index',
    $base
);
$categoryId = (int)($_GET['category_id'] ?? 0);
$locale = trim((string)($_GET['locale'] ?? ''));
if ($categoryId <= 0 || !in_array($locale, ct_enabled_locales($pdo), true)) { echo '<p>Invalid category or locale.</p>'; return; }
$stmt = $pdo->prepare('SELECT id, name, slug, description, created_by FROM categories WHERE id = ? AND is_deleted = 0 LIMIT 1');
$stmt->execute([$categoryId]);
$category = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$category || !ct_user_can_workspace($pdo)
    || !user_can($pdo, ct_current_user_id(), 'core.categories.update', ['owner_id' => (int)($category['created_by'] ?? 0)])) { echo '<p>Category not found.</p>'; return; }
$error = is_string($GLOBALS['ct_category_translation_error'] ?? null) ? $GLOBALS['ct_category_translation_error'] : '';
$translation = ct_get_category_translation($pdo, $categoryId, $locale) ?? ['name' => '', 'slug' => '', 'description' => ''];
?>
<div class="ct-admin">
  <div class="ct-header"><div><h2><?= __('Edit Category Translation') ?> - <?= htmlspecialchars(strtoupper($locale), ENT_QUOTES) ?></h2><p class="muted"><?= htmlspecialchars($category['name'], ENT_QUOTES) ?></p></div><a class="btn" href="<?= htmlspecialchars($returnTo, ENT_QUOTES) ?>"><?= __('Back') ?></a></div>
  <?php if ($error !== ''): ?><div class="ct-flash ct-flash-error"><?= htmlspecialchars($error, ENT_QUOTES) ?></div><?php endif; ?>
  <form method="post" class="ct-panel">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES) ?>">
    <input type="hidden" name="ct_save_category_translation" value="1">
    <input type="hidden" name="return_to" value="<?= htmlspecialchars($returnTo, ENT_QUOTES) ?>">
    <div class="ct-field"><label for="ct-category-name"><?= __('Name') ?></label><input id="ct-category-name" name="name" maxlength="255" value="<?= htmlspecialchars((string)($_POST['name'] ?? $translation['name']), ENT_QUOTES) ?>" required></div>
    <div class="ct-field"><label for="ct-category-slug"><?= __('Slug') ?></label><input id="ct-category-slug" name="slug" maxlength="255" value="<?= htmlspecialchars((string)($_POST['slug'] ?? $translation['slug']), ENT_QUOTES) ?>"><small class="muted"><?= __('Leave blank to generate it from the translated name.') ?> <?= __('This slug only needs to be unique among sibling categories in this language. It may match another language.') ?></small></div>
    <div class="ct-field"><label for="ct-category-description"><?= __('Description') ?></label><textarea id="ct-category-description" name="description"><?= htmlspecialchars((string)($_POST['description'] ?? $translation['description']), ENT_QUOTES) ?></textarea></div>
    <button class="btn btn-primary"><?= __('Save Translation') ?></button>
  </form>
</div>
