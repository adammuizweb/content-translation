<?php
declare(strict_types=1);

// Content Translation — settings

if (!function_exists('h')) {
    function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
}

$pdo = $GLOBALS['pdo'] ?? null;
if (!$pdo) { echo '<p>Database not available.</p>'; return; }
if (!ct_user_can_workspace($pdo) || !user_can($pdo, ct_current_user_id(), 'core.settings.manage')) { http_response_code(404); return; }

$base = defined('ADMIN_BASE_PATH') ? ADMIN_BASE_PATH : '/adiwira';
$selfUrl = $base . '/?page=admin/tools/content-translation/settings';
$overviewUrl = $base . '/?page=admin/tools/content-translation';
$exportUrl = $base . '/?page=admin/tools/content-translation/export';

$supported = function_exists('get_supported_locales') ? get_supported_locales() : ['en'];
$defaultLocale = function_exists('content_default_locale') ? content_default_locale() : (function_exists('default_locale') ? default_locale() : 'en');
$enabled = ct_enabled_locales($pdo);
$directions = ct_locale_directions($pdo);
$sitemapLocales = ct_sitemap_locales($pdo);
$authorPreferences = ct_author_locale_preferences($pdo);
$userLocaleGrants = ct_user_locale_edit_grants($pdo);
$roleLocaleGrants = ct_role_locale_edit_grants($pdo);
$authorRows = $pdo->query("SELECT u.id, u.name, u.username, u.email,
        (SELECT GROUP_CONCAT(r.name ORDER BY r.authority_rank DESC, r.name ASC SEPARATOR ', ')
         FROM user_roles ur INNER JOIN roles r ON r.id = ur.role_id
         WHERE ur.user_id = u.id AND (ur.expires_at IS NULL OR ur.expires_at > NOW())) AS role_names
    FROM users u
    WHERE u.is_deleted = 0 AND u.is_locked = 0
    ORDER BY COALESCE(NULLIF(u.name, ''), NULLIF(u.username, ''), u.email) ASC")->fetchAll(PDO::FETCH_ASSOC);
$grantUsers = array_values(array_filter($authorRows, static fn(array $user): bool =>
    ct_user_can_workspace($pdo, (int)$user['id'])
));
$authors = array_values(array_filter($grantUsers, static fn(array $user): bool =>
    user_can($pdo, (int)$user['id'], 'core.posts.create')
        || user_can($pdo, (int)$user['id'], 'core.pages.create')
        || user_can($pdo, (int)$user['id'], 'core.theme_content.create')
));
$roles = $pdo->query('SELECT id, name, slug FROM roles ORDER BY authority_rank DESC, name ASC')->fetchAll(PDO::FETCH_ASSOC);

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && !empty($_POST['ct_save_settings'])) {
    if (!function_exists('csrf_check') || !csrf_check((string)($_POST['csrf_token'] ?? ''))) {
        if (function_exists('adiwira_redirect_with_flash')) {
            adiwira_redirect_with_flash($selfUrl, 'error', __('Invalid CSRF token.'));
        }
        return;
    }
    $newLocale = trim((string)($_POST['custom_locale'] ?? '')) ?: trim((string)($_POST['preset_locale'] ?? ''));
    if ($newLocale !== '' && preg_match('/\A[a-z]{2,3}(?:-[A-Za-z0-9]{2,8})?\z/', $newLocale) !== 1) {
        if (function_exists('adiwira_redirect_with_flash')) adiwira_redirect_with_flash($selfUrl, 'error', __('Invalid locale code.'));
        return;
    }
    $selected = is_array($_POST['locales'] ?? null) ? $_POST['locales'] : [];
    if ($newLocale !== '') $selected[] = $newLocale;
    $selected = array_values(array_unique(array_map('strval', $selected)));
    $requiredSourceLocales = array_values(array_diff(ct_post_authoring_locales_in_use($pdo), $selected));
    if ($requiredSourceLocales !== []) {
        if (function_exists('adiwira_redirect_with_flash')) {
            adiwira_redirect_with_flash(
                $selfUrl,
                'error',
                sprintf(__('Cannot disable authored source locales: %s.'), strtoupper(implode(', ', $requiredSourceLocales)))
            );
        }
        return;
    }
    $ownsTransaction = !$pdo->inTransaction();
    try {
        if ($ownsTransaction) $pdo->beginTransaction();
        $actorId = ct_current_user_id();
        if (!function_exists('authorization_lock_actor_permissions')
            || !authorization_lock_actor_permissions($pdo, $actorId)
            || !ct_user_can_workspace($pdo, $actorId)
            || !user_can($pdo, $actorId, 'core.settings.manage')) {
            throw new RuntimeException('Settings authorization changed.');
        }
        if ($newLocale !== '' && function_exists('register_content_locale')) register_content_locale($pdo, $newLocale);
        if (!ct_set_enabled_locales($pdo, $selected)) throw new RuntimeException('Locale settings could not be saved.');
        $newDirection = ($_POST['new_locale_direction'] ?? '') === 'rtl' ? 'rtl' : 'ltr';
        $submittedDirections = is_array($_POST['locale_directions'] ?? null) ? $_POST['locale_directions'] : [];
        if ($newLocale !== '') $submittedDirections[$newLocale] = $newDirection;
        if (!ct_set_locale_directions($pdo, $submittedDirections)
            || !ct_set_sitemap_locales($pdo, is_array($_POST['sitemap_locales'] ?? null) ? $_POST['sitemap_locales'] : [])) {
            throw new RuntimeException('Locale settings could not be saved.');
        }
        ct_replace_locale_edit_grants(
            $pdo,
            is_array($_POST['user_locale_grants'] ?? null) ? $_POST['user_locale_grants'] : [],
            is_array($_POST['role_locale_grants'] ?? null) ? $_POST['role_locale_grants'] : []
        );
        if (!ct_set_author_locale_preferences($pdo, is_array($_POST['author_locales'] ?? null) ? $_POST['author_locales'] : [])) {
            throw new RuntimeException('Author preferences could not be saved.');
        }
        if ($ownsTransaction) $pdo->commit();
    } catch (Throwable $error) {
        if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack();
        error_log('[content-translation] settings save error: ' . $error->getMessage());
        if (function_exists('adiwira_redirect_with_flash')) adiwira_redirect_with_flash($selfUrl, 'error', __('Settings could not be saved.'));
        return;
    }
    if (function_exists('adiwira_redirect_with_flash')) {
        adiwira_redirect_with_flash($selfUrl, 'success', __('Settings saved.'));
    }
    return;
}

$flash = $_GET['flash'] ?? '';
$flashType = $_GET['flash_type'] ?? 'success';
?>

<div class="ct-admin">
  <div class="ct-header">
    <div>
      <h2><?= __('Translation Settings') ?></h2>
      <p class="muted"><?= __('Choose which locales content can be translated into.') ?></p>
    </div>
    <a class="btn" href="<?= h($overviewUrl) ?>"><?= __('Back') ?></a>
  </div>

  <?php if ($flash): ?>
    <div class="ct-flash ct-flash-<?= h($flashType) ?>"><?= h($flash) ?></div>
  <?php endif; ?>

  <form method="post" class="ct-settings-form">
    <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="ct_save_settings" value="1">

    <div class="ct-settings-grid">
      <section class="ct-settings-card ct-settings-card--default">
        <label><?= __('Default locale') ?></label>
        <div class="ct-default-locale">
          <strong><?= h(strtoupper($defaultLocale)) ?></strong>
          <span><?= __('no URL prefix') ?></span>
        </div>
        <div class="ct-direction-toggle" role="group" aria-label="<?= h(__('Text direction')) ?>">
          <span><?= __('Text direction') ?></span>
          <label><input type="radio" name="locale_directions[<?= h($defaultLocale) ?>]" value="ltr" <?= ($directions[$defaultLocale] ?? ct_default_locale_direction($defaultLocale)) === 'ltr' ? 'checked' : '' ?>><b>LTR</b></label>
          <label><input type="radio" name="locale_directions[<?= h($defaultLocale) ?>]" value="rtl" <?= ($directions[$defaultLocale] ?? ct_default_locale_direction($defaultLocale)) === 'rtl' ? 'checked' : '' ?>><b>RTL</b></label>
        </div>
      </section>

      <section class="ct-settings-card ct-settings-card--locales">
        <div class="ct-settings-card__heading">
          <label><?= __('Enabled translation locales') ?></label>
          <span class="muted"><?= __('Choose which locales content can be translated into.') ?></span>
        </div>
        <div class="ct-locale-options">
          <?php foreach ($supported as $locale): ?>
            <?php if ($locale === $defaultLocale) continue; ?>
            <div class="ct-locale-row">
              <label class="ct-locale-option">
                <input type="checkbox" name="locales[]" value="<?= h($locale) ?>" <?= in_array($locale, $enabled, true) ? 'checked' : '' ?>>
                <span><?= h(strtoupper($locale)) ?></span>
              </label>
              <div class="ct-direction-toggle" role="group" aria-label="<?= h(__('Text direction')) ?>">
                <label><input type="radio" name="locale_directions[<?= h($locale) ?>]" value="ltr" <?= ($directions[$locale] ?? ct_default_locale_direction($locale)) === 'ltr' ? 'checked' : '' ?>><b>LTR</b></label>
                <label><input type="radio" name="locale_directions[<?= h($locale) ?>]" value="rtl" <?= ($directions[$locale] ?? ct_default_locale_direction($locale)) === 'rtl' ? 'checked' : '' ?>><b>RTL</b></label>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
        <?php if (count($supported) < 2): ?>
          <p class="muted"><?= __('Only one locale is supported by this installation.') ?></p>
        <?php endif; ?>
      </section>
    </div>

    <section class="ct-settings-card ct-settings-card--add">
      <div class="ct-settings-card__heading">
        <label><?= __('Add language') ?></label>
        <span class="muted"><?= __('Added languages become available for translation after saving.') ?></span>
      </div>
      <div class="ct-add-language-controls">
        <select name="preset_locale"><option value=""><?= __('Choose a popular language') ?></option><?php foreach (function_exists('content_locale_presets') ? content_locale_presets() : [] as $code => $label): ?><?php if (!in_array($code, $supported, true)): ?><option value="<?= h($code) ?>"><?= h($label . ' (' . $code . ')') ?></option><?php endif; ?><?php endforeach; ?></select>
        <span class="ct-add-language-or"><?= __('or') ?></span>
        <input name="custom_locale" placeholder="Custom code, e.g. pt-BR" pattern="[a-z]{2,3}(-[A-Za-z0-9]{2,8})?">
        <label class="ct-new-direction">
          <span><?= __('Text direction') ?></span>
          <select name="new_locale_direction">
            <option value="ltr"><?= __('Left to right') ?></option>
            <option value="rtl"><?= __('Right to left') ?></option>
          </select>
        </label>
      </div>
    </section>

    <section class="ct-settings-card ct-settings-card--sitemap">
      <div class="ct-settings-card__heading">
        <label><?= __('Include published translations in sitemap') ?></label>
        <span class="muted"><?= __('Each selected locale receives separate posts and pages sitemap files.') ?></span>
      </div>
      <div class="ct-locale-options ct-locale-options--sitemap">
        <?php foreach ($enabled as $locale): ?>
          <label class="ct-locale-option">
            <input type="checkbox" name="sitemap_locales[]" value="<?= h($locale) ?>" <?= in_array($locale, $sitemapLocales, true) ? 'checked' : '' ?>>
            <span><?= h(strtoupper($locale)) ?></span>
          </label>
        <?php endforeach; ?>
      </div>
    </section>

    <section class="ct-settings-card ct-settings-card--authors">
      <div class="ct-settings-card__heading">
        <label><?= __('Direct user locale edit grants') ?></label>
        <span class="muted"><?= __('These grants authorize editing and are independent from the default writing language.') ?></span>
      </div>
      <div class="ct-author-language-list">
        <?php foreach ($grantUsers as $author): $authorId = (int)$author['id']; ?>
          <div class="ct-author-language-row">
            <span class="ct-author-language-identity"><strong><?= h(trim((string)($author['name'] ?? '')) ?: (string)$author['email']) ?></strong></span>
            <span><?php foreach (ct_content_locales($pdo) as $locale): ?><label><input type="checkbox" name="user_locale_grants[<?= $authorId ?>][]" value="<?= h($locale) ?>" <?= in_array($locale, $userLocaleGrants[$authorId] ?? [], true) ? 'checked' : '' ?>> <?= h(strtoupper($locale)) ?></label> <?php endforeach; ?></span>
          </div>
        <?php endforeach; ?>
      </div>
    </section>

    <section class="ct-settings-card ct-settings-card--authors">
      <div class="ct-settings-card__heading">
        <label><?= __('Role locale edit grants') ?></label>
        <span class="muted"><?= __('A role grant applies only while the user has an active assignment to that role.') ?></span>
      </div>
      <div class="ct-author-language-list">
        <?php foreach ($roles as $role): $roleId = (int)$role['id']; ?>
          <div class="ct-author-language-row">
            <span class="ct-author-language-identity"><strong><?= h((string)$role['name']) ?></strong><small><?= h((string)$role['slug']) ?></small></span>
            <span><?php foreach (ct_content_locales($pdo) as $locale): ?><label><input type="checkbox" name="role_locale_grants[<?= $roleId ?>][]" value="<?= h($locale) ?>" <?= in_array($locale, $roleLocaleGrants[$roleId] ?? [], true) ? 'checked' : '' ?>> <?= h(strtoupper($locale)) ?></label> <?php endforeach; ?></span>
          </div>
        <?php endforeach; ?>
      </div>
    </section>

    <section class="ct-settings-card ct-settings-card--authors">
      <div class="ct-settings-card__heading">
        <label><?= __('Default writing language by author') ?></label>
        <span class="muted"><?= __('Authors assigned to a translation language can use the standard Add Post and Add Page screens. Their content is automatically stored for that locale while the site default language stays unchanged.') ?></span>
      </div>
      <?php if ($authors === []): ?>
        <p class="muted"><?= __('No active users can create translatable content.') ?></p>
      <?php else: ?>
        <div class="ct-author-language-list">
          <?php foreach ($authors as $author): ?>
            <?php
              $authorId = (int)$author['id'];
              $authorLabel = trim((string)($author['name'] ?? '')) ?: (trim((string)($author['username'] ?? '')) ?: (string)$author['email']);
              $selectedLocale = $authorPreferences[$authorId] ?? '';
            ?>
            <label class="ct-author-language-row">
              <span class="ct-author-language-identity">
                <strong><?= h($authorLabel) ?></strong>
                <small><?= h((string)($author['role_names'] ?? '')) ?></small>
              </span>
              <select name="author_locales[<?= $authorId ?>]">
                <option value=""><?= h(__('Site default') . ' (' . strtoupper($defaultLocale) . ')') ?></option>
                <?php foreach ($enabled as $locale): ?>
                  <?php if (!ct_user_has_locale_edit_grant($pdo, $authorId, $locale)) continue; ?>
                  <option value="<?= h($locale) ?>" <?= $selectedLocale === $locale ? 'selected' : '' ?>><?= h(strtoupper($locale)) ?></option>
                <?php endforeach; ?>
              </select>
            </label>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </section>

    <div class="ct-settings-actions">
      <button type="submit" class="btn btn-primary"><?= __('Save Settings') ?></button>
    </div>
  </form>

  <section class="ct-panel" style="margin-top:1.25rem">
    <h3><?= __('Backup') ?></h3>
    <p class="muted"><?= __('Download all translation data and plugin locale settings as a JSON backup before removing the plugin or making major changes.') ?></p>
    <form method="post" action="<?= h($exportUrl) ?>">
      <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
      <button type="submit" class="btn"><?= __('Export backup') ?></button>
    </form>
  </section>

  <section class="ct-panel" style="margin-top:1.25rem">
    <h3><?= __('Content Translation') ?></h3>
    <p class="muted"><?= __('Use this shortcode in a Theme Customize HTML gadget or a Sidebar HTML/widget area. It automatically shows only published languages for the current post, theme, or category.') ?></p>
    <div class="ct-field"><label><?= __('Shortcode') ?></label><pre class="ct-readonly ct-source-code">[[widget:lang_switcher style="pills"]]</pre></div>
    <div class="ct-field"><label><?= __('Dropdown shortcode') ?></label><pre class="ct-readonly ct-source-code">[[widget:lang_switcher style="select"]]</pre></div>
    <div class="ct-field"><label><?= __('Raw HTML example') ?></label><pre class="ct-readonly ct-source-code">&lt;div class="my-language-switcher"&gt;
  [[widget:lang_switcher style="pills"]]
&lt;/div&gt;</pre></div>
    <p class="muted"><?= __('Do not add it as a regular Menu link: menu URLs are static. Use an HTML gadget in the menu area instead.') ?></p>
  </section>
</div>
