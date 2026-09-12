---
title: Configuration
description: Every key of pholio.config.php, what it does and what happens when you omit it.
icon: settings
---

The configuration is one PHP file returning one array. It contains data only:
no classes, no environment lookups, no side effects. Start from
`pholio.config.example.php`, which carries the same table as comments.

## Site

| Key | Default | Meaning |
| --- | --- | --- |
| `title` | required | Header wordmark, `<title>`, search dialog |
| `logo` | `null` | Path to the logo, rendered 24px and rounded |
| `base_url` | required | Absolute site URL, no trailing slash. Canonical links, Open Graph, sitemap |
| `base_path` | `/` | Path prefix the site is served under |
| `asset_base` | `assets/` | Where CSS, JS, fonts and images are served from |
| `language` | `en` | `<html lang>`, UI translations, search tokenizer. Shipped: `en`, `de` |

## Input and output

| Key | Default | Meaning |
| --- | --- | --- |
| `content_dir` | required | Markdown sources and `meta.json` tree files |
| `output_dir` | required | Where the static site is written. Files Pholio did not write are left alone |

## Navigation

`nav` is a list of entries with `title` and `href`. Add `active` set to
`prefix` to mark the entry current whenever the path starts with its `href`.
Add `icon` and `icon_only` for the icon links that sit on the right of the
header.

## Start page

`home` describes the landing page, or is `null` to make the first content page
the root.

| Key | Meaning |
| --- | --- |
| `home.hero.kicker` | Small uppercase line above the headline |
| `home.hero.headline` | The headline. `\n` becomes a line break |
| `home.hero.lead` | Paragraph under the headline |
| `home.hero.image`, `home.hero.image_dark` | Background images for the two colour schemes |
| `home.hero.icon` | Square icon above the kicker, 48px |
| `home.hero.buttons` | List of `label`, `href`, `variant` (`primary` or `secondary`), `icon` |
| `home.cards.title` | Heading above the card grid |
| `home.cards.from_tree` | Derive the cards from the root folders' `meta.json` |
| `home.cards.items` | Explicit cards, when `from_tree` is off |

## Theme

`theme.light` and `theme.dark` map colour token names, without the
`--color-fd-` prefix, to CSS colours. Anything omitted keeps its default.
Opacity variants are derived, so never list them.

| Key | Default | Meaning |
| --- | --- | --- |
| `theme.default_scheme` | `system` | Scheme before the visitor chooses: `light`, `dark`, `system` |
| `theme.custom_css` | `null` | Stylesheet appended after the generated CSS |

<Callout type="warn" title="Opacity is computed, not written">
Token opacities are emitted as `color-mix(in oklab, …)`, the way Tailwind v4
does it. Writing them as HSL alpha instead makes the computed styles diverge
from the reference, and the style diff will catch it.
</Callout>

## Search

| Key | Default | Meaning |
| --- | --- | --- |
| `search.enabled` | `true` | Off removes the search field, the hotkey and the index file |
| `search.index_path` | `search-index.json` | Index location, relative to `output_dir` |
| `search.tokenizer` | follows `language` | `german` or `english`. Neither stems, matching the reference |

## Redirects

`redirects` maps an old path to a new one. The entries become 301 rules in the
generated `.htaccess`, and in a `_redirects` file where the host supports it.

## Extension points

`slots` maps a slot name to a PHP file returning HTML. The slots are `nav`,
`sidebar.banner`, `sidebar.footer`, `toc.header` and `page.footer`.
`components` registers your own content tags. Both are planned for the phase
that follows the first release.
