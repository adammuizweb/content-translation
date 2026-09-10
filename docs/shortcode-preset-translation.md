# Shortcode Preset Translation

Content Translation `1.14.0` requires Jyavani Core `2.3.108` or newer. Core provides the
Shortcode Preset lifecycle, render-time, and preview contracts.

Content Translation provides an admin-only translation resource for Core
Shortcode Presets. The primary workflow starts at Core's **Shortcode Presets**
list: open the source preset, then choose a locale beside its heading settings.
The translation editor returns to that source preset. **Tools / Content
Translation / Shortcodes** remains available only as a status overview.

The plugin also adds an admin-only **Source heading behavior** control to the
Core preset editor. It maps
Core's three distinct states without collapsing them:

- **Automatic category heading** removes the `kicker` key, allowing Core to use
  the selected category name when available.
- **Hidden** persists an explicit empty `kicker` and suppresses the heading.
- **Custom heading** persists nonempty validated text.

The initial mode comes from the raw persisted preset configuration. Server-side
save filtering accepts only one of the visible modes from an admin and preserves
the exact persisted state on author/editor requests, forged form submissions,
and existing admin saves where the plugin mode was not submitted. Save a newly
created preset before adding translations.

## Data contract

Translations are stored in the plugin-owned `shortcode_preset_translations`
table. Each `preset_id` and `locale` pair is unique. A row contains:

- A translated management title used to identify the translation in admin.
- `draft` or `published` review status.
- A controlled JSON object containing only the localized `kicker` text.
- Creation and update timestamps used as part of optimistic translation state.

The management title does not replace a fetched Post or Page title. Those
records continue to use their existing Post/Page translation resource. If a
preset has no explicitly configured source kicker, the editor explains that
relationship and still permits a localized kicker when the localized
presentation needs a heading.

## Runtime contract

Core calls `shortcode_preset_runtime_config` for each render after locale routing.
On a request carrying `ct_request_locale`, the plugin loads only a complete,
published translation for that preset and locale. A nonempty localized `kicker`
replaces the runtime kicker. An empty value keeps Core's source heading behavior.

No JSON key other than `kicker` is accepted or copied. In particular, a
translation cannot change source, post type, category, author, limit, offset,
ordering, date range, layout, class prefix, wrapper, or extension-owned unknown
configuration. The Core config save filter changes only the optional source
kicker, discards forged legacy markers, and preserves all unknown preset config.

Core's `shortcode-preset-preview-config` editor event writes the selected live
state into the preview request. The `shortcode_preset_preview_config` filter
keeps explicit-empty and custom values distinct and renders Core's automatic
category-heading fallback for a missing key. All three modes therefore appear
in the real-data preview before the preset is saved.

## Validation and concurrency

The translated management title is limited to 191 characters and is required
for publication. The localized kicker is limited to 255 characters. Status is
restricted to `draft` or `published`, non-scalar form input and unsupported
control characters are rejected, and stored override JSON is bounded and
strictly decoded.

The mutation endpoint requires POST, an explicit admin role check, and a valid
Core CSRF token. The editor submits one SHA-256 token covering meaningful source
state and one covering the complete loaded translation row. Save and delete
start a transaction, lock the live `sc_preset` source and locale row, and reject
either stale token before mutation. Changes commit atomically or roll back.

If the optional translation table cannot be created or queried, runtime catches
the storage error and keeps the source preset configuration. Administration
shows a storage warning and mutations fail rather than publishing unverified
translation state.

When Core moves a preset to trash, its failure-propagating
`admin_shortcode_preset_before_delete` hook removes all related plugin
translation rows inside the source transaction.
Any failure rolls back both source and translations. Exports include only rows
whose source preset is live, and the Shortcode Preset translation list offers an
admin-only repair control for legacy orphans. Content Translation export format
version 4 includes `shortcode_preset_translations`, and uninstall removes the
table.

Indonesian and German UI values are maintained in a source-extracted seed
catalog. A plugin-owned ledger records only rows inserted by this plugin.
Updates replace a seed only while its live value still equals the plugin's
previous value, so administrator edits and pre-existing Core rows are retained.
Uninstall removes only ledger-owned rows whose values remain unchanged, then
removes the ledger itself.
