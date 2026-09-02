<?php
declare(strict_types=1);

$pdo = $GLOBALS['pdo'] ?? null;
if (!$pdo instanceof PDO) { echo '<p>Database not available.</p>'; return; }
ct_ensure_schema($pdo);
$base = defined('ADMIN_BASE_PATH') ? ADMIN_BASE_PATH : '/adiwira';
$returnTo = function_exists('adiwira_safe_return_to')
    ? adiwira_safe_return_to($_POST['return_to'] ?? $_GET['return_to'] ?? null, $base . '/?page=admin/categories/index')
    : $base . '/?page=admin/categories/index';
$categoryId = (int)($_GET['category_id'] ?? 0);
$locale = trim((string)($_GET['locale'] ?? ''));
if ($categoryId <= 0 || !in_array($locale, ct_enabled_locales($pdo), true)) { echo '<p>Invalid category or locale.</p>'; return; }
$stmt = $pdo->prepare('SELECT id, name, slug, description, created_by FROM categories WHERE id = ? AND is_deleted = 0 LIMIT 1');
$stmt->execute([$categoryId]);
$category = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$category || !ct_user_can_workspace($pdo)
    || !user_can($pdo, ct_current_user_id(), 'core.categories.update', ['owner_id' => (int)($category['created_by'] ?? 0)])) { echo '<p>Category not found.</p>'; return; }
$error = '';
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['ct_save_category_translation'])) {
    if (!function_exists('csrf_check') || !csrf_check((string)($_POST['csrf_token'] ?? ''))) { http_response_code(419); echo '<p>Invalid CSRF token.</p>'; return; }
    $input = $_POST;
    $input['name'] = trim((string)($input['name'] ?? ''));
    $input['slug'] = function_exists('slugify') ? slugify((string)($input['slug'] ?? '')) : trim((string)($input['slug'] ?? ''));
    if ($input['name'] === '' || $input['slug'] === '') {
        $error = __('Name and slug are required.');
    } else {
        try {
            $pdo->beginTransaction();
            $actorId = ct_current_user_id();
            if (!authorization_lock_actor_permissions($pdo, $actorId)) throw new DomainException('Category actor permission lock failed.');
            $lockedRows = $pdo->query('SELECT id, parent_id, created_by, is_deleted FROM categories ORDER BY id FOR UPDATE')->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $lockedCategory = null;
            foreach ($lockedRows as $lockedRow) {
                if ((int)$lockedRow['id'] === $categoryId && (int)$lockedRow['is_deleted'] === 0) $lockedCategory = $lockedRow;
            }
            $ownerId = (int)($lockedCategory['created_by'] ?? 0);
            if (!$lockedCategory || !authorization_lock_owner_contexts($pdo, [$ownerId])
                || !ct_user_can_workspace($pdo, $actorId)
                || !user_can($pdo, $actorId, 'core.categories.update', ['owner_id' => $ownerId])) {
                throw new DomainException('Category translation permission changed.');
            }
            $parentId = isset($lockedCategory['parent_id']) ? (int)$lockedCategory['parent_id'] : null;
            if (ct_category_translation_slug_conflict($pdo, $categoryId, $locale, (string)$input['slug'], $parentId)) {
                throw new InvalidArgumentException(__('Slug already taken by a sibling category.'));
            }
            if (!ct_save_category_translation($pdo, $categoryId, $locale, $input)) {
                throw new RuntimeException('Category translation could not be saved.');
            }
            $pdo->commit();
            header('Location: ' . $base . '/?' . http_build_query(['page' => 'admin/tools/content-translation/category-edit', 'category_id' => $categoryId, 'locale' => $locale, 'return_to' => $returnTo]));
            exit;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $error = $e instanceof InvalidArgumentException ? $e->getMessage() : __('Category translation could not be saved.');
            error_log('content-translation/category-edit.php error: ' . $e->getMessage());
        }
    }
}
$translation = ct_get_category_translation($pdo, $categoryId, $locale) ?? ['name' => '', 'slug' => '', 'description' => ''];
?>
<div class="ct-admin"><div class="ct-header"><div><h2><?= __('Edit Category Translation') ?> - <?= htmlspecialchars(strtoupper($locale), ENT_QUOTES) ?></h2><p class="muted"><?= htmlspecialchars($category['name'], ENT_QUOTES) ?></p></div><a class="btn" href="<?= htmlspecialchars($returnTo, ENT_QUOTES) ?>"><?= __('Back') ?></a></div><?php if ($error !== ''): ?><div class="ct-flash ct-flash-error"><?= htmlspecialchars($error, ENT_QUOTES) ?></div><?php endif; ?><form method="post" class="ct-panel"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES) ?>"><input type="hidden" name="ct_save_category_translation" value="1"><input type="hidden" name="return_to" value="<?= htmlspecialchars($returnTo, ENT_QUOTES) ?>"><div class="ct-field"><label><?= __('Name') ?></label><input name="name" value="<?= htmlspecialchars((string)($_POST['name'] ?? $translation['name']), ENT_QUOTES) ?>" required></div><div class="ct-field"><label><?= __('Slug') ?></label><input name="slug" value="<?= htmlspecialchars((string)($_POST['slug'] ?? $translation['slug']), ENT_QUOTES) ?>" required></div><div class="ct-field"><label><?= __('Description') ?></label><textarea name="description"><?= htmlspecialchars((string)($_POST['description'] ?? $translation['description']), ENT_QUOTES) ?></textarea></div><button class="btn btn-primary"><?= __('Save Translation') ?></button></form></div>
