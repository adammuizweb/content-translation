<?php
declare(strict_types=1);

$sandbox = sys_get_temp_dir() . '/ct-theme-file-path-' . bin2hex(random_bytes(8));
$themes = $sandbox . '/themes';
$outside = $sandbox . '/outside';
mkdir($themes, 0700, true);
mkdir($themes . '/safe-theme', 0700);
mkdir($outside, 0700);
symlink($outside, $themes . '/linked-theme');
define('VIEWS_BASE', $themes);

$customizerCalls = [];
$runtimeTemplate = ['type' => 'theme_file', 'theme_folder' => 'safe-theme', 'theme_file' => 'main/homepage.php'];
function get_active_theme_folder(PDO $pdo): string { return 'safe-theme'; }
function resolve_template(PDO $pdo, string $slot): array {
    global $runtimeTemplate;
    return $runtimeTemplate;
}
function theme_customizer_fields(string $folder): array {
    global $customizerCalls;
    $customizerCalls[] = $folder;
    return [
        'hero' => [
            'label' => 'Hero',
            'slot' => 'main.homepage',
            'fields' => [
                'title' => ['type' => 'text', 'label' => 'Title', 'translatable' => true],
            ],
        ],
    ];
}

require_once dirname(__DIR__) . '/includes/helpers.php';

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE themes (folder_name TEXT PRIMARY KEY)');
$insert = $pdo->prepare('INSERT INTO themes (folder_name) VALUES (?)');
$insert->execute(['safe-theme']);
$insert->execute(['linked-theme']);

$failures = [];
$check = static function (bool $condition, string $message) use (&$failures): void {
    echo ($condition ? 'PASS' : 'FAIL') . ' ' . $message . PHP_EOL;
    if (!$condition) $failures[] = $message;
};

try {
    $safeRoot = ct_theme_root($pdo, 'safe-theme');
    $check($safeRoot === realpath($themes . '/safe-theme'), 'registered direct-child theme resolves to its canonical root');
    $resources = ct_theme_file_resources($pdo, 'safe-theme');
    $check(count($resources) === 1, 'registered direct-child theme exposes declared Theme File resources');
    $resource = reset($resources);
    $check(ct_theme_file_runtime_state($pdo, $resource)['active'] === true,
        'runtime state identifies the exact physical theme file that controls the slot');
    $runtimeTemplate = ['type' => 'custom_post', 'post' => ['id' => 42, 'title' => 'Homepage Template']];
    $runtime = ct_theme_file_runtime_state($pdo, $resource);
    $check($runtime['active'] === false && $runtime['type'] === 'theme_template'
        && $runtime['post_id'] === 42 && $runtime['label'] === 'Homepage Template',
        'runtime state identifies a Theme Template that shadows stored Customizer translations');

    $customizerCalls = [];
    $check(ct_theme_file_resources($pdo, 'missing-theme') === [] && $customizerCalls === [],
        'unregistered theme is rejected before its manifest is read');
    $check(ct_theme_file_resources($pdo, '../outside') === [] && $customizerCalls === [],
        'traversal folder is rejected before its manifest is read');
    $check(ct_theme_file_resources($pdo, 'linked-theme') === [] && $customizerCalls === [],
        'registered symlink theme is rejected before its manifest is read');
} finally {
    unlink($themes . '/linked-theme');
    rmdir($themes . '/safe-theme');
    rmdir($themes);
    rmdir($outside);
    rmdir($sandbox);
}

if ($failures !== []) {
    fwrite(STDERR, count($failures) . " assertion(s) failed.\n");
    exit(1);
}
echo "RESULT: ALL PASS\n";
