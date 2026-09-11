<?php
declare(strict_types=1);

$filters = [];
$actions = [];
function add_filter(string $hook, callable $callback, int $priority = 10, int $acceptedArgs = 1): void {}
function add_action(string $hook, callable $callback, int $priority = 10, int $acceptedArgs = 1): void {}

$root = dirname(__DIR__);
require $root . '/includes/shortcode-presets.php';

$catalog = ct_shortcode_preset_ui_translations();
$required = [
    'Localized collection paths',
    'Set the Post and Page list paths used after each language prefix.',
    'Post list path',
    'Page list path',
    'Localized collection paths are invalid.',
    'Collection paths may only contain lowercase letters, numbers, slashes, underscores, and hyphens.',
    'Post and Page list paths must be different in each language.',
    'Shortcodes',
    'Translate Shortcode Preset management titles and localized kicker text without changing query or layout configuration.',
    'Manage Shortcode Presets',
    'Localized media',
    'Original metadata language',
    'Availability',
    'All locales',
    'Selected locales',
    'All locales keeps every language selected. Choose Selected locales to customize availability.',
    'Metadata language',
    'The fields below show the selected language. Save before editing another translation.',
    'Use translated title',
    'Use translated caption',
    'Use translated credit',
    'Caption',
    'Credit',
    'Alt text mode',
    'Inherit original',
    'Translated text',
    'Decorative (empty alt)',
    'Translation status',
    'Delete selected translation',
    'This affects only the selected translation; original metadata is retained.',
    'This media translation will be deleted when you save.',
    'Localized featured media',
    'Source thumbnail',
    'YouTube remains the first display-image source. This selection is used when no valid YouTube thumbnail is present.',
    'Inherit source thumbnail',
    'Choose media for this locale',
    'No thumbnail',
    'Open media picker',
    'This media is unavailable, private, or deleted for the selected locale. It may remain in a draft but cannot be published.',
    'Override alt text at this use site',
    'Override caption at this use site',
    'This media is unavailable, private, or deleted for the selected locale. Save as draft or choose compatible media.',
    'Content Translation cannot be disabled or deleted while localized media state exists.',
    'Content Translation state could not be verified.',
    'Content default language cannot change while localized media state exists.',
    'Content default language cannot change because localized media state could not be verified.',
    'Invalid localized featured media mode.',
    'Choose media for the localized thumbnail.',
    'Localized media overrides are too long.',
    'Published translations require available public media.',
    'Choose a valid source metadata language.',
    'Selected availability requires at least one locale.',
    'Localized media profile is unavailable.',
    'This media profile was changed by another editor. Reload before saving.',
    'Choose a valid alternate metadata language.',
    'Invalid media translation state.',
    'Invalid media translation operation.',
    'This media translation was changed by another editor. Reload before saving.',
    'Translated alt text cannot be empty; use decorative for an intentional empty alt.',
    'The new source metadata language already has a media translation. Delete that translation first.',
];
$files = [
    $root . '/includes/shortcode-presets.php',
    $root . '/admin/shortcodes.php',
    $root . '/admin/shortcode-edit.php',
    $root . '/admin/api/shortcode-save.php',
];
foreach ($files as $file) {
    $contents = (string)file_get_contents($file);
    $tokens = token_get_all($contents);
    $count = count($tokens);
    for ($index = 0; $index < $count; $index++) {
        $token = $tokens[$index];
        if (!is_array($token) || $token[0] !== T_STRING || $token[1] !== '__') continue;
        for ($next = $index + 1; $next < $count; $next++) {
            $candidate = $tokens[$next];
            if (is_array($candidate) && in_array($candidate[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) continue;
            if ($candidate === '(') continue;
            if (is_array($candidate) && $candidate[0] === T_CONSTANT_ENCAPSED_STRING) {
                $literal = $candidate[1];
                $body = substr($literal, 1, -1);
                $required[] = $literal[0] === "'"
                    ? str_replace(["\\\\", "\\'"], ["\\", "'"], $body)
                    : stripcslashes($body);
            }
            break;
        }
    }
    if (str_ends_with($file, '/includes/shortcode-presets.php')) {
        preg_match_all("/(?:InvalidArgumentException|RuntimeException)\\('([^']+)'/", $contents, $matches);
        array_push($required, ...$matches[1]);
    }
}

$failures = [];
foreach (array_values(array_unique($required)) as $source) {
    $values = $catalog[$source] ?? null;
    $ok = is_array($values)
        && count($values) === 2
        && is_string($values[0]) && trim($values[0]) !== ''
        && is_string($values[1]) && trim($values[1]) !== '';
    echo ($ok ? 'PASS' : 'FAIL') . ' Indonesian/German seed coverage: ' . $source . PHP_EOL;
    if (!$ok) $failures[] = $source;
}

$source = (string)file_get_contents($root . '/includes/shortcode-presets.php');
$uninstall = (string)file_get_contents($root . '/plugin.php');
$contracts = [
    'seed catalog is hash-versioned rather than release-version gated' => str_contains($source, "hash('sha256', json_encode(\$translations"),
    'owned values update only from their prior plugin value' => str_contains($source, 'AND value = ?'),
    'pre-existing unowned rows are not claimed' => str_contains($source, '$inserted || $previous !== false'),
    'obsolete owned UI values are deleted only when unchanged' => str_contains($source, 'DELETE FROM ui_translations WHERE scope = ? AND source = ? AND locale = ? AND value = ?'),
    'obsolete ownership rows are pruned' => str_contains($source, 'DELETE FROM ct_ui_translation_seeds WHERE scope = ? AND source_hash = ? AND locale = ?'),
    'uninstall matches ownership and current seeded value' => str_contains($uninstall, 'owned.value = ui.value'),
];
foreach ($contracts as $message => $ok) {
    echo ($ok ? 'PASS' : 'FAIL') . ' ' . $message . PHP_EOL;
    if (!$ok) $failures[] = $message;
}

if ($failures !== []) {
    fwrite(STDERR, count($failures) . " translation coverage assertion(s) failed.\n");
    exit(1);
}
echo "RESULT: ALL PASS\n";
