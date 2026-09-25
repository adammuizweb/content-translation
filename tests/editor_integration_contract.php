<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$manifest = json_decode((string)file_get_contents($root . '/plugin.json'), true, 32, JSON_THROW_ON_ERROR);
$editor = (string)file_get_contents($root . '/admin/edit.php');
$save = (string)file_get_contents($root . '/admin/api/save.php');
$failures = [];
$check = static function (bool $condition, string $message) use (&$failures): void {
    echo ($condition ? 'PASS' : 'FAIL') . ' ' . $message . PHP_EOL;
    if (!$condition) $failures[] = $message;
};

$check(($manifest['requires']['jyavani'] ?? '') === '>=2.3.155'
    && ($manifest['dependencies']['js'] ?? null) === ['content-editor'],
    'manifest requires and loads the scoped Core editor contract');
$check(!isset($manifest['requires']['plugins']['jyavani-ai'])
    && str_contains($editor, "plugin_is_active('jyavani-ai')")
    && str_contains($editor, "version_compare((string)JAI_VERSION, '0.4.0', '>=')")
    && str_contains($editor, "user_can(\$pdo, ct_current_user_id(), 'plugin.jyavani-ai.assistant.generate')")
    && str_contains($editor, "'core.posts.publish'")
    && str_contains($editor, "'core.pages.publish'")
    && !str_contains($editor, 'jai_authorize_content_request'),
    'Jyavani AI integration is optional, version-bounded, and endpoint-authorization-gated');
$check(str_contains($editor, "id: 'plugin.content-translation.ai-translate'")
    && str_contains($editor, "operation: 'translate'")
    && str_contains($editor, "'X-CSRF-Token': form.elements.csrf_token.value")
    && str_contains($editor, 'content: <?= json_encode((string)$post[\'content\']')
    && str_contains($editor, 'editor.setContent(data.html, { ifRevision: revision')
    && str_contains($editor, 'enabled: function() { return !aiRequestPending; }')
    && str_contains($editor, "error?.code !== 'EDITOR_LOSSY_MODE_CHANGE'"),
    'AI translation uses the protected Jyavani AI endpoint with one revision-safe request at a time');
$check(str_contains($editor, 'content_editor_render_mount')
    && str_contains($editor, "'mode_name' => 'ct_editor_mode'")
    && str_contains($editor, "window.JyavaniEditor.mount('#ct-content-editor'")
    && str_contains($editor, "document.addEventListener('DOMContentLoaded', initContentTranslationEditor")
    && str_contains($editor, 'request.defaults.pickMedia(request)')
    && str_contains($editor, 'editor.snapshot()')
    && str_contains($editor, 'editor.markSaved(submitted)')
    && !str_contains($editor, 'new Quill(')
    && !str_contains($editor, 'CodeMirror.fromTextArea'),
    'translation body initializes after dependencies and uses only the public scoped Core editor API');
$check(str_contains($save, 'csrf_check')
    && str_contains($save, 'translation_state')
    && str_contains($save, 'ct_user_can_edit_post_locale')
    && str_contains($save, 'ct_sanitize_translation_content'),
    'normal save keeps plugin-owned CSRF, locale authorization, optimistic state, and sanitization');

if ($failures !== []) {
    fwrite(STDERR, count($failures) . " editor integration assertion(s) failed.\n");
    exit(1);
}
echo "Editor integration contract passed.\n";
