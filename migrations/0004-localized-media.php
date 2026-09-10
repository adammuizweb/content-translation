<?php
declare(strict_types=1);

return static function (PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS ct_media_profiles (
        media_id INT UNSIGNED NOT NULL PRIMARY KEY,
        metadata_source_locale VARCHAR(16) NOT NULL,
        availability_policy ENUM('all','selected') NOT NULL DEFAULT 'all',
        source_fingerprint CHAR(64) NOT NULL,
        created_by INT UNSIGNED NULL,
        updated_by INT UNSIGNED NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY idx_ct_media_profile_source_locale (metadata_source_locale),
        CONSTRAINT fk_ct_media_profile_media FOREIGN KEY (media_id) REFERENCES media(id) ON DELETE CASCADE ON UPDATE CASCADE,
        CONSTRAINT fk_ct_media_profile_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE,
        CONSTRAINT fk_ct_media_profile_updated_by FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS ct_media_available_locales (
        media_id INT UNSIGNED NOT NULL,
        locale VARCHAR(16) NOT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (media_id, locale),
        KEY idx_ct_media_available_locale (locale),
        CONSTRAINT fk_ct_media_available_media FOREIGN KEY (media_id) REFERENCES ct_media_profiles(media_id) ON DELETE CASCADE ON UPDATE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS ct_media_translations (
        media_id INT UNSIGNED NOT NULL,
        locale VARCHAR(16) NOT NULL,
        title VARCHAR(255) NULL,
        alt TEXT NULL,
        alt_mode ENUM('inherit','text','decorative') NOT NULL DEFAULT 'inherit',
        caption TEXT NULL,
        credit VARCHAR(255) NULL,
        status ENUM('draft','published') NOT NULL DEFAULT 'draft',
        source_fingerprint CHAR(64) NOT NULL,
        created_by INT UNSIGNED NULL,
        updated_by INT UNSIGNED NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (media_id, locale),
        KEY idx_ct_media_translation_status (locale, status),
        KEY idx_ct_media_translation_source (source_fingerprint),
        CONSTRAINT fk_ct_media_translation_media FOREIGN KEY (media_id) REFERENCES ct_media_profiles(media_id) ON DELETE CASCADE ON UPDATE CASCADE,
        CONSTRAINT fk_ct_media_translation_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE,
        CONSTRAINT fk_ct_media_translation_updated_by FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS ct_post_featured_media (
        post_id INT UNSIGNED NOT NULL,
        locale VARCHAR(16) NOT NULL,
        role VARCHAR(32) NOT NULL DEFAULT 'featured',
        mode ENUM('inherit','media','none') NOT NULL DEFAULT 'inherit',
        media_id INT UNSIGNED NULL,
        alt_override TEXT NULL,
        caption_override TEXT NULL,
        source_fingerprint CHAR(64) NOT NULL,
        created_by INT UNSIGNED NULL,
        updated_by INT UNSIGNED NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (post_id, locale, role),
        KEY idx_ct_featured_media (media_id),
        KEY idx_ct_featured_locale (locale, role),
        CONSTRAINT fk_ct_featured_post FOREIGN KEY (post_id) REFERENCES posts(id) ON DELETE CASCADE ON UPDATE CASCADE,
        CONSTRAINT fk_ct_featured_media FOREIGN KEY (media_id) REFERENCES media(id) ON DELETE SET NULL ON UPDATE CASCADE,
        CONSTRAINT fk_ct_featured_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE,
        CONSTRAINT fk_ct_featured_updated_by FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
};
