# Changelog

## 1.14.3 - 2026-09-10

- Default unprofiled media to Core's Content Default Language while retaining the picker locale only as the active metadata view.

## 1.14.2 - 2026-09-10

- Represent all-locale media availability with checked disabled controls while keeping explicit locale subsets editable.
- Preview inherited source thumbnails in translated content without emitting empty image requests.
- Keep translated slug fields valid under current browser regular-expression semantics.

## 1.14.1 - 2026-09-10

- Keep selected media-availability locales persisted by switching the policy automatically when locale checkboxes are used.
- Unify original and alternate media metadata in one language-switched editor.
- Match the Core Quill toolbar and open the canonical contextual media modal from translated post content and featured-media controls.

## 1.14.0 - 2026-09-10

- Add source-language-aware media profiles, reviewed localized metadata, explicit availability, and distinct inherited/text/decorative alt semantics.
- Add atomic locale-specific featured media selection for post and page translations while preserving Core's YouTube-first display policy.
- Integrate localized media with Core mutation and lifecycle transactions, purge protection, default-locale preflight, uninstall, and export format 7.
- Lock and optimistically validate media profile, availability, and translation rows; protect source-language reclassification with old/new locale grants and explicit translation conflict checks.
- Carry picker availability diagnostics into post/page draft UX and add transaction-safe media-translation deletion.
- Require Jyavani Core 2.3.108 and its generic media extension contract.

## 1.13.2 - 2026-09-02

- Separate default writing-language preferences from explicit user and role locale edit grants.
- Keep every Core-readable language representation visible while rendering unassigned locales read-only.
- Record the last translation actor in `post_translations.updated_by` without changing Core ownership.
- Require locale grants for canonical source mutations and block inconsistent ownership changes in active multilingual workflows.

## 1.13.1 - 2026-09-02

- Extend assigned-language author workflows to Pages and localized Theme Template dashboard representation.
- Localize canonical Category labels and links through optional Core adapters while preserving owner-scoped mutations.
- Reauthorize category translations under ordered transaction locks and reject ambiguous localized sibling paths.
- Require translation workspace access before assigning or applying an alternate author writing language.

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
