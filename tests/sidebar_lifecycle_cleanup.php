<?php
declare(strict_types=1);

require '/var/www/jyavani.lan/cfg/helpers/hooks.php';
require '/var/www/jyavani.lan/cfg/helpers/resource_lifecycle.php';
require dirname(__DIR__) . '/includes/sidebar-lifecycle.php';

$failures = [];
$check = static function (bool $condition, string $message) use (&$failures): void {
    echo ($condition ? 'PASS' : 'FAIL') . ' ' . $message . PHP_EOL;
    if (!$condition) $failures[] = $message;
};
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE sidebar_item_translations (id INTEGER PRIMARY KEY, sidebar_item_id INTEGER, locale TEXT)');
$pdo->exec("INSERT INTO sidebar_item_translations VALUES (1,10,'de'),(2,11,'de'),(3,12,'de')");
$pdo->beginTransaction();
$event = resource_lifecycle_event([
    'schema' => 1,
    'event_id' => str_repeat('a', 64),
    'occurred_at' => '2026-10-01T00:00:00Z',
    'resource' => 'sidebar_item',
    'operation' => 'delete',
    'bulk' => true,
    'actor_id' => 1,
    'source' => 'core.sidebar_manager',
    'items' => [
        ['id' => 10, 'before' => ['id' => 10], 'after' => null, 'artifacts' => []],
        ['id' => 11, 'before' => ['id' => 11], 'after' => null, 'artifacts' => []],
    ],
    'metadata' => [], 'result' => [], 'warnings' => [],
]);
do_action('resource_lifecycle_before_mutation', $event, new ResourceLifecycleDatabase($pdo));
$check((int)$pdo->query('SELECT COUNT(*) FROM sidebar_item_translations')->fetchColumn() === 1, 'sidebar lifecycle cleanup deletes every event item in the caller transaction');
$pdo->rollBack();
$check((int)$pdo->query('SELECT COUNT(*) FROM sidebar_item_translations')->fetchColumn() === 3, 'sidebar cleanup remains atomic with Core rollback');

$pdo->exec('DROP TABLE sidebar_item_translations');
$pdo->beginTransaction();
$failed = false;
try { do_action('resource_lifecycle_before_mutation', $event, new ResourceLifecycleDatabase($pdo)); } catch (PDOException $error) { $failed = true; }
$check($failed && $pdo->inTransaction(), 'sidebar cleanup failure propagates without changing transaction ownership');
$pdo->rollBack();

if ($failures !== []) exit(1);
echo "RESULT: ALL PASS\n";
