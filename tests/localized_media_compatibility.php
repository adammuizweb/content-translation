<?php
declare(strict_types=1);

$registered = [];
function add_filter(string $hook, callable $callback, int $priority = 10): void { $GLOBALS['registered'][] = $hook; }
function add_action(string $hook, callable $callback, int $priority = 10): void { $GLOBALS['registered'][] = $hook; }

require_once dirname(__DIR__) . '/includes/media.php';

$ok = !ct_localized_media_supported() && $registered === [];
echo ($ok ? 'PASS' : 'FAIL') . ' localized media stays inactive when the candidate Core contract is unavailable' . PHP_EOL;
exit($ok ? 0 : 1);
