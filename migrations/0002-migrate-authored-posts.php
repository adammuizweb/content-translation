<?php
declare(strict_types=1);

return static function (PDO $pdo): void {
    require_once dirname(__DIR__) . '/includes/workflow-migration.php';
    $default = function_exists('content_default_locale') ? content_default_locale() : 'en';
    ct_130_migrate_legacy_authored_posts($pdo, $default);
};
