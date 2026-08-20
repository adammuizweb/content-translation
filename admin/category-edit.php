<?php
declare(strict_types=1);

$pdo = $GLOBALS['pdo'] ?? null;
if (!$pdo instanceof PDO) { echo '<p>Database not available.</p>'; return; }
ct_ensure_schema($pdo);
$categoryId = (int)($_GET['category_id'] ?? 0);
$locale = trim((string)($_GET['locale'] ?? ''));
if ($categoryId <= 0 || !in_array($locale, ct_enabled_locales($pdo), true)) { echo '<p>Invalid category or locale.</p>'; return; }
$stmt = $pdo->prepare('SELECT id, name, slug, description, created_by FROM categories WHERE id = ? AND is_deleted = 0 LIMIT 1');
$stmt->execute([$categoryId]);
$category = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$category || !ct_user_can_workspace($pdo)
    || !user_can($pdo, ct_current_user_id(), 'core.categories.update', ['owner_id' => (int)($category['created_by'] ?? 0)])) { echo '<p>Category not found.</p>'; return; }
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['ct_save_category_translation'])) {
    if (!function_exists('csrf_check') || !csrf_check((string)($_POST['csrf_token'] ?? ''))) { http_response_code(419); echo '<p>Invalid CSRF token.</p>'; return; }
    ct_save_category_translation($pdo, $categoryId, $locale, $_POST);
    header('Location: ' . (defined('ADMIN_BASE_PATH') ? ADMIN_BASE_PATH : '/adiwira') . '/?page=admin/tools/content-translation/category-edit&category_id=' . $categoryId . '&locale=' . urlencode($locale));
    exit;
}
$translation = ct_get_category_translation($pdo, $categoryId, $locale) ?? ['name' => '', 'slug' => '', 'description' => ''];
?>
<div class="ct-admin"><div class="ct-header"><div><h2><?= __('Edit Category Translation') ?> — <?= htmlspecialchars(strtoupper($locale), ENT_QUOTES) ?></h2><p class="muted"><?= htmlspecialchars($category['name'], ENT_QUOTES) ?></p></div></div><form method="post" class="ct-panel"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES) ?>"><input type="hidden" name="ct_save_category_translation" value="1"><div class="ct-field"><label><?= __('Name') ?></label><input name="name" value="<?= htmlspecialchars($translation['name'], ENT_QUOTES) ?>" required></div><div class="ct-field"><label><?= __('Slug') ?></label><input name="slug" value="<?= htmlspecialchars($translation['slug'], ENT_QUOTES) ?>" required></div><div class="ct-field"><label><?= __('Description') ?></label><textarea name="description"><?= htmlspecialchars($translation['description'], ENT_QUOTES) ?></textarea></div><button class="btn btn-primary"><?= __('Save Translation') ?></button></form></div>
