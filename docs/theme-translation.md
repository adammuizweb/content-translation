# Theme Translation

Content Translation supports reviewed translations for `article`, `page`, and
`theme` posts. Theme posts always use the manual CodeMirror editor so template
markup, placeholders, scripts, styles, and structural HTML are preserved.

## Categories

Categories have reviewed translations for name, slug, and description. A
localized category URL exists only after the category and every ancestor in its
path have published translations. Localized category lists include only posts
with a published translation, so their count and pagination remain consistent.

Translate a category from its Core editor through the **Content Translation**
locale picker. The plugin preserves category IDs and hierarchy; it only adapts
display data, URLs, and collection visibility.

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

## Content Translation widget

The plugin registers the Content Translation widget visible in Theme Customize and
Sidebar settings. To control the markup and placement, add this shortcode to a
Theme Customize HTML gadget or Sidebar HTML/widget area:

```html
<div class="my-language-switcher">
  [[widget:lang_switcher style="pills"]]
</div>
```

Use `style="select"` for a dropdown. Regular Menu items are static links, so
place the shortcode in an HTML gadget in the menu/header area rather than as a
menu item.

The widget is owned by this plugin. Core and the Default Theme do not install a
translation gadget or language selector by default.
