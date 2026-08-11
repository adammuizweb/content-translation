<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => __('POST required')]);
    return;
}
$pdo = $GLOBALS['pdo'] ?? null;
if (!$pdo instanceof PDO) {
    http_response_code(500);
    echo json_encode(['error' => __('Database not available')]);
    return;
}
if (!function_exists('current_user_role') || current_user_role($pdo) !== 'admin') {
    http_response_code(403);
    echo json_encode(['error' => __('Admin role required')]);
    return;
}
if (!function_exists('csrf_check') || !csrf_check(is_scalar($_POST['csrf_token'] ?? null) ? (string)$_POST['csrf_token'] : '')) {
    http_response_code(419);
    echo json_encode(['error' => __('Invalid CSRF token')]);
    return;
}

$scalar = static function (string $key): string {
    $value = $_POST[$key] ?? '';
    return is_scalar($value) ? (string)$value : '';
};
$presetId = (int)$scalar('preset_id');
$locale = trim($scalar('locale'));
$loadedSourceState = trim($scalar('source_state'));
$loadedTranslationState = trim($scalar('translation_state'));
$intent = $scalar('intent');
if ($intent === 'repair_orphans') {
    try {
        $removed = ct_repair_shortcode_preset_orphans($pdo);
        echo json_encode([
            'success' => true,
            'message' => sprintf(__('%d orphan preset translation(s) removed.'), $removed),
        ]);
    } catch (Throwable $e) {
        error_log('[content-translation] orphan repair error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => __('Orphan preset translation cleanup failed. Check the server error log.')]);
    }
    return;
}
if ($presetId <= 0 || $locale === '' || !in_array($intent, ['save', 'delete'], true)) {
    http_response_code(422);
    echo json_encode(['error' => __('Invalid preset translation request.')]);
    return;
}

try {
    if ($intent === 'delete') {
        ct_delete_shortcode_preset_translation($pdo, $presetId, $locale, $loadedSourceState, $loadedTranslationState);
        echo json_encode(['success' => true, 'message' => __('Translation deleted.')]);
        return;
    }
    $saved = ct_save_shortcode_preset_translation($pdo, $presetId, $locale, $loadedSourceState, $loadedTranslationState, [
        'title' => $scalar('title'),
        'kicker' => $scalar('kicker'),
        'status' => $scalar('status'),
    ]);
    echo json_encode([
        'success' => true,
        'message' => __('Translation saved.'),
        'translation_state' => ct_shortcode_preset_translation_state_token($saved),
    ]);
} catch (Throwable $e) {
    http_response_code($e instanceof InvalidArgumentException ? 422 : 409);
    echo json_encode(['error' => __($e->getMessage())]);
}
