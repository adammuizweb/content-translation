<?php
declare(strict_types=1);

return static function (PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS ct_media_aliases (
        media_id INT UNSIGNED NOT NULL,
        locale VARCHAR(16) NOT NULL,
        slug VARCHAR(191) NOT NULL,
        created_by INT UNSIGNED NULL,
        updated_by INT UNSIGNED NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (media_id, locale),
        UNIQUE KEY uq_ct_media_alias_locale_slug (locale, slug),
        CONSTRAINT fk_ct_media_alias_media FOREIGN KEY (media_id) REFERENCES media(id) ON DELETE CASCADE ON UPDATE CASCADE,
        CONSTRAINT fk_ct_media_alias_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE,
        CONSTRAINT fk_ct_media_alias_updated_by FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
};
