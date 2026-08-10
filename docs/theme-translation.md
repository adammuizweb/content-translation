# Theme Translation

Content Translation supports three separate theme workflows:

- **Theme Partials** translate database-backed posts whose `type` is `theme`.
- **Theme Section Packages** adapt reviewed translations of Theme Templates
  composed from Core Theme Sections.
- **Theme Files** translate selected Theme Customizer values consumed by PHP theme
  files through Core's `theme_mod()` helper.

All workflows use reviewed draft/published records. The default content locale
and its source values are never modified.

Content Translation `1.9.0` requires Jyavani Core `2.3.55` or newer. Core
`2.3.54` introduced the generic Theme Section renderer and hooks required by the
`ct-theme-sections-v1` adapter; Core `2.3.55` added the canonical content routes
used by localized Theme Templates and their sitemaps. These releases also
include the resource metadata and slot-aware `theme_mod_value` hook used by
file-backed translation.

## Theme Section package contract

`ct-theme-sections-v1` is a plugin-owned translation format stored as the
translated content of a database-backed Theme Template. It does not register
site pages or Theme Sections. Themes or site-support plugins remain responsible
for section definitions, PHP renderers, Theme Template assignments, and routes.

A package has this ordered shape:

```json
{
  "format": "ct-theme-sections-v1",
  "theme_folder": "example",
  "composition": "theme-sections-v1",
  "source_sha256": "<sha256 of all section HTML in order>",
  "sections": {
    "landing.hero": {
      "html": "<section><h1>Localized heading</h1></section>",
      "fallback": {
        "title": "Localized heading",
        "summary": "Localized summary",
        "url": "",
        "link_label": ""
      },
      "sha256": "<sha256 of this section HTML>"
    }
  }
}
```

The adapter validates the exact package shape, theme and section identifiers,
section count and content limits, safe fallback URLs, per-section hashes, and
the aggregate hash. PHP fragments and nested widget shortcodes are rejected.
Section order is preserved when the package is converted to
`[[widget:theme_section ...]]` composition.

Localized HTML can replace a section only when the package's `theme_folder` is
the active theme and Core resolved the PHP renderer from that theme's validated
Theme Section directory. This ownership check prevents translated HTML from one
theme being applied to another theme or to a global/default renderer.

Published package translations require a title and meta description. A slug is
also required except for a Theme Template assigned to `main.homepage`, whose
localized canonical URL is `/{locale}/`. Canonical Core content routes may be
used instead of a translated slug when they resolve to the same post and locale.
Until a package-aware editor is released, packages remain editable through the
existing raw translation content field.

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
