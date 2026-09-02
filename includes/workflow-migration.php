<?php
declare(strict_types=1);

if (!function_exists('ct_130_pending_source_slug')) {
    function ct_130_pending_source_slug(PDO $pdo, int $postId, string $locale): string {
        $base = 'ct-pending-' . strtolower($locale) . '-' . $postId;
        $postConflict = $pdo->prepare('SELECT id FROM posts WHERE slug = ? AND id != ? AND is_deleted = 0 LIMIT 1');
        $translationConflict = $pdo->prepare('SELECT post_id FROM post_translations WHERE locale = ? AND slug = ? AND post_id != ? LIMIT 1');
        $routeConflict = $pdo->prepare('SELECT post_id FROM content_routes WHERE locale = ? AND path = ? AND post_id != ? LIMIT 1');
        $routeLocale = $locale === (function_exists('content_default_locale') ? content_default_locale() : 'en') ? '' : $locale;
        for ($suffix = 0; $suffix < 100; $suffix++) {
            $slug = $suffix === 0 ? $base : $base . '-' . $suffix;
            $postConflict->execute([$slug, $postId]);
            $translationConflict->execute([$locale, $slug, $postId]);
            $routeConflict->execute([$routeLocale, $slug, $postId]);
            if (!$postConflict->fetchColumn() && !$translationConflict->fetchColumn() && !$routeConflict->fetchColumn()) {
                return $slug;
            }
        }
        throw new RuntimeException('Canonical source placeholder route is unavailable.');
    }

    function ct_130_migrate_legacy_authored_posts(PDO $pdo, string $default): void {
        $probe = $pdo->prepare("SELECT p.id FROM posts p
            LEFT JOIN ct_post_workflows w ON w.post_id = p.id
            WHERE p.type = 'article' AND p.is_deleted = 0 AND w.post_id IS NULL
              AND JSON_VALID(p.meta)
              AND JSON_EXTRACT(p.meta, '$.content_translation.authoring_locale') IS NOT NULL
            LIMIT 1");
        $probe->execute();
        if (!$probe->fetchColumn()) return;

        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare("SELECT p.*,
                    JSON_UNQUOTE(JSON_EXTRACT(p.meta, '$.content_translation.authoring_locale')) AS author_locale
                FROM posts p
                LEFT JOIN ct_post_workflows w ON w.post_id = p.id
                WHERE p.type = 'article' AND p.is_deleted = 0 AND w.post_id IS NULL
                  AND JSON_VALID(p.meta)
                  AND JSON_EXTRACT(p.meta, '$.content_translation.authoring_locale') IS NOT NULL
                FOR UPDATE");
            $stmt->execute();
            $legacyPosts = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $translationSelect = $pdo->prepare('SELECT * FROM post_translations WHERE post_id = ? AND locale = ? LIMIT 1 FOR UPDATE');
            $translationSave = $pdo->prepare("INSERT INTO post_translations (post_id, locale, title, slug, content, meta_description, status)
                VALUES (?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE title = VALUES(title), slug = VALUES(slug), content = VALUES(content), meta_description = VALUES(meta_description), status = VALUES(status)");
            $workflowSave = $pdo->prepare('INSERT INTO ct_post_workflows (post_id, source_locale, author_locale, source_status) VALUES (?, ?, ?, ?)');
            $postSave = $pdo->prepare('UPDATE posts SET title = ?, slug = ?, content = ?, meta = ?, status = ? WHERE id = ?');
            $markerClear = $pdo->prepare('UPDATE posts SET meta = ? WHERE id = ?');
            $translationDelete = $pdo->prepare('DELETE FROM post_translations WHERE post_id = ? AND locale = ?');
            $publishedTranslation = $pdo->prepare("SELECT 1 FROM post_translations WHERE post_id = ? AND status = 'published' AND TRIM(title) <> '' AND TRIM(slug) <> '' LIMIT 1");

            foreach ($legacyPosts as $post) {
                $postId = (int)$post['id'];
                $authorLocale = trim((string)$post['author_locale']);
                if ($postId <= 0) throw new RuntimeException('Legacy authored post identity is invalid.');
                $meta = is_string($post['meta'] ?? null) ? json_decode((string)$post['meta'], true) : [];
                if (!is_array($meta)) $meta = [];
                if ($authorLocale === '' || $authorLocale === $default) {
                    if (is_array($meta['content_translation'] ?? null)) {
                        unset($meta['content_translation']['authoring_locale']);
                        if ($meta['content_translation'] === []) unset($meta['content_translation']);
                    }
                    $cleanMeta = $meta === [] ? null : json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
                    $markerClear->execute([$cleanMeta, $postId]);
                    continue;
                }

                $translationSelect->execute([$postId, $default]);
                $source = $translationSelect->fetch(PDO::FETCH_ASSOC) ?: null;
                $metaTags = is_array($meta['meta_tags'] ?? null) ? $meta['meta_tags'] : [];
                $authoredDescription = trim((string)($metaTags['description'] ?? ''));
                $authoredStatus = (string)$post['status'] === 'published' ? 'published' : 'draft';
                $translationSave->execute([
                    $postId,
                    $authorLocale,
                    (string)$post['title'],
                    (string)$post['slug'],
                    (string)$post['content'],
                    $authoredDescription,
                    $authoredStatus,
                ]);

                if (is_array($meta['content_translation'] ?? null)) {
                    unset($meta['content_translation']['authoring_locale']);
                    if ($meta['content_translation'] === []) unset($meta['content_translation']);
                }
                unset($metaTags['description']);
                if ($metaTags === []) {
                    unset($meta['meta_tags']);
                } else {
                    $meta['meta_tags'] = $metaTags;
                }

                $sourceComplete = $source
                    && trim((string)$source['title']) !== ''
                    && trim((string)$source['slug']) !== '';
                $sourceStatus = $sourceComplete && (string)$source['status'] === 'published' ? 'published' : 'draft';
                $sourceTitle = $sourceComplete ? (string)$source['title'] : '[' . strtoupper($default) . ' translation pending]';
                $sourceSlug = $sourceComplete ? (string)$source['slug'] : ct_130_pending_source_slug($pdo, $postId, $default);
                $sourceContent = $source ? (string)$source['content'] : '';
                $sourceDescription = $source ? trim((string)($source['meta_description'] ?? '')) : '';
                if ($sourceDescription !== '') $meta['meta_tags']['description'] = $sourceDescription;
                $sourceMeta = $meta === [] ? null : json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

                $translationDelete->execute([$postId, $default]);
                $workflowSave->execute([$postId, $default, $authorLocale, $sourceStatus]);
                $publishedTranslation->execute([$postId]);
                $effectiveStatus = $sourceStatus === 'published' || $publishedTranslation->fetchColumn()
                    ? 'published'
                    : 'draft';
                $postSave->execute([$sourceTitle, $sourceSlug, $sourceContent, $sourceMeta, $effectiveStatus, $postId]);
            }
            if ($ownsTransaction) $pdo->commit();
        } catch (Throwable $error) {
            if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack();
            throw $error;
        }
    }
}
