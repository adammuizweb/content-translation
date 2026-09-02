<?php
declare(strict_types=1);

return static function (PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS post_translations (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        post_id INT UNSIGNED NOT NULL,
        locale VARCHAR(16) NOT NULL,
        title VARCHAR(255) NOT NULL DEFAULT '',
        slug VARCHAR(255) NOT NULL DEFAULT '',
        content MEDIUMTEXT NULL,
        status ENUM('draft','published') NOT NULL DEFAULT 'published',
        meta_description VARCHAR(320) NOT NULL DEFAULT '',
        updated_by INT UNSIGNED NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_post_locale (post_id, locale),
        KEY idx_locale_slug (locale, slug),
        KEY idx_post_translations_updated_by (updated_by),
        CONSTRAINT fk_post_translations_updated_by FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS ct_user_locale_edit_grants (
        user_id INT UNSIGNED NOT NULL,
        locale VARCHAR(16) NOT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (user_id, locale),
        KEY idx_ct_user_locale (locale),
        CONSTRAINT fk_ct_user_locale_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE ON UPDATE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS ct_role_locale_edit_grants (
        role_id INT UNSIGNED NOT NULL,
        locale VARCHAR(16) NOT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (role_id, locale),
        KEY idx_ct_role_locale (locale),
        CONSTRAINT fk_ct_role_locale_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE ON UPDATE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $column = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
    $column->execute(['post_translations', 'updated_by']);
    if ((int)$column->fetchColumn() === 0) {
        $pdo->exec('ALTER TABLE post_translations ADD COLUMN updated_by INT UNSIGNED NULL AFTER status');
    }
    $index = $pdo->prepare('SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?');
    $index->execute(['post_translations', 'idx_post_translations_updated_by']);
    if ((int)$index->fetchColumn() === 0) {
        $pdo->exec('ALTER TABLE post_translations ADD KEY idx_post_translations_updated_by (updated_by)');
    }
    $constraint = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ?');
    $constraint->execute(['post_translations', 'fk_post_translations_updated_by']);
    if ((int)$constraint->fetchColumn() === 0) {
        $pdo->exec('ALTER TABLE post_translations ADD CONSTRAINT fk_post_translations_updated_by FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE');
    }

    $setting = $pdo->prepare('SELECT `value` FROM settings WHERE `key` = ? LIMIT 1');
    $setting->execute(['content_translation_author_locales']);
    $preferences = json_decode((string)($setting->fetchColumn() ?: ''), true);
    if (!is_array($preferences)) $preferences = [];

    $seed = $pdo->prepare('INSERT IGNORE INTO ct_user_locale_edit_grants (user_id, locale)
        SELECT id, ? FROM users WHERE id = ? AND is_deleted = 0');
    foreach ($preferences as $userId => $locale) {
        $userId = (int)$userId;
        $locale = trim((string)$locale);
        if ($userId > 0 && preg_match('/\A[a-z]{2,3}(?:-[A-Za-z0-9]{2,8})?\z/', $locale) === 1) {
            $seed->execute([$locale, $userId]);
        }
    }

    $default = function_exists('content_default_locale') ? content_default_locale() : 'en';
    if (preg_match('/\A[a-z]{2,3}(?:-[A-Za-z0-9]{2,8})?\z/', $default) !== 1) return;
    $preferredUserIds = array_values(array_filter(array_map('intval', array_keys($preferences)), static fn(int $id): bool => $id > 0));
    $workspaceUsers = $pdo->prepare("SELECT DISTINCT u.id
        FROM users u
        INNER JOIN user_roles ur ON ur.user_id = u.id AND (ur.expires_at IS NULL OR ur.expires_at > NOW())
        INNER JOIN role_permissions rp ON rp.role_id = ur.role_id
        WHERE u.is_deleted = 0 AND u.is_locked = 0
          AND rp.permission_key = 'plugin.content-translation.workspace.access'");
    $workspaceUsers->execute();
    $workspaceUserIds = $workspaceUsers->fetchAll(PDO::FETCH_COLUMN);
    foreach ($workspaceUserIds as $workspaceUserId) {
        $workspaceUserId = (int)$workspaceUserId;
        if ($workspaceUserId > 0 && !in_array($workspaceUserId, $preferredUserIds, true)) {
            $seed->execute([$default, $workspaceUserId]);
        }
    }
    if ($preferences !== [] || $workspaceUserIds !== []) {
        $markSeeded = $pdo->prepare("INSERT INTO settings (`key`, `value`) VALUES (?, '1') ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)");
        $markSeeded->execute(['content_translation_locale_grants_seeded']);
    }
};
