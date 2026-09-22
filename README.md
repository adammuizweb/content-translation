# Content Translation

Content Translation adds reviewed multilingual publishing to
[Jyavani CMS](https://jyavani.com/). It keeps translations separate from the
canonical content, serves alternate locales from prefixed URLs such as `/id/`
and `/de/`, and integrates localized metadata with Jyavani's frontend routing,
search, sitemaps, and `hreflang` output.

## Requirements

- Jyavani Core 2.3.149 or newer
- PHP 8.1 or newer
- PHP extensions: PDO and JSON

## Features

- Translate Posts and Pages with separate draft and published states.
- Translate Categories, author biographies, site identity, menu labels, and
  selected sidebar content.
- Translate Theme Partials, Theme Sections, declared Theme File values, Theme
  Zones, and discoverable theme UI strings.
- Translate the controlled fields of Core Shortcode Presets.
- Configure locale-specific Post and Page collection paths.
- Publish locale-prefixed routes, canonical URLs, `hreflang` links, localized
  search results, collection pages, and selected-locale sitemaps.
- Render a context-aware language switcher as pills or a select field.
- Assign locale editing grants and default writing languages by user or role.
- Switch the displayed language on Core content/category lists and compatible
  mixed-content plugin lists without changing the user's writing language.
- Manage reviewed media metadata, locale availability, aliases, and localized
  featured-media choices when the required Core media contract is available.
- Export translation data as JSON before maintenance or removal.

Translations are not published automatically. Public routes and switcher links
are exposed only when the relevant translation satisfies its publication and
completeness requirements.

## Installation

Install Content Translation through Jyavani's Plugin Store or upload its plugin
package from the Jyavani plugin manager. Activate it there so Jyavani can check
requirements, run the append-only migrations, copy static assets, and register
the plugin permission.

Do not load `plugin.php` directly.

## Getting Started

1. Open **Tools > Content Translation > Settings**.
2. Enable the locales used by the site and select their text direction.
3. Choose which locales should receive translated sitemaps.
4. Assign locale editing grants to the appropriate users or roles.
5. Open the Content Translation workspace, or use the translation controls in
   a supported Core editor.
6. Review the canonical source, save a translation as a draft, and publish it
   when it is ready.

The default content locale keeps its normal unprefixed URL. Enabled alternate
locales use locale-prefixed routes. For example:

```text
/article-slug/
/id/slug-artikel/
/de/artikel-slug/
```

## Language Switcher

Add a language switcher with one of the following widget shortcodes:

```text
[[widget:lang_switcher style="pills"]]
[[widget:lang_switcher style="select"]]
```

The switcher resolves the matching localized resource for the current page and
hides representations that are not publicly available.

## Optional Jyavani AI Integration

When Jyavani AI 0.4.0 or newer is active and the current user is authorized,
Post and Page translation editors can offer a **Translate with AI** action. The
action is explicit, translates only the canonical body into the unsaved editor
draft, and never publishes or saves the result automatically.

See [Jyavani AI integration](docs/jyavani-ai.md) for the complete contract.

## Theme and Media Translation

Theme translation is contract-based. Themes must explicitly declare supported
Theme File or Theme Zone fields, and only literal PHP strings discoverable from
supported translation calls can be reviewed. Arbitrary runtime expressions and
JavaScript catalogs are not translated automatically.

Localized media reuses one physical file while storing reviewed metadata and
availability separately for each locale. Optional aliases do not rename or
duplicate the source file.

- [Theme translation](docs/theme-translation.md)
- [Localized media](docs/localized-media.md)
- [Shortcode Preset translation](docs/shortcode-preset-translation.md)

## Authorization and Data Safety

- Workspace access uses the `plugin.content-translation.workspace.access`
  permission.
- Mutations also enforce the relevant Core permission and locale grant.
- Publishing requires the applicable Core publishing permission.
- Mutation and export requests use Core CSRF validation.
- Saves use optimistic state tokens and reject conflicting edits.
- Translation bodies follow the actor's Core HTML-sanitization permission.
- Slugs are checked against reserved and existing source or translated routes.
- Translation records remain in plugin-owned tables; canonical Core resources
  retain their ownership.

## Export and Uninstall

The Settings page can export translation data as JSON. Export data before
disabling or removing the plugin. The export is intended as a safety record and
this repository does not currently provide an importer.

Uninstalling Content Translation is destructive: plugin-owned translation,
workflow, grant, and localized-media tables are removed. Safety checks can
block removal while alternate-language authoring workflows or localized-media
state still require attention.

## Development

Tests are standalone PHP scripts. From the repository root, run:

```bash
for test in tests/*.php; do
  php "$test" || exit 1
done
```

Lint all PHP sources with:

```bash
while IFS= read -r -d '' file; do
  php -l "$file" || exit 1
done < <(find . -name '*.php' -print0)
```

Bug reports and focused contributions are welcome through
[GitHub Issues](https://github.com/adammuizweb/content-translation/issues).
