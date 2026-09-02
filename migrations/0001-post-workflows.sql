CREATE TABLE IF NOT EXISTS ct_post_workflows (
    post_id INT UNSIGNED NOT NULL PRIMARY KEY,
    source_locale VARCHAR(16) NOT NULL,
    author_locale VARCHAR(16) NOT NULL,
    source_status ENUM('draft','published','private') NOT NULL DEFAULT 'draft',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_ct_workflow_author_locale (author_locale),
    KEY idx_ct_workflow_source_status (source_status),
    CONSTRAINT fk_ct_workflow_post FOREIGN KEY (post_id) REFERENCES posts(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
