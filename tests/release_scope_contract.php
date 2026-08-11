<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$runtimeFiles = [
    $root . '/plugin.php',
    ...glob($root . '/includes/*.php') ?: [],
    ...glob($root . '/admin/*.php') ?: [],
    ...glob($root . '/admin/api/*.php') ?: [],
];
$runtime = '';
foreach ($runtimeFiles as $file) $runtime .= (string)file_get_contents($file);

$clean = !is_file($root . '/includes/directory-pages.php')
    && !str_contains($runtime, 'directory_page')
    && !str_contains($runtime, 'directory-pages');

echo ($clean ? 'PASS' : 'FAIL') . ' unsupported directory-page runtime is absent' . PHP_EOL;
exit($clean ? 0 : 1);
