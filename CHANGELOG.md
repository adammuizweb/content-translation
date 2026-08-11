# Changelog

## Unreleased

- Start Shortcode Preset translations from the Core source editor and retain the standalone page as a status overview.
- Add per-Theme-Template locale controls to the Core Theme Section renderer editor.
- Focus the selected section in the package editor and return to its source renderer.
- Load active-theme styles in sandboxed source and translated section previews.

## 1.11.0 - 2026-08-11

- Harden Shortcode Preset kicker mutation, source concurrency checks, and schema failure handling.
- Apply localized kickers through Core's render-time contract and live-preview extension point.
- Make preset translation deletion atomic with source deletion and add legacy orphan repair.
- Display translated management titles and register Indonesian and German admin strings.
- Preserve automatic, hidden, and custom source heading states across editor saves and previews.
- Track plugin-owned UI seeds for safe updates and uninstall, and use accessible NewNotif confirmation.

## 1.10.0 - 2026-08-11

- Add reviewed Theme Section package translation management.
