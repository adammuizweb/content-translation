# Theme Translation

Content Translation supports reviewed translations for `article`, `page`, and
`theme` posts. Theme posts always use the manual CodeMirror editor so template
markup, placeholders, scripts, styles, and structural HTML are preserved.

## Routes

- The default content locale has no URL prefix.
- A published direct theme translation is available at `/{locale}/{slug}/`.
- A locale route with no published translation returns 404. It never silently
  renders the default-language theme at a localized URL.
- `/{locale}/` is a localized homepage only when the `main.homepage` assignment
  is a custom `theme` post with a published translation in that locale.

## Slot assignments

A custom theme post can be assigned to slots such as `header`, `main.homepage`,
or `footer`.

- On a localized request, a published translation replaces the assigned source
  post for that slot.
- If the assigned partial has no translation, its source content remains in use
  so the site layout does not become empty or broken.
- This fallback applies only to assigned partials. Explicit localized routes,
  including localized homepages, require a published translation.

## Core integration

The plugin consumes generic Jyavani Core hooks. Core does not know about this
plugin or about translations:

- `theme_post_data` adapts a direct theme post before rendering.
- `theme_slot_post_data` adapts a custom theme post resolved for a slot.
- `theme_editor_before_content` adds the translation picker to the Theme editor.

`theme_slot_post_data` intentionally does not change the current page metadata.
This prevents a translated header or footer from changing the canonical URL,
hreflang links, or language switcher of the page being viewed.

## Publishing workflow

1. Create or edit a theme post in **Themes / Partials**.
2. Select a locale from **Translations** and translate the title, optional slug,
   and template content in the Content Translation editor.
3. Save the reviewed translation as published.
4. Assign the source theme post to a slot as usual. The translated version is
   selected automatically only for matching localized requests.
