# Changelog

## 1.13.0 - 2026-09-02

- Add reviewed Theme Zone text translations with explicit widget schemas, source fingerprints, and optimistic editor state.
- Add bounded physical PHP discovery and reviewed translations for theme-owned UI strings.
- Integrate locale-safe Theme Builder return navigation and generic Core URL contracts.
- Export and uninstall both new plugin-owned resource types.
- Add per-author writing-language defaults to Translation Settings.
- Keep the default locale canonical in `posts`, store alternate-language authoring as a translation, and track source publication independently.
- Offer the site default and other enabled languages as translation targets while excluding the authored source language.
- Route alternate-locale authored posts to their locale URL until a reviewed default-language translation is published.
- Use a published default-language translation for unprefixed URLs, collections, search, hreflang links, and language switching.
- Reject translated slug conflicts, quarantine failed source synchronization as draft, and block unsafe locale or plugin removal while alternate-language sources remain.
- Display published post titles, links, and editor actions in each dashboard user's configured writing locale.

## 1.12.2 - 2026-08-20

- Honor host-provided locales for search forms, translated-field matching, result overlays, pagination, and canonical URLs.
- Align workspace routes and translation mutations with Core's dynamic permission policy.

## 1.12.1 - 2026-08-13

- Initialize assigned homepage context for localized homepage switching and metadata.
- Keep localized post-category labels and URLs from replacing the current post's document metadata.

## 1.12.0 - 2026-08-11

- Start Shortcode Preset translations from the Core source editor and retain the standalone page as a status overview.
- Add per-Theme-Template locale controls to the Core Theme Section renderer editor.
- Focus the selected section in the package editor and return to its source renderer.
- Load active-theme styles in sandboxed source and translated section previews.
- Keep preview scripts disabled while allowing same-origin theme fonts and removing script elements from preview documents.
- Remove dormant directory-page routing that is outside the supported plugin scope.

## 1.11.0 - 2026-08-11

- Harden Shortcode Preset kicker mutation, source concurrency checks, and schema failure handling.
- Apply localized kickers through Core's render-time contract and live-preview extension point.
- Make preset translation deletion atomic with source deletion and add legacy orphan repair.
- Display translated management titles and register Indonesian and German admin strings.
- Preserve automatic, hidden, and custom source heading states across editor saves and previews.
- Track plugin-owned UI seeds for safe updates and uninstall, and use accessible NewNotif confirmation.

## 1.10.0 - 2026-08-11

- Add reviewed Theme Section package translation management.
