# Localized Media

Localized media is available in Content Translation 1.14.0 and requires Jyavani Core 2.3.108 or newer. Its generic Core media extension contract provides contextual media admin hooks, mutation metadata, resource lifecycle events, `media_data`, `featured_media`, and stable featured-media IDs.

## Metadata model

- Core `media.title`, `alt`, `caption`, and `credit` remain the asset's original metadata. They are not assumed to be English or the site default language.
- `metadata_source_locale` records the language in which that original metadata was authored, independent of the site default language.
- Availability is either `all` locales or an explicit non-empty locale set.
- Checking an individual locale in the media editor selects the explicit-locale policy automatically, so the visible checks and persisted policy cannot silently disagree.
- Alternate metadata is reviewed independently as draft or published and is overlaid only when its source fingerprint is current.
- Profile availability and each locale translation carry optimistic editor tokens. Saves lock those rows and reject stale submissions.
- Reclassifying the source language requires grants for both the old and new source locale. A locale with an existing translation cannot become the source until that translation is deleted in a separate save.
- A nullable translated field inherits the original value. For alt text, `inherit`, translated `text`, and intentionally empty `decorative` are distinct states.
- Private, trashed, deleted, and locale-incompatible assets are never rendered as localized featured media.

## Featured media

Each post or page translation may inherit the source thumbnail, select a stable media ID, or suppress the thumbnail. Optional alt and caption values are use-site overrides, including an explicitly empty override.

Incompatible media may be retained while the translation is a draft. Publishing validates the selection again under the same transaction locks as the text translation. Text and featured selection share one optimistic editor state and commit or roll back together. Deleting a translation removes its selection in the same transaction.

The Core picker exposes the locale-availability diagnostic to the editor, so an incompatible draft selection is warned immediately. Article pickers identify their consumer as `post`; Page pickers use `page`.

Core's YouTube thumbnail remains first in display-image precedence. Localized featured selection controls only the featured-media branch used when no valid YouTube thumbnail is available.

## Lifecycle and export

Trash retains localized media data and restore resumes it. Permanent purge is blocked while a published localized representation actively selects the media; otherwise safe draft/orphan selections and all plugin-owned media metadata are removed in the Core purge transaction.

The media detail panel uses one metadata form for the original and alternate languages. Changing its language selector replaces the title, alt, caption, and credit controls in place; alternate inheritance and status controls appear only for a translation. Save before switching to and editing another translation.

An alternate media translation can be marked for deletion in that shared panel. The deletion is checked against its optimistic token and is applied only when the CSRF-protected Core metadata form is saved; Core metadata is never deleted.

Export format version 7 contains media profiles, selected availability locales, media translations, localized featured selections, and diagnostic Core media identity. No importer is provided.

Localized-media hooks and post columns remain inactive when the Core media contract is unavailable. The manifest also requires Jyavani Core 2.3.108 or newer.
