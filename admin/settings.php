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

$supported = function_exists('get_supported_locales') ? get_supported_locales() : ['en'];
$defaultLocale = function_exists('content_default_locale') ? content_default_locale() : (function_exists('default_locale') ? default_locale() : 'en');
$enabled = ct_enabled_locales($pdo);

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && !empty($_POST['ct_save_settings'])) {
    if (!function_exists('csrf_check') || !csrf_check((string)($_POST['csrf_token'] ?? ''))) {
        if (function_exists('adiwira_redirect_with_flash')) {
            adiwira_redirect_with_flash($selfUrl, 'error', __('Invalid CSRF token.'));
        }
        return;
    }
    $selected = $_POST['locales'] ?? [];
    ct_set_enabled_locales($pdo, is_array($selected) ? $selected : []);
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

  <form method="post" class="ct-panel ct-settings-form">
    <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="ct_save_settings" value="1">

    <div class="ct-field">
      <label><?= __('Default locale') ?></label>
      <div class="ct-readonly"><strong><?= h(strtoupper($defaultLocale)) ?></strong> <span class="muted">(<?= __('no URL prefix') ?>)</span></div>
    </div>

    <div class="ct-field">
      <label><?= __('Enabled translation locales') ?></label>
      <?php foreach ($supported as $locale): ?>
        <?php if ($locale === $defaultLocale) continue; ?>
        <label class="ct-check">
          <input type="checkbox" name="locales[]" value="<?= h($locale) ?>" <?= in_array($locale, $enabled, true) ? 'checked' : '' ?>>
          <?= h(strtoupper($locale)) ?>
        </label>
      <?php endforeach; ?>
      <?php if (count($supported) < 2): ?>
        <p class="muted"><?= __('Only one locale is supported by this installation.') ?></p>
      <?php endif; ?>
    </div>

    <button type="submit" class="btn btn-primary"><?= __('Save Settings') ?></button>
  </form>

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
