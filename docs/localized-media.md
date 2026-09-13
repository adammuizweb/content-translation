# Localized Media

Localized media is available in Content Translation 1.14.0. The focused picker workflow and localized URL aliases require Jyavani Core 2.3.116 or newer. Its generic Core media extension contract provides contextual media admin hooks, mutation metadata, resource lifecycle events, `media_data`, `featured_media`, and stable featured-media IDs.

## Metadata model

- Core `media.title`, `alt`, `caption`, and `credit` remain the asset's original metadata. They are not assumed to be English or the site default language.
- `metadata_source_locale` records the language in which that original metadata was authored, independent of the site default language.
- New media profiles uploaded from a Content Translation picker use that editor's content locale as the original metadata language. Standalone Media Library uploads continue to default to Core's Content Default Language.
- Availability is either `all` locales or an explicit non-empty locale set.
- The all-locales policy displays every locale checked and disabled to reflect effective availability. Choosing selected locales enables the controls for an explicit subset.
- Alternate metadata is reviewed independently as draft or published and is overlaid only when its source fingerprint is current.
- Profile availability and each locale translation carry optimistic editor tokens. Saves lock those rows and reject stale submissions.
- Reclassifying the source language requires grants for both the old and new source locale. A locale with an existing translation cannot become the source until that translation is deleted in a separate save.
- A nullable translated field inherits the original value. For alt text, `inherit`, translated `text`, and intentionally empty `decorative` are distinct states.
- Private, trashed, deleted, and locale-incompatible assets are never rendered as localized featured media.
- Picker data reports `ready`, `source_fallback`, `draft`, `stale`, or `unavailable`, with a localized badge label and human-readable source/target language names.
- A contextual editor may assign one optional URL slug per language. These aliases resolve to the same stable `media.id` and redirect to the existing public file; they never rename or duplicate physical media.

## Featured media

Each post or page translation may inherit the source thumbnail, select a stable media ID, or suppress the thumbnail. Optional alt and caption values are use-site overrides, including an explicitly empty override.

Incompatible media may be retained while the translation is a draft. Publishing validates the selection again under the same transaction locks as the text translation. Text and featured selection share one optimistic editor state and commit or roll back together. Deleting a translation removes its selection in the same transaction.

The Core picker is opened with `selection_mode=review` and exposes locale diagnostics to the editor. An incompatible featured selection is warned immediately, and an incompatible inline image is not inserted. Inline images retain a stable `data-media-id` when Core returns an ID. Article pickers identify their consumer as `post`; Page pickers use `page`.

Core's YouTube thumbnail remains first in display-image precedence. Localized featured selection controls only the featured-media branch used when no valid YouTube thumbnail is available.

## Lifecycle and export

Trash retains localized media data and restore resumes it. Permanent purge is blocked while a published localized representation actively selects the media; otherwise safe draft/orphan selections and all plugin-owned media metadata are removed in the Core purge transaction.

The standalone Media Library keeps full source-language reclassification and availability controls. From a Content Translation picker, the metadata pane is locked to the validated content locale, names that language, shows the original metadata language read-only, and omits source reclassification and broad availability controls. One physical file serves all languages; only metadata is localized, and files are not renamed.

An alternate media translation can be marked for deletion in that shared panel. The deletion is checked against its optimistic token and is applied only when the CSRF-protected Core metadata form is saved; Core metadata is never deleted.

Core's `media_mutation_response` filter is used to append refreshed profile and active-translation optimistic tokens to the refreshed `media` response. The detail form consumes those tokens from `media:updated`, allowing repeated saves without accepting stale writes. This requires the upcoming Core response/filter contract described above; persistence remains in Core's existing transaction and authorization lifecycle.

Export format version 8 contains media profiles, selected availability locales, media translations, localized media aliases, localized featured selections, and diagnostic Core media identity. No importer is provided.

Localized-media hooks and post columns remain inactive when the Core media contract is unavailable. The manifest requires Jyavani Core 2.3.116 or newer.
