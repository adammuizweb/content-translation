<?php
declare(strict_types=1);

final class CtPresetTranslationStatement extends PDOStatement
{
    private array $rows;
    public function __construct(array $rows) { $this->rows = $rows; }
    public function execute(?array $params = null): bool { return true; }
    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed
    {
        return array_shift($this->rows) ?: false;
    }
    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array { return $this->rows; }
    public function fetchColumn(int $column = 0): mixed
    {
        $row = array_shift($this->rows);
        return is_array($row) ? (array_values($row)[$column] ?? false) : false;
    }
}

final class CtPresetTranslationPdo extends PDO
{
    public function __construct(private array $translation) {}
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        if (str_contains($query, 'FROM shortcode_preset_translations')) {
            return new CtPresetTranslationStatement([$this->translation]);
        }
        return new CtPresetTranslationStatement([]);
    }
}

final class CtPresetMutationPdo extends PDO
{
    public array $queries = [];
    private bool $transaction = false;

    public function __construct(public array $source, public ?array $translation = null) {}
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $this->queries[] = $query;
        if (str_contains($query, 'SELECT meta FROM posts')) {
            return new CtPresetTranslationStatement([['meta' => (string)$this->source['meta']]]);
        }
        if (str_contains($query, 'FROM posts')) return new CtPresetTranslationStatement([$this->source]);
        if (str_contains($query, 'FROM shortcode_preset_translations')) {
            return new CtPresetTranslationStatement($this->translation === null ? [] : [$this->translation]);
        }
        return new CtPresetTranslationStatement([]);
    }
    public function beginTransaction(): bool { $this->transaction = true; return true; }
    public function inTransaction(): bool { return $this->transaction; }
    public function commit(): bool { $this->transaction = false; return true; }
    public function rollBack(): bool { $this->transaction = false; return true; }
}

final class CtPresetFailingStatement extends PDOStatement
{
    public function execute(?array $params = null): bool { throw new PDOException('simulated cleanup failure'); }
}

final class CtPresetFailingDeletePdo extends PDO
{
    public function __construct() {}
    public function inTransaction(): bool { return true; }
    public function prepare(string $query, array $options = []): PDOStatement|false { return new CtPresetFailingStatement(); }
}

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
function ct_ensure_schema(PDO $pdo): bool { return true; }
function ct_enabled_locales(PDO $pdo): array { return ['de']; }
function __(string $message): string { return $message; }
function ct_current_user_id(): int { return 1; }
$canTranslatePreset = true;
function ct_user_can_workspace(PDO $pdo, ?int $userId = null): bool { global $canTranslatePreset; return $canTranslatePreset; }
function user_can(PDO $pdo, int $userId, string $permission, array $context = []): bool { global $canTranslatePreset; return $canTranslatePreset; }

require dirname(__DIR__) . '/includes/shortcode-presets.php';

$failures = [];
$check = static function (bool $condition, string $message) use (&$failures): void {
    echo ($condition ? 'PASS' : 'FAIL') . ' ' . $message . PHP_EOL;
    if (!$condition) $failures[] = $message;
};

$decoded = ct_shortcode_preset_decode_overrides('{"kicker":"Neuigkeiten"}');
$check($decoded === ['kicker' => 'Neuigkeiten'], 'controlled override decoder accepts the localized kicker');
$check(ct_shortcode_preset_decode_overrides('{"layout":"grid"}') === null, 'controlled override decoder rejects structural layout keys');
$check(ct_shortcode_preset_decode_overrides('{"kicker":"x","limit":"200"}') === null, 'controlled override decoder rejects mixed text and query keys');
$check(ct_shortcode_preset_decode_overrides('[]') === null && ct_shortcode_preset_decode_overrides('{bad') === null, 'controlled override decoder rejects list and malformed JSON');

$valid = ct_validate_shortcode_preset_translation(['title' => 'Startseite', 'kicker' => 'Aktuell', 'status' => 'published']);
$check($valid === ['title' => 'Startseite', 'kicker' => 'Aktuell', 'status' => 'published'], 'translation validation accepts bounded published text');
foreach ([
    ['title' => '', 'kicker' => '', 'status' => 'published'],
    ['title' => str_repeat('x', 192), 'kicker' => '', 'status' => 'draft'],
    ['title' => 'Title', 'kicker' => ['layout' => 'grid'], 'status' => 'draft'],
    ['title' => 'Title', 'kicker' => '', 'status' => 'private'],
] as $invalid) {
    $rejected = false;
    try { ct_validate_shortcode_preset_translation($invalid); } catch (InvalidArgumentException $e) { $rejected = true; }
    $check($rejected, 'invalid translation input is rejected');
}

$row = [
    'id' => 2, 'preset_id' => 9, 'locale' => 'de', 'title' => 'Startseite',
    'overrides_json' => '{"kicker":"Aktuell"}', 'status' => 'published',
    'created_at' => '2026-08-11 10:00:00', 'updated_at' => '2026-08-11 10:00:00',
];
$token = ct_shortcode_preset_translation_state_token($row);
$changed = $row;
$changed['overrides_json'] = '{"kicker":"Geändert"}';
$check($token === ct_shortcode_preset_translation_state_token($row), 'optimistic state token is deterministic');
$check($token !== ct_shortcode_preset_translation_state_token($changed), 'optimistic state token covers the complete mutable row');
$check(ct_shortcode_preset_translation_state_token(null) !== $token, 'missing-row optimistic state is distinct');
$sourceRow = ['id' => 9, 'title' => 'Cards', 'slug' => 'cards', 'status' => 'published', 'meta' => '{"layout":"grid","kicker":"Latest"}'];
$sourceToken = ct_shortcode_preset_source_state_token($sourceRow);
$reorderedSource = $sourceRow;
$reorderedSource['meta'] = '{"kicker":"Latest","layout":"grid"}';
$changedSource = $sourceRow;
$changedSource['meta'] = '{"layout":"grid","kicker":"Changed"}';
$check($sourceToken === ct_shortcode_preset_source_state_token($reorderedSource), 'source state ignores irrelevant JSON object key order');
$check($sourceToken !== ct_shortcode_preset_source_state_token($changedSource), 'source state covers meaningful preset configuration');
$check(ct_shortcode_preset_text_length('Grüße😀') === 6, 'character counting is Unicode-safe');
$check(ct_shortcode_preset_like_pattern('100%_!') === '%100!%!_!!%', 'search escaping treats percent, underscore, and the escape character literally');
$check(ct_shortcode_preset_kicker_mode(['layout' => 'cards']) === 'automatic', 'missing source kicker is the automatic category-heading state');
$check(ct_shortcode_preset_kicker_mode(['kicker' => '']) === 'hidden', 'explicit-empty source kicker is the hidden state');
$check(ct_shortcode_preset_kicker_mode(['kicker' => '   ']) === 'hidden', 'present whitespace source kicker follows Core hidden behavior');
$check(ct_shortcode_preset_kicker_mode(['kicker' => 'Latest']) === 'custom', 'nonempty source kicker is the custom state');

$runtime = $filters['shortcode_preset_runtime_config'][20][0][0] ?? null;
$check(is_callable($runtime), 'runtime preset config filter is registered');
$GLOBALS['ct_request_locale'] = 'de';
$pdo = new CtPresetTranslationPdo($row);
$source = ['kicker' => 'Latest', 'layout' => 'grid', 'limit' => 7, 'plugin_extension' => ['kept' => true]];
$localized = $runtime($source, ['id' => 9], $pdo, ['slot' => 'main.homepage']);
$check(($localized['kicker'] ?? '') === 'Aktuell', 'published locale overlays the preset kicker');
$check(($localized['layout'] ?? '') === 'grid' && ($localized['limit'] ?? 0) === 7, 'runtime leaves structural query and layout values unchanged');
$check(($localized['plugin_extension']['kept'] ?? false) === true, 'runtime preserves unknown extension configuration');
$check(($runtime($source, ['id' => 9], $pdo)['kicker'] ?? '') === 'Aktuell', 'runtime callback tolerates an omitted Core context argument');
$draft = $row;
$draft['status'] = 'draft';
$draftResult = $runtime($source, ['id' => 9], new CtPresetTranslationPdo($draft), []);
$check($draftResult === $source, 'draft preset translation is not applied at runtime');
$summaries = ct_shortcode_preset_translation_statuses($pdo, [9]);
$check(($summaries[9]['de']['title'] ?? '') === 'Startseite', 'translated management title is returned for administration displays');
unset($GLOBALS['ct_request_locale']);
$check($runtime($source, ['id' => 9], $pdo, []) === $source, 'default-locale runtime does not query or alter preset config');

$beforeSave = $filters['shortcode_preset_config_before_save'][20][0][0] ?? null;
$mutationPdo = new CtPresetMutationPdo($sourceRow, $row);
$savedConfig = $beforeSave(['layout' => 'cards', 'unknown' => 'keep', 'kicker' => '  Heading  ', '_ct_kicker_mode' => 'custom'], ['id' => 9, 'is_admin' => true], $mutationPdo);
$check($savedConfig === ['layout' => 'cards', 'unknown' => 'keep', 'kicker' => 'Heading'], 'Core save hook normalizes text while preserving unknown config');
$automaticConfig = $beforeSave(['layout' => 'cards', 'kicker' => 'Old', '_ct_kicker_mode' => 'automatic'], ['id' => 9, 'is_admin' => true], $mutationPdo);
$check($automaticConfig === ['layout' => 'cards'], 'automatic mode saves the source kicker as a missing key');
$hiddenConfig = $beforeSave(['layout' => 'cards', 'kicker' => 'Forged', '_ct_kicker_mode' => 'hidden'], ['id' => 9, 'is_admin' => true], $mutationPdo);
$check($hiddenConfig === ['layout' => 'cards', 'kicker' => ''], 'hidden mode saves an explicit-empty source kicker');
$invalidMode = $beforeSave(['layout' => 'cards', 'kicker' => 'Forged', '_ct_kicker_mode' => 'forged'], ['id' => 9, 'is_admin' => true], $mutationPdo);
$validation = $filters['shortcode_preset_validation_errors'][20][0][0] ?? null;
$check(in_array('Invalid source heading behavior.', $validation([], $invalidMode), true), 'admin source heading mode is validated server-side');
$emptySourceRow = $sourceRow;
$emptySourceRow['meta'] = '{"layout":"grid","kicker":""}';
$emptyMutationPdo = new CtPresetMutationPdo($emptySourceRow, $row);
$unrelatedAdminSave = $beforeSave(['layout' => 'list', 'kicker' => ''], ['id' => 9, 'is_admin' => true], $emptyMutationPdo);
$check(array_key_exists('kicker', $unrelatedAdminSave) && $unrelatedAdminSave['kicker'] === '', 'unrelated admin save preserves a persisted explicit-empty source kicker');
$canTranslatePreset = false;
$forged = $beforeSave(['layout' => 'cards', 'kicker' => 'Forged', '_ct_kicker_mode' => 'automatic'], ['id' => 9, 'is_admin' => false], $mutationPdo);
$check(($forged['kicker'] ?? '') === 'Latest' && !array_key_exists('_ct_kicker_mode', $forged), 'forged non-admin kicker mutation preserves persisted state and ignores the mode marker');
$forgedEmpty = $beforeSave(['layout' => 'cards', 'kicker' => 'Forged', '_ct_kicker_mode' => 'custom'], ['id' => 9, 'is_admin' => false], $emptyMutationPdo);
$check(array_key_exists('kicker', $forgedEmpty) && $forgedEmpty['kicker'] === '', 'forged non-admin save preserves persisted explicit-empty state');
$forgedCreate = $beforeSave(['layout' => 'cards', 'kicker' => 'Forged'], ['id' => 0, 'is_admin' => false], $mutationPdo);
$check(!array_key_exists('kicker', $forgedCreate), 'non-admin preset creation cannot add a source kicker');
$canTranslatePreset = true;

$conflict = false;
try {
    ct_save_shortcode_preset_translation($mutationPdo, 9, 'de', str_repeat('0', 64), $token, [
        'title' => 'Startseite', 'kicker' => 'Aktuell', 'status' => 'published',
    ]);
} catch (RuntimeException $e) {
    $conflict = str_contains($e->getMessage(), 'source preset changed');
}
$check($conflict && !$mutationPdo->inTransaction(), 'save validates source state under lock and rolls back a stale source');
$deleteConflict = false;
try {
    ct_delete_shortcode_preset_translation($mutationPdo, 9, 'de', str_repeat('0', 64), $token);
} catch (RuntimeException $e) {
    $deleteConflict = str_contains($e->getMessage(), 'source preset changed');
}
$check($deleteConflict && !$mutationPdo->inTransaction(), 'delete validates source state under lock and rolls back a stale source');

$preview = $filters['shortcode_preset_preview_config'][20][0][0] ?? null;
$check(is_callable($preview), 'Core preview config extension filter is registered');
$customPreview = $preview(['layout' => 'cards', 'kicker' => 'Preview'], ['mode' => 'inline'], $mutationPdo);
$check(($customPreview['kicker'] ?? '') === 'Preview', 'live preview preserves the custom heading state for the Core resolver');
$hiddenPreview = $preview(['layout' => 'cards', 'kicker' => ''], ['mode' => 'inline'], $mutationPdo);
$check(array_key_exists('kicker', $hiddenPreview) && $hiddenPreview['kicker'] === '', 'live preview preserves the explicit-empty hidden state for the Core resolver');
$automaticEmptyPreview = $preview(['layout' => 'cards', 'category' => ''], ['mode' => 'inline'], $mutationPdo);
$check(!array_key_exists('kicker', $automaticEmptyPreview), 'automatic live preview with an empty category leaves the heading missing for Core resolution');
$automaticCategoryPreview = $preview(['layout' => 'cards', 'category' => 'world-news'], ['mode' => 'inline'], $mutationPdo);
$check(!array_key_exists('kicker', $automaticCategoryPreview), 'automatic live preview with a category leaves the heading missing for Core resolution');
$check(isset($actions['shortcode_preset_editor_fields'][20]), 'Core preset editor field hook is registered');
$check(isset($actions['admin_shortcode_preset_before_delete'][10]), 'failure-propagating Core preset pre-delete cleanup hook is registered');
$deleteHook = $actions['admin_shortcode_preset_before_delete'][10][0][0] ?? null;
$mutationPdo->beginTransaction();
$deleteHook(9, $mutationPdo);
$check($mutationPdo->inTransaction() && count(array_filter($mutationPdo->queries, static fn(string $query): bool => str_contains($query, 'DELETE FROM shortcode_preset_translations'))) === 1, 'pre-delete cleanup stays inside the Core source transaction');
$mutationPdo->rollBack();
$cleanupFailed = false;
try { $deleteHook(9, new CtPresetFailingDeletePdo()); } catch (PDOException $e) { $cleanupFailed = true; }
$check($cleanupFailed, 'pre-delete cleanup failure propagates so Core can roll back source deletion');

if ($failures !== []) {
    fwrite(STDERR, count($failures) . " assertion(s) failed.\n");
    exit(1);
}
echo "RESULT: ALL PASS\n";
