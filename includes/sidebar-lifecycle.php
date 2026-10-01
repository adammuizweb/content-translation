<?php
declare(strict_types=1);

function ct_cleanup_sidebar_item_translations_for_lifecycle(array $event, ResourceLifecycleDatabase $database): void {
    if (($event['resource'] ?? '') !== 'sidebar_item' || ($event['operation'] ?? '') !== 'delete') return;
    $items = $event['items'] ?? null;
    if (!is_array($items) || $items === [] || count($items) > 1000) {
        throw new RuntimeException('Sidebar translation cleanup received an invalid lifecycle event.');
    }
    $ids = [];
    foreach ($items as $item) {
        $id = $item['id'] ?? null;
        if ((!is_int($id) && (!is_string($id) || preg_match('/\A[1-9][0-9]{0,18}\z/D', $id) !== 1)) || (int)$id <= 0) {
            throw new RuntimeException('Sidebar translation cleanup received an invalid item ID.');
        }
        $ids[(int)$id] = (int)$id;
    }
    $ids = array_values($ids);
    if (count($ids) !== count($items)) throw new RuntimeException('Sidebar translation cleanup received duplicate item IDs.');
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $database->prepare("DELETE FROM sidebar_item_translations WHERE sidebar_item_id IN ({$placeholders})");
    if (!$stmt || !$stmt->execute($ids)) throw new RuntimeException('Sidebar translation cleanup failed.');
}

add_action('resource_lifecycle_before_mutation', 'ct_cleanup_sidebar_item_translations_for_lifecycle', 10, 2);
