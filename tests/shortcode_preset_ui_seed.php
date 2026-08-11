<?php
declare(strict_types=1);

final class CtSeedStatement extends PDOStatement
{
    private array $rows = [];
    private int $affected = 0;

    public function __construct(private CtSeedPdo $pdo, private string $query) {}

    public function execute(?array $params = null): bool
    {
        [$this->rows, $this->affected] = $this->pdo->run($this->query, $params ?? []);
        return true;
    }

    public function fetchColumn(int $column = 0): mixed
    {
        $row = array_shift($this->rows);
        return is_array($row) ? (array_values($row)[$column] ?? false) : false;
    }

    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array { return $this->rows; }
    public function rowCount(): int { return $this->affected; }
}

final class CtSeedPdo extends PDO
{
    public array $ui = [];
    public array $owned = [];
    private bool $transaction = false;

    public function __construct() {}
    public function prepare(string $query, array $options = []): PDOStatement|false { return new CtSeedStatement($this, $query); }
    public function beginTransaction(): bool { $this->transaction = true; return true; }
    public function inTransaction(): bool { return $this->transaction; }
    public function commit(): bool { $this->transaction = false; return true; }
    public function rollBack(): bool { $this->transaction = false; return true; }

    private function uiKey(string $scope, string $source, string $locale): string
    {
        return $scope . "\0" . $source . "\0" . $locale;
    }

    private function ownedKey(string $scope, string $sourceHash, string $locale): string
    {
        return $scope . "\0" . $sourceHash . "\0" . $locale;
    }

    public function run(string $query, array $params): array
    {
        if (str_starts_with($query, 'SELECT value FROM ct_ui_translation_seeds')) {
            [$scope, $sourceHash, $locale, $source] = $params;
            $row = $this->owned[$this->ownedKey($scope, $sourceHash, $locale)] ?? null;
            return [$row !== null && $row['source'] === $source ? [['value' => $row['value']]] : [], 0];
        }
        if (str_starts_with($query, 'INSERT IGNORE INTO ui_translations')) {
            [$scope, $source, $value, $locale] = $params;
            $key = $this->uiKey($scope, $source, $locale);
            if (array_key_exists($key, $this->ui)) return [[], 0];
            $this->ui[$key] = $value;
            return [[], 1];
        }
        if (str_starts_with($query, 'UPDATE ui_translations SET value')) {
            [$value, $scope, $source, $locale, $previous] = $params;
            $key = $this->uiKey($scope, $source, $locale);
            if (($this->ui[$key] ?? null) !== $previous) return [[], 0];
            $this->ui[$key] = $value;
            return [[], 1];
        }
        if (str_starts_with($query, 'INSERT INTO ct_ui_translation_seeds')) {
            [$scope, $sourceHash, $source, $locale, $value] = $params;
            $this->owned[$this->ownedKey($scope, $sourceHash, $locale)] = compact('source', 'value');
            return [[], 1];
        }
        if (str_starts_with($query, 'SELECT source_hash, source, locale, value FROM ct_ui_translation_seeds')) {
            $rows = [];
            foreach ($this->owned as $key => $row) {
                [$scope, $sourceHash, $locale] = explode("\0", $key, 3);
                if ($scope === $params[0]) $rows[] = ['source_hash' => $sourceHash, 'source' => $row['source'], 'locale' => $locale, 'value' => $row['value']];
            }
            return [$rows, 0];
        }
        if (str_starts_with($query, 'DELETE FROM ui_translations')) {
            [$scope, $source, $locale, $value] = $params;
            $key = $this->uiKey($scope, $source, $locale);
            if (($this->ui[$key] ?? null) !== $value) return [[], 0];
            unset($this->ui[$key]);
            return [[], 1];
        }
        if (str_starts_with($query, 'DELETE FROM ct_ui_translation_seeds')) {
            [$scope, $sourceHash, $locale] = $params;
            $key = $this->ownedKey($scope, $sourceHash, $locale);
            $affected = isset($this->owned[$key]) ? 1 : 0;
            unset($this->owned[$key]);
            return [[], $affected];
        }
        throw new RuntimeException('Unexpected seed query: ' . $query);
    }

    public function setUi(string $source, string $locale, string $value): void
    {
        $this->ui[$this->uiKey('default', $source, $locale)] = $value;
    }

    public function setOwned(string $source, string $locale, string $value): void
    {
        $this->owned[$this->ownedKey('default', hash('sha256', $source), $locale)] = compact('source', 'value');
    }

    public function uiValue(string $source, string $locale): ?string
    {
        return $this->ui[$this->uiKey('default', $source, $locale)] ?? null;
    }

    public function owns(string $source, string $locale): bool
    {
        return isset($this->owned[$this->ownedKey('default', hash('sha256', $source), $locale)]);
    }
}

function add_filter(string $hook, callable $callback, int $priority = 10, int $acceptedArgs = 1): void {}
function add_action(string $hook, callable $callback, int $priority = 10, int $acceptedArgs = 1): void {}
function ct_ensure_schema(PDO $pdo): bool { return true; }
$seedSetting = 'outdated';
function settings_get(PDO $pdo, string $key, mixed $default = null): mixed { global $seedSetting; return $seedSetting; }
function settings_set(PDO $pdo, string $key, mixed $value): void { global $seedSetting; $seedSetting = $value; }

require dirname(__DIR__) . '/includes/shortcode-presets.php';

$catalog = ct_shortcode_preset_ui_translations();
$pdo = new CtSeedPdo();
$pdo->setOwned('Shortcodes', 'id', 'Old plugin value');
$pdo->setUi('Shortcodes', 'id', 'Old plugin value');
$pdo->setOwned('Shortcodes', 'de', 'Alter Pluginwert');
$pdo->setUi('Shortcodes', 'de', 'User edit');
$pdo->setUi('Manage Shortcode Presets', 'id', 'Pre-existing value');
$pdo->setOwned('Obsolete unchanged', 'id', 'Plugin value');
$pdo->setUi('Obsolete unchanged', 'id', 'Plugin value');
$pdo->setOwned('Obsolete edited', 'de', 'Plugin value');
$pdo->setUi('Obsolete edited', 'de', 'User value');

ct_seed_shortcode_preset_ui_translations($pdo);

$failures = [];
$check = static function (bool $condition, string $message) use (&$failures): void {
    echo ($condition ? 'PASS' : 'FAIL') . ' ' . $message . PHP_EOL;
    if (!$condition) $failures[] = $message;
};
$check($pdo->uiValue('Shortcodes', 'id') === $catalog['Shortcodes'][0], 'owned current UI value updates to the new seed');
$check($pdo->uiValue('Shortcodes', 'de') === 'User edit', 'user-edited current UI value is preserved');
$check($pdo->uiValue('Manage Shortcode Presets', 'id') === 'Pre-existing value' && !$pdo->owns('Manage Shortcode Presets', 'id'), 'pre-existing unowned UI row is preserved and remains unowned');
$check($pdo->uiValue('Obsolete unchanged', 'id') === null && !$pdo->owns('Obsolete unchanged', 'id'), 'obsolete unchanged owned UI row and ownership are removed');
$check($pdo->uiValue('Obsolete edited', 'de') === 'User value' && !$pdo->owns('Obsolete edited', 'de'), 'obsolete user-edited UI row is preserved while ownership is removed');
$check(!$pdo->inTransaction(), 'successful reseed commits its transaction');
$check($seedSetting !== 'outdated', 'successful reseed records the catalog hash');

if ($failures !== []) {
    fwrite(STDERR, count($failures) . " seed assertion(s) failed.\n");
    exit(1);
}
echo "RESULT: ALL PASS\n";
