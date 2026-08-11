<?php
declare(strict_types=1);

final class CtSchemaStatement extends PDOStatement
{
    public function __construct(private array $rows = []) {}
    public function execute(?array $params = null): bool { return true; }
    public function fetchColumn(int $column = 0): mixed
    {
        $row = array_shift($this->rows);
        return is_array($row) ? (array_values($row)[$column] ?? false) : false;
    }
    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array { return $this->rows; }
}

final class CtSchemaPdo extends PDO
{
    public int $execCalls = 0;
    public bool $failNextExec = false;
    public bool $failTranslationQuery = false;

    public function __construct() {}

    public function exec(string $statement): int|false
    {
        $this->execCalls++;
        if ($this->failNextExec) {
            $this->failNextExec = false;
            throw new PDOException('simulated schema failure');
        }
        return 0;
    }
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        if ($this->failTranslationQuery && str_contains($query, 'FROM shortcode_preset_translations')) {
            throw new PDOException('simulated optional query failure');
        }
        if (str_contains($query, 'CHARACTER_MAXIMUM_LENGTH')) return new CtSchemaStatement([['length' => 16]]);
        if (str_contains($query, 'COUNT(*) FROM information_schema.COLUMNS')) return new CtSchemaStatement([['count' => 1]]);
        return new CtSchemaStatement();
    }
}

require dirname(__DIR__) . '/includes/helpers.php';

$filters = [];
$actions = [];
function add_filter(string $hook, callable $callback, int $priority = 10, int $acceptedArgs = 1): void
{
    global $filters;
    $filters[$hook][$priority][] = [$callback, $acceptedArgs];
}
function add_action(string $hook, callable $callback, int $priority = 10, int $acceptedArgs = 1): void
{
    global $actions;
    $actions[$hook][$priority][] = [$callback, $acceptedArgs];
}
function __(string $message): string { return $message; }

require dirname(__DIR__) . '/includes/shortcode-presets.php';

$failures = [];
$check = static function (bool $condition, string $message) use (&$failures): void {
    echo ($condition ? 'PASS' : 'FAIL') . ' ' . $message . PHP_EOL;
    if (!$condition) $failures[] = $message;
};

$retry = new CtSchemaPdo();
$retry->failNextExec = true;
$first = ct_ensure_schema($retry);
$callsAfterFailure = $retry->execCalls;
$second = ct_ensure_schema($retry);
$check(!$first && $second && $retry->execCalls > $callsAfterFailure, 'schema completion is recorded only after a successful retry');

$degraded = new CtSchemaPdo();
$check(ct_ensure_schema($degraded), 'optional schema is available before query degradation test');
$degraded->failTranslationQuery = true;
$runtime = $filters['shortcode_preset_runtime_config'][20][0][0] ?? null;
$GLOBALS['ct_request_locale'] = 'de';
$source = ['layout' => 'cards', 'kicker' => 'Source'];
$result = $runtime($source, ['id' => 7], $degraded, ['route' => '/de/']);
unset($GLOBALS['ct_request_locale']);
$check($result === $source, 'optional translation query failure degrades to source runtime behavior');
$check(ct_schema_error($degraded) !== null, 'optional query failure remains available for a clear admin error');

if ($failures !== []) {
    fwrite(STDERR, count($failures) . " assertion(s) failed.\n");
    exit(1);
}
echo "RESULT: ALL PASS\n";
