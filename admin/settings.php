<?php
declare(strict_types=1);

// Content Translation — settings

if (!function_exists('h')) {
    function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
}

$pdo = $GLOBALS['pdo'] ?? null;
if (!$pdo) { echo '<p>Database not available.</p>'; return; }

$base = defined('ADMIN_BASE_PATH') ? ADMIN_BASE_PATH : '/adiwira';
$selfUrl = $base . '/?page=admin/tools/content-translation/settings';
$overviewUrl = $base . '/?page=admin/tools/content-translation';
$exportUrl = $base . '/admin/tools/content-translation/export.php';

$supported = function_exists('get_supported_locales') ? get_supported_locales() : ['en'];
$defaultLocale = function_exists('content_default_locale') ? content_default_locale() : (function_exists('default_locale') ? default_locale() : 'en');
$enabled = ct_enabled_locales($pdo);
$sitemapLocales = ct_sitemap_locales($pdo);

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && !empty($_POST['ct_save_settings'])) {
    if (!function_exists('csrf_check') || !csrf_check((string)($_POST['csrf_token'] ?? ''))) {
        if (function_exists('adiwira_redirect_with_flash')) {
            adiwira_redirect_with_flash($selfUrl, 'error', __('Invalid CSRF token.'));
        }
        return;
    }
    $newLocale = trim((string)($_POST['custom_locale'] ?? '')) ?: trim((string)($_POST['preset_locale'] ?? ''));
    if ($newLocale !== '' && function_exists('register_content_locale')) register_content_locale($pdo, $newLocale);
    $selected = $_POST['locales'] ?? [];
    if ($newLocale !== '') $selected[] = $newLocale;
    ct_set_enabled_locales($pdo, is_array($selected) ? $selected : []);
    ct_set_sitemap_locales($pdo, is_array($_POST['sitemap_locales'] ?? null) ? $_POST['sitemap_locales'] : []);
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
      </section>

      <section class="ct-settings-card ct-settings-card--locales">
        <div class="ct-settings-card__heading">
          <label><?= __('Enabled translation locales') ?></label>
          <span class="muted"><?= __('Choose which locales content can be translated into.') ?></span>
        </div>
        <div class="ct-locale-options">
          <?php foreach ($supported as $locale): ?>
            <?php if ($locale === $defaultLocale) continue; ?>
            <label class="ct-locale-option">
              <input type="checkbox" name="locales[]" value="<?= h($locale) ?>" <?= in_array($locale, $enabled, true) ? 'checked' : '' ?>>
              <span><?= h(strtoupper($locale)) ?></span>
            </label>
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
