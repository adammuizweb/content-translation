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
    'Shortcodes',
    'Translate Shortcode Preset management titles and localized kicker text without changing query or layout configuration.',
    'Manage Shortcode Presets',
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
