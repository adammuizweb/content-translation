# Theme Translation

Content Translation supports two separate theme workflows:

- **Theme Partials** translate database-backed posts whose `type` is `theme`.
- **Theme Files** translate selected Theme Customizer values consumed by PHP theme
  files through Core's `theme_mod()` helper.

Both workflows use reviewed draft/published records. The default content locale
and its source values are never modified.

File-backed translation requires Jyavani Core `2.3.49` or newer. Older Core
versions do not expose the normalized resource metadata and slot-aware
`theme_mod_value` hook required by this workflow.

## File-backed contract

A theme opts in through its normalized `theme.json` Customizer declaration. A
section must declare a nonempty `slot`, and each field intended for translation
must explicitly set `translatable` to `true`.

```json
{
  "folder": "example",
  "name": "Example Theme",
  "customizer": {
    "sections": {
      "homepage_hero": {
        "label": "Homepage Hero",
        "slot": "main.homepage",
        "fields": {
          "hero_title": {
            "type": "text",
            "label": "Hero title",
            "translatable": true
          },
          "hero_intro": {
            "type": "textarea",
            "label": "Hero introduction",
            "translatable": true
          },
          "feature_list": {
            "type": "textarea",
            "label": "Feature list JSON",
            "translatable": true,
            "format": "json"
          },
          "hero_image": {
            "type": "image",
            "label": "Hero image"
          },
          "show_hero": {
            "type": "toggle",
            "label": "Show hero"
          }
        }
      },
      "homepage_cta": {
        "label": "Homepage CTA",
        "slot": "main.homepage",
        "fields": {
          "cta_label": {
            "type": "text",
            "label": "CTA label",
            "translatable": true
          },
          "cta_url": {
            "type": "text",
            "label": "CTA URL"
          }
        }
      }
    }
  }
}
```

Sections sharing a slot are merged into one atomic translation resource. In the
example, `hero_title`, `hero_intro`, and `cta_label` must all be nonempty before
`main.homepage` can be published. This prevents a localized render from mixing
translated and source-language values.

Only mark human-readable scalar text as translatable. URLs, images, menu IDs,
sidebar IDs, and toggles should remain unmarked; Core continues to return their
source values. Theme files consume all values normally:

Use `"format": "json"` for translated JSON arrays. A malformed or non-list JSON
value makes the resource incomplete and prevents a published locale from
rendering.

```php
<h1><?= htmlspecialchars((string)theme_mod('hero_title', 'Welcome'), ENT_QUOTES) ?></h1>
<p><?= nl2br(htmlspecialchars((string)theme_mod('hero_intro', ''), ENT_QUOTES)) ?></p>
```

Core supplies the current theme folder and rendering slot to the
`theme_mod_value` hook. The plugin overlays a value only when the request locale
has a complete published row for that exact folder and slot.

Source values shown by the editor come from values saved in Theme Customize.
Fallback defaults passed directly in PHP calls such as
`theme_mod('hero_title', 'Welcome')` cannot be discovered automatically. Save
the source-language Customizer values before reviewing or publishing their
translations.

## File homepage routes

The default content locale uses `/`. A file-backed localized homepage uses
`/{locale}/` only when all of these conditions are true:

- `resolve_template($pdo, 'main.homepage')` resolves to `theme_file`.
- The resolved active-theme folder declares `slot: "main.homepage"` with at
  least one explicitly translatable field.
- That locale has a complete published file translation.

A custom database-backed `theme` post assigned to `main.homepage` remains first
priority. If it is assigned, its existing post translation determines whether
the locale root exists; the file-backed fallback is not used.

The default `/` request is also identified as a file-backed homepage before the
layout renders. This lets the header language switcher and hreflang output work
even when Core serves `public/index.php` without running `router_path`.

Localized file homepages use `/{locale}/` as canonical. A nonempty translated
SEO title and meta description override the document metadata. Hreflang output
contains the default locale, every complete published locale, and `x-default`.

Completeness is enforced per slot resource. Header, footer, and other assigned
slots remain independent and keep their source values until their own locale
resource is published. Publish every visible slot to produce a fully localized
page.

Root search requests such as `/{locale}/?s=query` retain the normal localized
search behavior and do not require a file-backed homepage translation.

## File publishing workflow

1. Add `slot` and explicit `translatable: true` declarations to the active
   theme's `theme.json`.
2. Open **Tools / Content Translation / Theme Files**.
3. Select a locale for the discovered resource.
4. Compare each translation control with its effective source value from the
   theme's saved Customizer values.
5. Save an incomplete translation as draft, or complete every field and publish.

Changing a theme declaration is enforced immediately. If a new translatable
field is added to a resource, an older published row is no longer considered
complete until the new field is translated and saved.

Version 1.7 also requires nonempty translated fields when Core explicitly marks
them as required for a collection. Existing published Page List translations
with an empty title or slug are omitted until those fields are completed.

## Database-backed partials

Database `theme` posts continue to use the manual CodeMirror editor so template
markup, placeholders, scripts, styles, and structural HTML are preserved. A
published direct translation is available at `/{locale}/{slug}/`.

A custom theme post can be assigned to slots such as `header`, `main.homepage`,
or `footer`. On localized requests, a published translation replaces the source
post for that slot. Existing source fallback for assigned partials is unchanged;
explicit localized routes and custom-post homepages still require a published
post translation.

The plugin consumes these generic Core integration points:

- `theme_post_data` adapts a direct database theme post.
- `theme_slot_post_data` adapts a custom database theme post resolved for a slot.
- `theme_editor_before_content` adds the database partial translation picker.
- `theme_mod_value` adapts a declared file-backed Customizer value.

File resources never set `ct_current_post`, so a translated header, footer, or
file homepage cannot be mistaken for a database post by canonical, hreflang, or
language-switcher logic.

## Other translated content

Categories have reviewed translations for name, slug, and description. A
localized category URL exists only after the category and every ancestor in its
path have published translations. Localized category lists include only posts
with a published translation, preserving count and pagination behavior.

Direct posts, pages, database theme posts, category routes, collections,
sitemaps, author archives, and custom-post homepage handling are independent of
file-backed resources and retain their existing routing behavior.

## Language switcher

The plugin registers the Content Translation widget for Theme Customize and
Sidebar settings. To control its placement, use the shortcode in an HTML gadget
or widget area:

```html
<div class="my-language-switcher">
  [[widget:lang_switcher style="pills"]]
</div>
```

Use `style="select"` for a dropdown. On a file-backed homepage, the switcher
shows the default locale and only complete published file translations.
