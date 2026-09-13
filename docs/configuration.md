---
title: Configuration
description: Every key of pholio.config.php, what it does and what happens when you omit it.
icon: settings
---

The configuration is one PHP file returning one array. It holds data only: no
classes, no environment lookups, no side effects. Start from
`pholio.config.example.php` in the repository root, which shows the same keys
with comments.

`src/Config.php` validates the file before anything is built. An unknown key,
a value of the wrong type or a planned key set to anything but its default
stops the command with exit code 2 and names the key path, for example
`pholio: unknown key: search.tokeniser (did you mean "tokenizer"?)`.

## Rules for values

**Paths** (`content_dir`, `output_dir`, `copy` sources, `content.asset_root`,
`theme.palette_css`, `redirects_file`) are absolute or relative to the directory
of the configuration file, not to the directory you run `pholio` from.

**URL paths** start with `/`. Trailing slashes are optional and removed: every
generated URL has none, except the site root `/`. A URL with a scheme
(`https://…`) is kept as written.

**Placeholders** are resolved in URL and text values:

- `{site}`: the `title`
- `{home}`: the start page URL, from `base_path`
- `{docs}`: the docs root URL, from `docs_path`
- `{assets}`: the theme asset URL, from `asset_base`

**Planned** keys are part of the schema so a configuration that uses them fails
with a clear `planned: <key>` message instead of `unknown key`. They accept
only their default value until the feature exists.

## Site

| Key | Default | Meaning |
| --- | --- | --- |
| `title` | required | Site name: header wordmark, `<title>`, `{site}` |
| `title_template` | `{title} – {site}` | Page `<title>` pattern |
| `logo` | `null` | Logo URL next to the wordmark. `null` shows the wordmark only |
| `logo_size` | `24` | Logo width and height in pixels |
| `home_link` | `{home}` | Where the wordmark links |
| `base_url` | `null` | Planned: absolute origin for canonical links, Open Graph and a sitemap |
| `base_path` | `/` | URL of the start page. `/docs/` when the site sits in a subfolder |
| `docs_path` | `null` | URL of the docs root. `null` uses `base_path` |
| `docs_root_suffix` | `null` | Path appended to the docs root page, e.g. `/overview`. Needed when the docs root and the start page would share one URL |
| `asset_base` | `assets/` | URL of the theme's CSS, JavaScript, fonts and licences. Relative to `base_path` unless it starts with `/` or a scheme |
| `language` | `en` | `<html lang>`, the interface strings and the default search tokenizer. Shipped: `en`, `de`. Any other language needs `translations` for every key |
| `translations` | `[]` | Interface string overrides, translation key => text. An unknown key is an error |

When a start page is configured and `docs_path` equals `base_path`, a content
page at the docs root would collide with the start page. The build stops and
asks for `docs_root_suffix`, a different `docs_path`, or `home => null`.

## Input and output

| Key | Default | Meaning |
| --- | --- | --- |
| `content_dir` | required | Markdown sources and `meta.json` tree files |
| `output_dir` | required | Directory of the start page's `index.html`. `pholio build` writes into it and deletes nothing |
| `content.extensions` | `['md']` | File extensions read as pages, without the dot |
| `content.link_prefix` | `null` | URL prefix of internal links in the content that is rewritten to the docs root. With `'/handbook'`, a link to `/handbook/guide` in the content points at `{docs}/guide` in the output. `null` leaves links as written |
| `content.asset_prefix` | `null` | URL prefix of image sources in the content that is rewritten to `content.asset_target`. `null` leaves image sources as written |
| `content.asset_target` | `{docs}` | URL that `content.asset_prefix` maps to |
| `content.asset_root` | `null` | Directory that mirrors `content.asset_target`, used to read image width and height. `null` resolves images against `content_dir` |
| `content.frontmatter_aliases` | `[]` | Extra frontmatter names mapped onto allowed ones, e.g. `['date' => 'updated']` |
| `copy` | `[]` | Source directory => URL directory, copied into the output verbatim, without `*.md` files |
| `output.keep` | `[]` | Paths below `output_dir` that Pholio neither writes nor reports in `pholio check`. A directory covers everything below it |

Rewriting happens at a path boundary only: `/handbook` matches `/handbook` and
`/handbook/guide`, never `/handbooks`.

## Navigation

`nav` is the list of header links, rendered in the header and in the mobile
drawer.

| Key | Default | Meaning |
| --- | --- | --- |
| `nav` | `[]` | List of header links |
| `nav[].title` | required | Link text |
| `nav[].href` | required | Link target. Write internal URLs without a trailing slash, as the generated pages have none |
| `nav[].active` | `exact` | `exact`: current on that URL only. `prefix`: also current on every page below it |
| `nav[].external` | `false` | Opens in a new tab with `rel="noreferrer noopener"` |
| `nav[].icon` | `null` | Planned: lucide icon for the link |
| `nav[].icon_only` | `false` | Planned: render the link as an icon button |

## Start page

`home` describes the landing page at `base_path`. Leave it `null` to make the
first content page the root.

| Key | Default | Meaning |
| --- | --- | --- |
| `home` | `null` | The start page, or `null` for none |
| `home.title` | `{site}` | `<title>` of the start page |
| `home.hero` | `null` | The hero section, or `null` to leave it out |
| `home.hero.kicker` | `''` | Small line above the headline |
| `home.hero.headline` | required | The headline. `\n` starts a new line |
| `home.hero.lead` | `''` | Paragraph under the headline |
| `home.hero.image` | `null` | Background image URL |
| `home.hero.image_dark` | `null` | Background image URL for the dark scheme |
| `home.hero.icon` | `null` | Square icon URL above the kicker |
| `home.hero.icon_size` | `48` | Icon width and height in pixels |
| `home.hero.buttons` | `[]` | Buttons under the lead |
| `home.hero.buttons[].label` | required | Button text |
| `home.hero.buttons[].href` | required | Button target |
| `home.hero.buttons[].variant` | `primary` | `primary` or `secondary` |
| `home.hero.buttons[].icon` | `null` | lucide icon after the label |
| `home.cards` | `null` | The card grid, or `null` to leave it out |
| `home.cards.title` | `''` | Heading above the grid |
| `home.cards.link_label` | `null` | Link text on each card. `null` uses the translation of `Open(home card)` |
| `home.cards.from_tree` | `false` | One card per root folder, from its `meta.json` title, description and icon |
| `home.cards.items` | `[]` | Explicit cards, in this order |
| `home.cards.items[].title` | required | Card title |
| `home.cards.items[].description` | `''` | Card text |
| `home.cards.items[].href` | required | Card target |
| `home.cards.items[].icon` | `null` | lucide icon |

## Theme

`theme.light` and `theme.dark` map colour token names, without the
`--color-fd-` prefix, to CSS colours. The build writes them, followed by the
contents of `theme.palette_css`, at the palette marker `/* @pholio:palette */`
in `theme/css/tokens.css`. That block is unlayered, like the site palette in
the reference stylesheet, so it wins over the default tokens. Anything you
leave out keeps its default.

| Key | Default | Meaning |
| --- | --- | --- |
| `theme.light` | `[]` | Token => colour for the light scheme |
| `theme.dark` | `[]` | Token => colour for the dark scheme |
| `theme.palette_css` | `null` | Stylesheet inserted at the palette marker, for palettes a token map can't express |
| `theme.preset` | `null` | Value of `<html data-preset>`. `null` omits the attribute |
| `theme.font_class` | `''` | Extra class on `<html>` |
| `theme.hotkey` | `d` | Key that toggles the colour scheme |
| `theme.default_scheme` | `system` | Planned: `light` or `dark` as the scheme before the visitor chooses |
| `theme.custom_css` | `null` | Planned: stylesheet appended after the theme CSS |

<Callout type="warn" title="Opacity is computed, not written">
Token opacities are emitted as `color-mix(in oklab, …)`, the way Tailwind v4
does it. Writing them as HSL alpha instead makes the computed styles diverge
from the reference, and the style comparison catches it.
</Callout>

## Document head

| Key | Default | Meaning |
| --- | --- | --- |
| `head.icons` | `[]` | `<link>` icons, in this order |
| `head.icons[].rel` | required | `icon`, `apple-touch-icon`, … |
| `head.icons[].type` | `null` | MIME type |
| `head.icons[].sizes` | `null` | `sizes` attribute |
| `head.icons[].href` | required | Icon URL |
| `head.manifest` | `null` | Web app manifest URL |
| `head.theme_color` | `null` | `<meta name="theme-color">` |

## Search

The index is written at build time and loaded by the browser the first time the
search dialog opens.

| Key | Default | Meaning |
| --- | --- | --- |
| `search.enabled` | `true` | Planned: `false` to remove the search field, the hotkey and the index |
| `search.index_path` | `search-index.json` | Index file, relative to the docs root |
| `search.tokenizer` | `null` | `english` or `german`. `null` follows `language`: a language starting with `de` gives `german`, anything else `english` |
| `search.hotkey` | `['⌘', 'K']` | Keys shown in the search field |

The two tokenizers are the zbsearch splitter profiles of the same name. They
differ only in which characters count as part of a word: `english` keeps
apostrophes, hyphens and a few accented vowels, `german` keeps the German
letters. Neither removes stopwords or stems, which matches the reference.

## Server

| Key | Default | Meaning |
| --- | --- | --- |
| `redirects` | `[]` | Old path => new path, or a list of `from`, `to`, `reason` |
| `redirects_file` | `null` | JSON file with a list of `from`, `to`, `reason`, appended to `redirects` |
| `server.htaccess` | `true` | Write `.htaccess` into `output_dir` |
| `server.csp` | strict default | `Content-Security-Policy` header in `.htaccess`. Must not contain `"` |

The generated `.htaccess` is the only server file Pholio writes. It sets
security headers and MIME types, maps slashless page URLs to their
`index.html`, and turns every redirect into a 301 rule. A redirect's `from` must
lie below `base_path`, and its `to` must be a path.

## Profiles

| Key | Default | Meaning |
| --- | --- | --- |
| `profiles` | `[]` | Profile name => partial configuration |

`--profile <name>` merges that partial configuration recursively over the base
before validation, for example a staging build under another `base_path`.
Unknown keys inside every profile are errors even when the profile isn't
selected, and profiles can't be nested.

## Extension points

| Key | Default | Meaning |
| --- | --- | --- |
| `slots` | `[]` | Planned: slot name => PHP file returning HTML |
| `components` | `[]` | Planned: custom content tags registered in PHP |
| `strict_content` | `false` | Planned: stricter content checks, such as a required description |
