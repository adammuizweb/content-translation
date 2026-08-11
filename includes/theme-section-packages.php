<?php
declare(strict_types=1);

if (!function_exists('ct_theme_section_package_format')) {
    function ct_theme_section_package_format(): string {
        return 'ct-theme-sections-v1';
    }

    function ct_parse_theme_section_attributes(string $raw): ?array {
        $attrs = [];
        $offset = 0;
        $length = strlen($raw);
        while ($offset < $length) {
            if (preg_match('/\G\s+/A', $raw, $space, 0, $offset) === 1) $offset += strlen($space[0]);
            if ($offset >= $length) break;
            if (preg_match('/\G([a-zA-Z_][a-zA-Z0-9_-]*)\s*=\s*/A', $raw, $match, 0, $offset) !== 1) return null;
            $key = (string)$match[1];
            if (array_key_exists($key, $attrs)) return null;
            $offset += strlen($match[0]);
            if ($offset >= $length) return null;
            $quote = $raw[$offset];
            if ($quote === '"' || $quote === "'") {
                $end = strpos($raw, $quote, $offset + 1);
                if ($end === false) return null;
                $value = substr($raw, $offset + 1, $end - $offset - 1);
                $offset = $end + 1;
            } elseif (preg_match('/\G[^\s"\'`=<>]+/A', $raw, $valueMatch, 0, $offset) === 1) {
                $value = (string)$valueMatch[0];
                $offset += strlen($valueMatch[0]);
            } else {
                return null;
            }
            if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value)
                || str_contains($value, '<?')
                || stripos($value, '[[widget:') !== false) {
                return null;
            }
            $attrs[$key] = $value;
        }
        return $attrs;
    }

    /** Parse a source that contains only ordered Core Theme Section shortcodes and whitespace. */
    function ct_parse_theme_section_composition(string $content): ?array {
        if ($content === '' || strlen($content) > 2 * 1024 * 1024 || str_contains($content, '<?')) return null;
        $sections = [];
        $seen = [];
        $offset = 0;
        $length = strlen($content);
        while ($offset < $length) {
            if (preg_match('/\G\s+/A', $content, $space, 0, $offset) === 1) $offset += strlen($space[0]);
            if ($offset >= $length) break;
            if (substr($content, $offset, 2) !== '[[') return null;

            $quote = null;
            $end = null;
            for ($i = $offset + 2; $i < $length - 1; $i++) {
                $char = $content[$i];
                if ($quote !== null) {
                    if ($char === ']') return null;
                    if ($char === $quote) $quote = null;
                    continue;
                }
                if ($char === '"' || $char === "'") {
                    $quote = $char;
                    continue;
                }
                if ($char === ']' && $content[$i + 1] === ']') {
                    $end = $i;
                    break;
                }
                if ($char === ']') return null;
            }
            if ($end === null || $quote !== null) return null;
            $inside = trim(substr($content, $offset + 2, $end - $offset - 2));
            if (preg_match('/\Awidget:theme_section(?:\s+(.*))?\z/s', $inside, $match) !== 1) return null;
            $attrs = ct_parse_theme_section_attributes((string)($match[1] ?? ''));
            $name = is_array($attrs) ? (string)($attrs['name'] ?? '') : '';
            if ($attrs === null
                || !function_exists('theme_section_name_is_valid')
                || !theme_section_name_is_valid($name)
                || isset($seen[$name])) {
                return null;
            }
            $seen[$name] = true;
            $sections[] = ['name' => $name, 'attrs' => $attrs];
            $offset = $end + 2;
            if (count($sections) > 100) return null;
        }
        return $sections !== [] ? $sections : null;
    }

    function ct_theme_section_template_usages(PDO $pdo, string $sectionName): array {
        if (!function_exists('theme_section_name_is_valid') || !theme_section_name_is_valid($sectionName)) return [];
        $usages = [];
        $cursor = PHP_INT_MAX;
        $batchSize = 250;
        do {
            $stmt = $pdo->prepare("SELECT id, type, title, slug, content, status, updated_at FROM posts WHERE type = 'theme' AND is_deleted = 0 AND LOCATE('widget:theme_section', content) > 0 AND id < ? ORDER BY id DESC LIMIT {$batchSize}");
            $stmt->execute([$cursor]);
            $posts = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($posts as $post) {
                $composition = ct_parse_theme_section_composition((string)$post['content']);
                if ($composition === null) continue;
                foreach ($composition as $index => $section) {
                    if ((string)$section['name'] !== $sectionName) continue;
                    $post['section_index'] = $index;
                    $usages[] = $post;
                    break;
                }
            }
            if ($posts !== []) $cursor = (int)$posts[count($posts) - 1]['id'];
        } while (count($posts) === $batchSize);
        return $usages;
    }

    function ct_theme_section_url_is_safe(string $url): bool {
        $decoded = $url;
        for ($i = 0; $i < 3; $i++) {
            $next = html_entity_decode($decoded, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if ($next === $decoded) break;
            $decoded = $next;
        }
        $decoded = trim($decoded);
        if ($decoded === '') return true;
        if (preg_match('/[\x00-\x1F\x7F]/', $decoded)) return false;
        $schemeProbe = preg_replace('/[\x09-\x0D\x20]+/', '', $decoded) ?? $decoded;
        if (preg_match('/\A([a-z][a-z0-9+.-]*):/i', $schemeProbe, $match) !== 1) return true;
        return in_array(strtolower((string)$match[1]), ['http', 'https', 'mailto', 'tel'], true);
    }

    function ct_theme_section_css_is_safe(string $css): bool {
        for ($i = 0; $i < 3; $i++) {
            $next = html_entity_decode($css, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if ($next === $css) break;
            $css = $next;
        }
        $css = preg_replace('~/\*.*?\*/~s', '', $css) ?? $css;
        $css = preg_replace_callback('/\\\\([0-9a-f]{1,6})\s?/i', static function (array $match): string {
            $code = hexdec((string)$match[1]);
            if ($code <= 0x7f) return chr($code);
            return '';
        }, $css) ?? $css;
        $css = preg_replace('/\\\\([^0-9a-f\r\n])/i', '$1', $css) ?? $css;
        $probe = strtolower($css);
        if (preg_match('/expression\s*\(|@import\b|(?:javascript|vbscript|data)\s*:|behavior\s*:|-moz-binding\s*:/i', $probe)) return false;
        $compact = preg_replace('/[\x00-\x20\x7F]+/', '', $probe) ?? $probe;
        if (preg_match('/(?:javascript|vbscript|data):/i', $compact)) return false;
        if (preg_match_all('/url\s*\(\s*(["\']?)(.*?)\1\s*\)/is', $css, $urls, PREG_SET_ORDER)) {
            foreach ($urls as $url) {
                if (!ct_theme_section_url_is_safe((string)$url[2])) return false;
            }
        }
        return true;
    }

    /** Validate without parsing and serializing the document, so accepted bytes are untouched. */
    function ct_validate_theme_section_html(string $html): ?string {
        if ($html === '' || strlen($html) > 1024 * 1024) return 'Section HTML is empty or too large.';
        if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $html)) return 'Control characters are not allowed in section HTML.';
        if (str_contains($html, '<?')) return 'PHP processing instructions are not allowed.';
        if (stripos($html, '[[widget:') !== false) return 'Nested widget shortcodes are not allowed.';
        if (str_contains($html, '<!')) return 'HTML declarations and comments are not allowed.';

        $forbiddenTags = array_flip([
            'script', 'style', 'iframe', 'object', 'embed', 'form', 'input', 'button', 'select', 'option',
            'optgroup', 'textarea', 'fieldset', 'legend', 'label', 'datalist', 'output', 'progress', 'meter',
            'base', 'meta', 'link', 'frame', 'frameset', 'applet', 'template',
            'keygen', 'isindex', 'animate', 'animatemotion', 'animatetransform', 'set', 'plaintext', 'xmp',
            'title', 'noscript', 'noembed', 'noframes',
        ]);
        $urlAttrs = array_flip([
            'href', 'src', 'action', 'poster', 'background', 'cite', 'data', 'xlink:href', 'usemap',
            'longdesc', 'manifest', 'formaction', 'ping', 'srcset', 'imagesrcset', 'dynsrc', 'lowsrc',
        ]);
        $offset = 0;
        $length = strlen($html);
        while (($start = strpos($html, '<', $offset)) !== false) {
            $quote = null;
            $end = null;
            for ($i = $start + 1; $i < $length; $i++) {
                $char = $html[$i];
                if ($quote !== null) {
                    if ($char === $quote) $quote = null;
                    continue;
                }
                if ($char === '"' || $char === "'") {
                    $quote = $char;
                } elseif ($char === '>') {
                    $end = $i;
                    break;
                }
            }
            if ($end === null || $quote !== null) return 'HTML tag is not closed.';
            $token = substr($html, $start, $end - $start + 1);
            $offset = $end + 1;
            if (preg_match('/\A<!doctype\s+html\s*>\z/i', $token) === 1) continue;
            if (preg_match('/\A<\s*\/\s*([a-z][a-z0-9:-]*)\s*>\z/i', $token, $closing) === 1) {
                if (isset($forbiddenTags[strtolower((string)$closing[1])])) return 'Forbidden HTML tag: ' . strtolower((string)$closing[1]);
                continue;
            }
            if (preg_match('/\A<\s*([a-z][a-z0-9:-]*)(.*)>\z/is', $token, $opening) !== 1) return 'Malformed or unsupported HTML markup.';
            $tag = strtolower((string)$opening[1]);
            if (isset($forbiddenTags[$tag])) return 'Forbidden HTML tag: ' . $tag;
            $rawAttrs = (string)$opening[2];
            $attrOffset = 0;
            $attrLength = strlen($rawAttrs);
            $seenAttrs = [];
            while ($attrOffset < $attrLength) {
                if (preg_match('/\G\s+/A', $rawAttrs, $space, 0, $attrOffset) === 1) $attrOffset += strlen($space[0]);
                if ($attrOffset >= $attrLength) break;
                if ($rawAttrs[$attrOffset] === '/' && trim(substr($rawAttrs, $attrOffset + 1)) === '') break;
                if (preg_match('/\G([a-zA-Z_:][a-zA-Z0-9_.:-]*)/A', $rawAttrs, $attrMatch, 0, $attrOffset) !== 1) return 'Malformed HTML attribute.';
                $attribute = strtolower((string)$attrMatch[1]);
                if (isset($seenAttrs[$attribute])) return 'Duplicate HTML attribute: ' . $attribute;
                $seenAttrs[$attribute] = true;
                $attrOffset += strlen($attrMatch[0]);
                if (preg_match('/\G\s*/A', $rawAttrs, $space, 0, $attrOffset) === 1) $attrOffset += strlen($space[0]);
                $value = '';
                if ($attrOffset < $attrLength && $rawAttrs[$attrOffset] === '=') {
                    $attrOffset++;
                    if (preg_match('/\G\s*/A', $rawAttrs, $space, 0, $attrOffset) === 1) $attrOffset += strlen($space[0]);
                    if ($attrOffset >= $attrLength) return 'HTML attribute value is missing.';
                    $attrQuote = $rawAttrs[$attrOffset];
                    if ($attrQuote === '"' || $attrQuote === "'") {
                        $attrEnd = strpos($rawAttrs, $attrQuote, $attrOffset + 1);
                        if ($attrEnd === false) return 'HTML attribute quote is not closed.';
                        $value = substr($rawAttrs, $attrOffset + 1, $attrEnd - $attrOffset - 1);
                        $attrOffset = $attrEnd + 1;
                    } elseif (preg_match('/\G[^\s"\'`=<>]+/A', $rawAttrs, $unquoted, 0, $attrOffset) === 1) {
                        $value = (string)$unquoted[0];
                        $attrOffset += strlen($unquoted[0]);
                    } else {
                        return 'Malformed HTML attribute value.';
                    }
                }
                if (str_starts_with($attribute, 'on') || in_array($attribute, ['srcdoc', 'formaction'], true)) {
                    return 'Unsafe HTML attribute: ' . $attribute;
                }
                if ($attribute === 'style' && !ct_theme_section_css_is_safe($value)) return 'Unsafe CSS in style attribute.';
                if (in_array($attribute, ['fill', 'stroke', 'filter', 'clip-path', 'mask', 'cursor', 'marker', 'marker-start', 'marker-mid', 'marker-end'], true)
                    && !ct_theme_section_css_is_safe($value)) {
                    return 'Unsafe CSS URL in ' . $attribute . ' attribute.';
                }
                if (isset($urlAttrs[$attribute])) {
                    $values = in_array($attribute, ['srcset', 'imagesrcset'], true)
                        ? preg_split('/\s*,\s*/', $value)
                        : ($attribute === 'ping' ? preg_split('/\s+/', trim($value)) : [$value]);
                    foreach ((array)$values as $candidate) {
                        $candidate = trim((string)$candidate);
                        if (in_array($attribute, ['srcset', 'imagesrcset'], true)) $candidate = (string)(preg_split('/\s+/', $candidate)[0] ?? '');
                        if (!ct_theme_section_url_is_safe($candidate)) return 'Unsafe URL in ' . $attribute . ' attribute.';
                    }
                }
            }
        }
        return null;
    }

    function ct_decode_theme_section_package(string $content): ?array {
        if ($content === '' || strlen($content) > 2 * 1024 * 1024) return null;
        try {
            $package = json_decode($content, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            return null;
        }
        if (!is_array($package)
            || array_keys($package) !== ['format', 'theme_folder', 'composition', 'source_sha256', 'sections']
            || $package['format'] !== ct_theme_section_package_format()
            || preg_match('/\A[a-z0-9][a-z0-9_-]*\z/i', (string)$package['theme_folder']) !== 1
            || $package['composition'] !== 'theme-sections-v1'
            || preg_match('/\A[a-f0-9]{64}\z/', (string)$package['source_sha256']) !== 1
            || !is_array($package['sections'])
            || $package['sections'] === []
            || count($package['sections']) > 100) {
            return null;
        }

        foreach ($package['sections'] as $name => $section) {
            if (!is_string($name)
                || !function_exists('theme_section_name_is_valid')
                || !theme_section_name_is_valid($name)
                || !is_array($section)
                || array_keys($section) !== ['html', 'fallback', 'sha256']
                || !is_string($section['html'])
                || trim($section['html']) === ''
                || strlen($section['html']) > 1024 * 1024
                || str_contains($section['html'], '<?')
                || str_contains($section['html'], '[[widget:')
                || preg_match('/\A[a-f0-9]{64}\z/', (string)$section['sha256']) !== 1
                || !hash_equals(hash('sha256', $section['html']), (string)$section['sha256'])
                || !is_array($section['fallback'])
                || array_keys($section['fallback']) !== ['title', 'summary', 'url', 'link_label']) {
                return null;
            }
            foreach ($section['fallback'] as $value) {
                if (!is_string($value)) return null;
            }
            if (trim($section['fallback']['title']) === '' || trim($section['fallback']['summary']) === '') return null;
            if ($section['fallback']['url'] !== '' && function_exists('theme_section_safe_url')
                && theme_section_safe_url($section['fallback']['url']) === '') {
                return null;
            }
        }

        $combined = implode('', array_column($package['sections'], 'html'));
        return hash_equals(hash('sha256', $combined), (string)$package['source_sha256']) ? $package : null;
    }

    function ct_theme_section_source_fallback(string $name, array $attrs, array $definition): array {
        $defaults = is_array($definition['defaults'] ?? null) ? $definition['defaults'] : [];
        $values = array_merge($defaults, $attrs);
        foreach (['title', 'summary', 'url', 'link_label', 'text', 'cta_label'] as $key) {
            if (isset($values[$key]) && is_string($values[$key])) {
                $values[$key] = html_entity_decode($values[$key], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            }
        }
        $url = (string)($values['url'] ?? '');
        if (function_exists('theme_section_safe_url')) $url = theme_section_safe_url($url);
        return [
            'title' => (string)($values['title'] ?? $definition['label'] ?? (function_exists('theme_section_default_label') ? theme_section_default_label($name) : $name)),
            'summary' => (string)($values['summary'] ?? $values['text'] ?? $definition['description'] ?? ''),
            'url' => $url,
            'link_label' => (string)($values['link_label'] ?? $values['cta_label'] ?? ''),
        ];
    }

    function ct_theme_section_composition_fingerprint(string $themeFolder, array $sections): string {
        $identity = [];
        foreach ($sections as $section) {
            $attrs = (array)$section['attrs'];
            ksort($attrs, SORT_STRING);
            $identity[] = [
                'name' => (string)$section['name'],
                'attrs' => $attrs,
                'source_fingerprint' => (string)$section['source_fingerprint'],
            ];
        }
        $json = json_encode([
            'composition' => 'theme-sections-v1',
            'theme_folder' => $themeFolder,
            'sections' => $identity,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        return hash('sha256', $json);
    }

    function ct_theme_section_source_resource(PDO $pdo, array $post, bool $renderPreviews = true): ?array {
        if (($post['type'] ?? '') !== 'theme') return null;
        $composition = ct_parse_theme_section_composition((string)($post['content'] ?? ''));
        if ($composition === null
            || !function_exists('theme_section_definitions')
            || !function_exists('theme_section_source_descriptor')
            || !function_exists('theme_section_source_fingerprint')
            || !function_exists('render_theme_section')
            || !function_exists('get_active_theme_folder')) {
            return null;
        }
        $definitions = theme_section_definitions();
        $sections = [];
        foreach ($composition as $item) {
            $name = (string)$item['name'];
            if (!array_key_exists($name, $definitions)) return null;
            $definition = (array)$definitions[$name];
            $descriptor = theme_section_source_descriptor($name, $pdo);
            $fingerprint = theme_section_source_fingerprint($name, $pdo);
            if ($descriptor === [] || preg_match('/\A[a-f0-9]{64}\z/', $fingerprint) !== 1) return null;
            $attrs = (array)$item['attrs'];
            $renderAttrs = $attrs;
            unset($renderAttrs['name']);
            $sections[] = [
                'name' => $name,
                'attrs' => $attrs,
                'definition' => $definition,
                'source_descriptor' => $descriptor,
                'source_fingerprint' => $fingerprint,
                'source_html' => $renderPreviews ? render_theme_section($name, $renderAttrs, $pdo, [
                    'post' => $post,
                    'ct_theme_section_editor' => 'source',
                ]) : '',
                'fallback' => ct_theme_section_source_fallback($name, $attrs, $definition),
            ];
        }
        $folder = (string)get_active_theme_folder($pdo);
        if (preg_match('/\A[a-z0-9][a-z0-9_-]*\z/i', $folder) !== 1) return null;
        return [
            'post' => $post,
            'theme_folder' => $folder,
            'sections' => $sections,
            'source_fingerprint' => ct_theme_section_composition_fingerprint($folder, $sections),
        ];
    }

    function ct_build_theme_section_package(string $themeFolder, array $sourceSections, array $submitted, ?array $existingPackage = null): array {
        if (preg_match('/\A[a-z0-9][a-z0-9_-]*\z/i', $themeFolder) !== 1
            || count($sourceSections) !== count($submitted)
            || $submitted === []) {
            throw new InvalidArgumentException('Theme Section package identity is invalid.');
        }
        if ($existingPackage !== null) {
            try {
                $existingEncoded = json_encode($existingPackage, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            } catch (JsonException $e) {
                throw new InvalidArgumentException('Existing Theme Section package is invalid.');
            }
            $existingPackage = ct_decode_theme_section_package($existingEncoded);
            if ($existingPackage === null) throw new InvalidArgumentException('Existing Theme Section package is invalid.');
            $sourceNames = array_map(static fn(array $section): string => (string)($section['name'] ?? ''), array_values($sourceSections));
            if ((string)$existingPackage['theme_folder'] !== $themeFolder
                || array_keys((array)$existingPackage['sections']) !== $sourceNames) {
                throw new InvalidArgumentException('Existing Theme Section package identity or order is invalid.');
            }
        }
        $sections = [];
        foreach (array_values($sourceSections) as $index => $source) {
            $input = $submitted[$index] ?? null;
            $name = (string)($source['name'] ?? '');
            if (!is_array($input) || (string)($input['name'] ?? '') !== $name || isset($sections[$name])) {
                throw new InvalidArgumentException('Theme Section identity or order changed. Reload the editor.');
            }
            $html = $input['html'] ?? null;
            if (!is_string($html)) throw new InvalidArgumentException('Section HTML must be text.');
            $htmlError = ct_validate_theme_section_html($html);
            $legacyHtml = $existingPackage['sections'][$name]['html'] ?? null;
            if ($htmlError !== null && (!is_string($legacyHtml) || $html !== $legacyHtml)) {
                throw new InvalidArgumentException($name . ': ' . $htmlError);
            }
            $fallback = [];
            foreach (['title', 'summary', 'url', 'link_label'] as $field) {
                if (!isset($input[$field]) || !is_string($input[$field])) {
                    throw new InvalidArgumentException('Every semantic fallback field must be text.');
                }
                $fallback[$field] = $input[$field];
            }
            if (trim($fallback['title']) === '' || trim($fallback['summary']) === '') {
                throw new InvalidArgumentException($name . ': title and summary are required.');
            }
            $legacyFallback = is_array($existingPackage) ? ($existingPackage['sections'][$name]['fallback'] ?? []) : [];
            foreach (['title' => 1000, 'summary' => 20000, 'url' => 2048, 'link_label' => 1000] as $field => $limit) {
                if (strlen($fallback[$field]) > $limit && (!is_array($legacyFallback) || ($legacyFallback[$field] ?? null) !== $fallback[$field])) {
                    throw new InvalidArgumentException($name . ': a semantic fallback field is too long.');
                }
            }
            if (!ct_theme_section_url_is_safe($fallback['url'])
                || ($fallback['url'] !== '' && function_exists('theme_section_safe_url') && theme_section_safe_url($fallback['url']) === '')) {
                if (is_array($legacyFallback) && ($legacyFallback['url'] ?? null) === $fallback['url']) {
                    // Preserve hash-valid v1 package bytes that predate the stricter editor write policy.
                } else {
                    throw new InvalidArgumentException($name . ': fallback URL is unsafe.');
                }
            }
            $sections[$name] = [
                'html' => $html,
                'fallback' => $fallback,
                'sha256' => hash('sha256', $html),
            ];
        }
        $package = [
            'format' => ct_theme_section_package_format(),
            'theme_folder' => $themeFolder,
            'composition' => 'theme-sections-v1',
            'source_sha256' => hash('sha256', implode('', array_column($sections, 'html'))),
            'sections' => $sections,
        ];
        $encoded = json_encode($package, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        if (strlen($encoded) > 2 * 1024 * 1024) throw new InvalidArgumentException('Theme Section package is too large.');
        return $package;
    }

    function ct_translation_row_state_token(?array $row): string {
        if ($row === null) return hash('sha256', 'ct-translation-row:missing');
        $state = [];
        foreach (['id', 'post_id', 'locale', 'title', 'slug', 'content', 'meta_description', 'status', 'created_at', 'updated_at'] as $key) {
            $state[$key] = $row[$key] ?? null;
        }
        return hash('sha256', json_encode($state, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    function ct_theme_section_saved_source_fingerprint(PDO $pdo, int $postId, string $locale): ?string {
        ct_ensure_schema($pdo);
        $stmt = $pdo->prepare('SELECT source_fingerprint FROM ct_theme_section_translation_meta WHERE post_id = ? AND locale = ? LIMIT 1');
        $stmt->execute([$postId, $locale]);
        $fingerprint = $stmt->fetchColumn();
        return is_string($fingerprint) && preg_match('/\A[a-f0-9]{64}\z/', $fingerprint) === 1 ? $fingerprint : null;
    }

    function ct_theme_section_source_state(?string $savedFingerprint, string $currentFingerprint): string {
        if ($savedFingerprint === null || $savedFingerprint === '') return 'unverified';
        return hash_equals($savedFingerprint, $currentFingerprint) ? 'current' : 'stale';
    }

    function ct_assert_theme_section_editor_state(
        string $loadedSourceFingerprint,
        string $currentSourceFingerprint,
        string $loadedTranslationState,
        ?array $currentTranslation
    ): void {
        if (!hash_equals($loadedSourceFingerprint, $currentSourceFingerprint)) {
            throw new RuntimeException('The source Theme Template changed. Reload before saving.');
        }
        if (!hash_equals($loadedTranslationState, ct_translation_row_state_token($currentTranslation))) {
            throw new RuntimeException('This translation was changed by another editor. Reload before saving.');
        }
    }

    function ct_save_theme_section_package_translation(
        PDO $pdo,
        int $postId,
        string $locale,
        string $loadedSourceFingerprint,
        string $loadedTranslationState,
        array $data,
        array $submittedSections
    ): array {
        if (!in_array($locale, ct_enabled_locales($pdo), true)) throw new InvalidArgumentException('Locale not enabled.');
        if (preg_match('/\A[a-f0-9]{64}\z/', $loadedSourceFingerprint) !== 1
            || preg_match('/\A[a-f0-9]{64}\z/', $loadedTranslationState) !== 1) {
            throw new InvalidArgumentException('Editor lock state is invalid. Reload the editor.');
        }
        ct_ensure_schema($pdo);
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) $pdo->beginTransaction();
        try {
            $postStmt = $pdo->prepare("SELECT id, type, title, slug, content, status FROM posts WHERE id = ? AND is_deleted = 0 LIMIT 1 FOR UPDATE");
            $postStmt->execute([$postId]);
            $post = $postStmt->fetch(PDO::FETCH_ASSOC);
            if (!$post) throw new RuntimeException('Source Theme Template no longer exists.');
            $source = ct_theme_section_source_resource($pdo, $post);
            if ($source === null) throw new RuntimeException('Source Theme Template composition is no longer valid.');
            $translationStmt = $pdo->prepare('SELECT * FROM post_translations WHERE post_id = ? AND locale = ? LIMIT 1 FOR UPDATE');
            $translationStmt->execute([$postId, $locale]);
            $existing = $translationStmt->fetch(PDO::FETCH_ASSOC) ?: null;
            ct_assert_theme_section_editor_state(
                $loadedSourceFingerprint,
                (string)$source['source_fingerprint'],
                $loadedTranslationState,
                $existing
            );
            $existingPackage = $existing ? ct_decode_theme_section_package((string)$existing['content']) : null;
            $sourceNames = array_map(static fn(array $section): string => (string)$section['name'], (array)$source['sections']);
            if ($existingPackage !== null && array_keys((array)$existingPackage['sections']) !== $sourceNames) {
                throw new RuntimeException('The existing package identity or order no longer matches the source Theme Template.');
            }
            $themeFolder = (string)$source['theme_folder'];
            if ($existingPackage !== null && (string)$existingPackage['theme_folder'] !== $themeFolder) {
                throw new RuntimeException('The existing package belongs to a different theme and cannot be preserved by this editor.');
            }
            $package = ct_build_theme_section_package(
                $themeFolder,
                (array)$source['sections'],
                array_values($submittedSections),
                $existingPackage
            );
            $encoded = json_encode($package, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            $candidate = $data;
            $candidate['content'] = $encoded;
            $candidate['post_id'] = $postId;
            $candidate['locale'] = $locale;
            if (($candidate['status'] ?? '') === 'published' && !ct_post_translation_is_complete($pdo, $candidate)) {
                throw new InvalidArgumentException('Published Theme Section translations require complete page metadata.');
            }
            if (!ct_save_translation($pdo, $postId, $locale, $candidate)) throw new RuntimeException('Translation save failed.');
            $meta = $pdo->prepare('INSERT INTO ct_theme_section_translation_meta (post_id, locale, source_fingerprint) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE source_fingerprint = VALUES(source_fingerprint)');
            if (!$meta->execute([$postId, $locale, (string)$source['source_fingerprint']])) throw new RuntimeException('Source verification metadata save failed.');
            if ($ownsTransaction) $pdo->commit();
            return ['package' => $package, 'source_fingerprint' => (string)$source['source_fingerprint']];
        } catch (Throwable $e) {
            if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    function ct_theme_section_shortcode_value(string $value): string {
        $value = trim((string)(preg_replace('/\s+/u', ' ', $value) ?? $value));
        return str_replace(['&', '"'], ['&amp;', '&quot;'], $value);
    }

    function ct_theme_section_package_composition(array $package): string {
        $lines = [];
        foreach ($package['sections'] as $name => $section) {
            $fallback = $section['fallback'];
            $attrs = ['name' => $name];
            foreach (['title', 'summary', 'url', 'link_label'] as $key) {
                if ((string)$fallback[$key] !== '') $attrs[$key] = (string)$fallback[$key];
            }
            $serialized = [];
            foreach ($attrs as $key => $value) {
                $serialized[] = $key . '="' . ct_theme_section_shortcode_value($value) . '"';
            }
            $lines[] = '[[widget:theme_section ' . implode(' ', $serialized) . ']]';
        }
        return implode("\n", $lines);
    }

    function ct_apply_theme_section_package(array $post): array {
        if (empty($post['ct_locale'])) return $post;
        $package = ct_decode_theme_section_package((string)($post['content'] ?? ''));
        if ($package === null) return $post;
        $post['ct_theme_section_package'] = $package;
        $post['content'] = ct_theme_section_package_composition($package);
        $GLOBALS['ct_current_post'] = $post;
        return $post;
    }

    function ct_is_homepage_post(PDO $pdo, int $postId): bool {
        if ($postId <= 0) return false;
        $homepage = ct_homepage_theme_post($pdo);
        return is_array($homepage) && (int)($homepage['id'] ?? 0) === $postId;
    }

    function ct_homepage_sitemap_paths(PDO $pdo): array {
        $paths = ['sitemap_homepage.xml'];
        $raw = function_exists('settings_get') ? settings_get($pdo, 'content_translation_homepage_sitemap_aliases', '') : '';
        $aliases = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
        if (is_array($aliases)) {
            foreach ($aliases as $alias) {
                $alias = trim((string)$alias, '/');
                if (preg_match('/\Asitemap_[a-z0-9_-]+\.xml\z/i', $alias) === 1 && !in_array($alias, $paths, true)) {
                    $paths[] = $alias;
                }
            }
        }
        return $paths;
    }
}

add_filter('theme_post_data', function ($post) {
    return is_array($post) ? ct_apply_theme_section_package($post) : $post;
}, 20, 1);

add_filter('theme_slot_post_data', function ($post) {
    return is_array($post) ? ct_apply_theme_section_package($post) : $post;
}, 20, 1);

add_filter('content_translation_post_translation_is_complete', function ($complete, $translation, $pdo) {
    if (!$pdo instanceof PDO || !is_array($translation)) return $complete;
    $package = ct_decode_theme_section_package((string)($translation['content'] ?? ''));
    if ($package === null) return $complete;
    $postId = (int)($translation['post_id'] ?? 0);
    $homepage = ct_is_homepage_post($pdo, $postId);
    $identityComplete = ($translation['status'] ?? 'published') === 'published'
        && trim((string)($translation['title'] ?? '')) !== ''
        && trim((string)($translation['meta_description'] ?? '')) !== ''
        && ($homepage || trim((string)($translation['slug'] ?? '')) !== '');
    return $identityComplete;
}, 20, 3);

add_filter('content_translation_slug_is_reserved', function ($reserved, $postId, $locale, $slug, $pdo) {
    if (!$pdo instanceof PDO || !function_exists('content_route_find_canonical')) return $reserved;
    try {
        $route = content_route_find_canonical($pdo, (int)$postId, (string)$locale);
    } catch (Throwable $e) {
        return $reserved;
    }
    return is_array($route) && trim((string)$route['path'], '/') === trim((string)$slug, '/') ? false : $reserved;
}, 20, 6);

add_filter('theme_section_attrs', function ($attrs, $name, $definition, $context) {
    if (!is_array($attrs) || !is_array($context)) return $attrs;
    foreach (['title', 'summary', 'url', 'link_label'] as $key) {
        if (isset($attrs[$key]) && is_string($attrs[$key])) {
            $attrs[$key] = html_entity_decode($attrs[$key], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
    }
    $post = $context['post'] ?? null;
    $package = is_array($post) ? ($post['ct_theme_section_package'] ?? null) : null;
    $fallback = is_array($package) ? ($package['sections'][(string)$name]['fallback'] ?? null) : null;
    if (!is_array($fallback)) return $attrs;
    foreach ($fallback as $key => $value) {
        if ($value !== '' || !array_key_exists($key, $attrs)) $attrs[$key] = $value;
    }
    return $attrs;
}, 20, 4);

add_filter('theme_section_html', function ($html, $name, $attrs, $context, $pdo, $layout) {
    if (!$pdo instanceof PDO || !is_array($context) || !is_string($layout)) return $html;
    $post = $context['post'] ?? null;
    $package = is_array($post) ? ($post['ct_theme_section_package'] ?? null) : null;
    if (!is_array($package) || !function_exists('get_active_theme_folder')) return $html;
    $owner = (string)$package['theme_folder'];
    if (get_active_theme_folder($pdo) !== $owner || !function_exists('theme_section_theme_directory')) return $html;
    $sectionRoot = theme_section_theme_directory($pdo, false, $owner);
    $layoutPath = realpath($layout);
    if (!$sectionRoot || !$layoutPath || !theme_section_path_is_within($layoutPath, $sectionRoot)) return $html;
    $translated = $package['sections'][(string)$name]['html'] ?? null;
    return is_string($translated) && trim($translated) !== '' ? $translated : $html;
}, 20, 6);

add_filter('content_permalink', function ($url, $post) {
    $pdo = $GLOBALS['pdo'] ?? null;
    if (!$pdo instanceof PDO || !is_array($post) || !ct_is_homepage_post($pdo, (int)($post['id'] ?? 0))) return $url;
    $locale = $GLOBALS['ct_request_locale'] ?? null;
    return $locale ? ct_homepage_url((string)$locale) : '/';
}, 5, 2);

add_action('init', function (): void {
    $pdo = $GLOBALS['pdo'] ?? null;
    if (!$pdo instanceof PDO) return;
    $path = trim((string)(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? ''), '/');
    $homepage = ct_homepage_theme_post($pdo);
    if (!$homepage || $path === '' || $path !== trim((string)($homepage['slug'] ?? ''), '/')) return;
    if (function_exists('redirect')) redirect('/', 301);
    header('Location: /', true, 301);
    exit;
}, 19);

add_filter('sitemap_index_entries', function ($entries, $pdo, $domain) {
    if (!is_array($entries) || !$pdo instanceof PDO) return $entries;
    $homepage = ct_homepage_theme_post($pdo);
    if (!$homepage) return $entries;
    foreach (ct_enabled_locales($pdo) as $locale) {
        if (!ct_get_published_translation($pdo, (int)$homepage['id'], $locale)) return $entries;
    }
    $entries[] = ['loc' => rtrim((string)$domain, '/') . '/sitemap_homepage.xml'];
    return $entries;
}, 20, 3);

add_action('init', function (): void {
    $pdo = $GLOBALS['pdo'] ?? null;
    if (!$pdo instanceof PDO) return;
    $path = trim((string)(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? ''), '/');
    if (!in_array($path, ct_homepage_sitemap_paths($pdo), true)) return;
    $homepage = ct_homepage_theme_post($pdo);
    if (!$homepage) return;

    $base = rtrim(ct_base_url(), '/');
    $urls = [['loc' => $base . '/', 'lastmod' => (string)($homepage['updated_at'] ?? $homepage['created_at'] ?? '')]];
    foreach (ct_enabled_locales($pdo) as $locale) {
        $translation = ct_get_published_translation($pdo, (int)$homepage['id'], $locale);
        if (!$translation) continue;
        $urls[] = [
            'loc' => $base . ct_homepage_url($locale),
            'lastmod' => (string)($translation['updated_at'] ?? $homepage['updated_at'] ?? ''),
        ];
    }

    header('Content-Type: application/xml; charset=utf-8');
    header('Cache-Control: public, max-age=3600');
    echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
    foreach ($urls as $url) {
        echo '  <url><loc>' . htmlspecialchars((string)$url['loc'], ENT_XML1) . '</loc>';
        if ($url['lastmod'] !== '') echo '<lastmod>' . htmlspecialchars(date('c', strtotime((string)$url['lastmod'])), ENT_XML1) . '</lastmod>';
        echo '</url>' . "\n";
    }
    echo '</urlset>';
    exit;
}, 18);
