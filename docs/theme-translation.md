# Theme Translation

Content Translation supports three separate theme workflows:

- **Theme Partials** translate database-backed posts whose `type` is `theme`.
- **Theme Section Packages** adapt reviewed translations of Theme Templates
  composed from Core Theme Sections.
- **Theme Files** translate selected Theme Customizer values consumed by PHP theme
  files through Core's `theme_mod()` helper.

All workflows use reviewed draft/published records. The default content locale
and its source values are never modified.

Content Translation `1.12.1` requires Jyavani Core `2.3.59` or newer. Core
`2.3.54` introduced the generic Theme Section renderer and hooks required by the
`ct-theme-sections-v1` adapter; Core `2.3.55` added the canonical content routes
used by localized Theme Templates and their sitemaps. Core `2.3.57` supplies the
registered source descriptors and deterministic section fingerprints consumed
by the editor. Core `2.3.58` supplies failure-propagating preset deletion,
render-time preset configuration, and live-preview configuration contracts. The
Core contract also includes the slot-aware `theme_mod_value` hook used by
file-backed translation. Core `2.3.59` adds source-editor integration and
script-free, theme-styled Theme Section previews.

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

The read adapter retains the exact 1.9 contract: it validates the package shape,
theme and section identifiers, section count and content limits, safe fallback
URLs, per-section hashes, and the aggregate hash. PHP fragments and nested
widget shortcodes are rejected. Historically accepted section HTML, including
inline scripts and event attributes, continues to decode for runtime
compatibility.
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
Version 1.10 provides an admin-only package editor at **Tools / Content
Translation / Theme Sections**. Package-composed Theme Templates are listed
there instead of under Theme Partials. The Core Theme Section renderer editor
also shows every Theme Template that uses the current renderer, with direct
per-locale links into the package editor. Those links focus the matching section
and return to the source renderer. Opening the generic translation editor for a
package-composed template redirects to the package editor; templates containing
plain HTML, ordinary shortcodes, or mixed content continue to use the generic
editor.

## Theme Section editor contract

The source Theme Template must contain only whitespace and an ordered sequence
of `[[widget:theme_section ...]]` shortcodes. Every shortcode must have one
valid, unique `name`, syntactically complete attributes, and a currently
registered Core definition. Mixed HTML, another shortcode type, malformed or
duplicate attributes, duplicate section names, PHP markers, and nested widget
content are not treated as a package composition.

For each section the editor obtains Core's registered definition,
`theme_section_source_descriptor()`, and
`theme_section_source_fingerprint()`. It renders the source with the source post
in the render context. Source and translated rendering are displayed in
sandboxed preview frames that load the active theme's declared styles. Theme
JavaScript remains disabled, so the preview is visual rather than interactive.
The translated controls expose semantic title,
summary, URL, and link label fields plus an advanced raw HTML CodeMirror field.
The source section identities and order are read-only.

The page-level translated title, slug, meta description, and draft/published
status remain available. Published packages require title and meta description,
and require a slug except when the source Theme Template is assigned to
`main.homepage`. The localized homepage therefore retains `/{locale}/` and an
empty stored slug.

On save, the browser sends section values but not hashes or package identity.
The server verifies source identity/order, validates each HTML value without
rewriting accepted bytes, calculates each `sha256`, calculates the ordered
aggregate `source_sha256`, and encodes `ct-theme-sections-v1`. Existing valid v1
packages are decoded in place and are never migrated on read. Re-saving an
unchanged package preserves section order, translated HTML bytes, fallbacks,
hashes, and runtime composition semantics.

Translated section HTML is rejected if it contains PHP, nested widget
shortcodes, script/style/iframe/object/embed, form or control elements, event
handlers, `srcdoc`, `formaction`, unsafe URL schemes in URL-bearing attributes,
or dangerous inline CSS such as `expression()`, imports, bindings, and
JavaScript/VBScript/data URLs. Structural HTML, responsive images, SVG icon
markup, data and ARIA attributes, CSS variables, and normal relative/HTTP(S),
mail, and telephone links remain supported. Validation is reject-only: it does
not sanitize or serialize accepted HTML.

An unsafe section from an existing hash-valid v1 package is grandfathered only
when the submitted HTML is byte-for-byte identical to that same named section.
This permits fallback/metadata edits and safe changes elsewhere in the package
without breaking existing runtime output. Any byte change to that unsafe HTML,
or unsafe HTML in a new package, is rejected. The read adapter remains separate
from this write-time policy.

## Source verification and concurrency

The plugin creates `ct_theme_section_translation_meta` idempotently. It stores
the deterministic source-composition fingerprint for each post/locale after a
successful package save. Existing translations without a metadata row show
**Unverified source**, matching fingerprints show **Current**, and changed Core
section definitions/renderers/composition show **Stale source**. There is no
destructive migration. Export format version 3 adds
`theme_section_translation_metadata`; all prior translation arrays retain their
existing shape.

The editor carries the source fingerprint and a hash of the complete
translation row state. A save starts a transaction, locks the source post and
translation row, reparses the live composition, and compares both lock values.
A concurrent translation edit or source change fails before mutation. The
translation package and source metadata are then committed together, or both
are rolled back. Deletion locks and verifies the same translation state before
removing the translation and source metadata atomically. Mutation endpoints
require POST, an admin session through the manifest, and a valid Core CSRF
token; package saves also enforce the admin role in depth.

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
