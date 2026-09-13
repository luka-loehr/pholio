---
title: Configuration
description: Every key of pholio.config.php, what it does and what happens when you omit it.
icon: settings
---

The configuration is one PHP file returning one array. It holds data only: no
classes, no environment lookups, no side effects. It is optional: every key has a
default, so a project directory with a `content/` folder builds without one.
`pholio init` writes a small `pholio.config.php`, and
`pholio.config.example.php` in the repository root shows every key with
comments.

Pholio looks for the configuration in this order: the file passed with
`--config`; otherwise `pholio.config.php` in the project directory (the `[dir]`
argument, default the current directory); otherwise the defaults, with paths
relative to the project directory. `--config` together with a `[dir]` argument
is a usage error.

`src/Config.php` validates the file before anything is built. An unknown key,
a value of the wrong type or a planned key set to anything but its default
stops the command with exit code 2 and names the key path, for example
`pholio: unknown key: search.tokenize (did you mean "tokenizer"?)`.

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
| `title` | `Documentation` | Site name: header wordmark, `<title>`, `{site}` |
| `title_template` | `{title} – {site}` | Page `<title>` pattern |
| `logo` | `null` | Logo URL next to the wordmark. `null` shows the wordmark only |
| `logo_size` | `24` | Logo width and height in pixels |
| `home_link` | `{home}` | Where the wordmark links |
| `base_url` | `null` | Planned: absolute origin for canonical links, Open Graph and a sitemap |
| `base_path` | `/` | URL of the start page. `/docs/` when the site sits in a subfolder |
| `docs_path` | `null` | URL of the docs root. `null` uses `base_path` |
| `docs_root_suffix` | `null` | Path appended to the docs root page, e.g. `/overview`. Needed when the docs root and the start page would share one URL |
| `asset_base` | `pholio/` | URL of the theme's CSS, JavaScript, fonts and licenses. Relative to `base_path` unless it starts with `/` or a scheme. The default keeps `/assets/` free for the site's own files |
| `language` | `en` | `<html lang>`, the interface strings and the default search tokenizer. Shipped: `en`, `de`. Any other language needs `translations` for every key |
| `translations` | `[]` | Interface string overrides, translation key => text. An unknown key is an error |

When a start page is configured and `docs_path` equals `base_path`, a content
page at the docs root would collide with the start page. The build stops and
asks for `docs_root_suffix`, a different `docs_path`, or `home => null`.

## Input and output

| Key | Default | Meaning |
| --- | --- | --- |
| `content_dir` | `content` | Markdown sources and `meta.json` tree files |
| `output_dir` | `public` | Directory of the start page's `index.html`. `pholio build` writes into it and deletes nothing |
| `content.extensions` | `['md']` | File extensions read as pages, without the dot |
| `content.link_prefix` | `null` | URL prefix of internal links in the content that is rewritten to the docs root. With `'/handbook'`, a link to `/handbook/guide` in the content points at `{docs}/guide` in the output. `null` leaves links as written |
| `content.asset_prefix` | `null` | URL prefix of image sources in the content that is rewritten to `content.asset_target`. `null` leaves image sources as written |
| `content.asset_target` | `{docs}` | URL that `content.asset_prefix` maps to |
| `content.asset_root` | `null` | Directory that mirrors `content.asset_target`, used to read image width and height. `null` resolves images against `content_dir` |
| `content.frontmatter_aliases` | `[]` | Extra frontmatter names mapped onto allowed ones, e.g. `['date' => 'updated']` |
| `copy` | `assets` => `{home}/assets` | Source directory => URL directory, copied into the output verbatim, without `*.md` files. Without the key, `assets/` is published at `/assets/` when it exists; an explicit `copy` replaces that default |
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

The theme has a light and a dark color scheme with static colors. `theme.light`
and `theme.dark` map single color token names, without the `--color-fd-` prefix,
to CSS colors, and `theme.palette_css` adds a stylesheet of your own. The build
writes the token maps and then the stylesheet at the palette marker
`/* @pholio:palette */` in `theme/css/tokens.css`. The block is unlayered, so it
wins over the default tokens, and the stylesheet wins over the token maps.
Anything you leave out keeps its default.

| Key | Default | Meaning |
| --- | --- | --- |
| `theme.light` | `[]` | Token => color for the light scheme |
| `theme.dark` | `[]` | Token => color for the dark scheme |
| `theme.palette_css` | `null` | Stylesheet inserted after the token maps, for palettes a token map can't express |
| `theme.font_class` | `''` | Extra class on `<html>` |
| `theme.hotkey` | `d` | Key that toggles the color scheme |
| `theme.default_scheme` | `system` | Planned: `light` or `dark` as the scheme before the visitor chooses |
| `theme.custom_css` | `null` | Planned: stylesheet appended after the theme CSS |

The tokens are `background`, `foreground`, `muted`, `muted-foreground`,
`popover`, `popover-foreground`, `card`, `card-foreground`, `border`, `primary`,
`primary-foreground`, `secondary`, `secondary-foreground`, `accent`,
`accent-foreground` and `ring`.

```php title="pholio.config.php"
'theme' => [
    'light' => ['primary' => 'hsl(220 85% 45%)'],
    'dark' => ['primary' => 'hsl(220 90% 70%)'],
],
```

<Callout type="warn" title="Give tokens as opaque colors">
The theme derives translucent variants of a token with `color-mix(in oklab, …)`,
the way Tailwind v4 does. A token with its own alpha channel multiplies with
those steps and ends up fainter than intended.
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

Both tokenizers lowercase, fold diacritics and ligatures (`ä` → `a`, `ß` → `ss`),
split at everything but letters and digits and also index hyphenated words
joined, so "chat export", "chat-export" and "chatexport" find the same page.
`german` additionally reads `ae`, `oe` and `ue` as `a`, `o` and `u`, so
"Passwörter" and "Passwoerter" meet. Queries drop common stopwords ("wie", "ich",
"the", …; `german` drops German and English ones) unless nothing else is left, and
match inflected forms loosely, so "logs" also finds "Logging". Words a page does not
use itself can be added with the `keywords` frontmatter key.

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

## Agents

What a build publishes for AI agents: a Markdown twin of every page, `llms.txt`,
`llms-full.txt`, `skill.md`, the agent card, `robots.txt`, `sitemap.xml`, JSON-LD and
the discovery headers. [Agents](agents.md) describes each file.

| Key | Default | Meaning |
| --- | --- | --- |
| `site.url` | `null` | Origin the site is served from, e.g. `https://docs.example.org`, without a path (`base_path` adds it). Makes the URLs in the agent files absolute. `sitemap.xml` and `.well-known/agent-card.json` need it and are skipped with a warning while it is `null` |
| `site.description` | `null` | One sentence about the site for `llms.txt`, `skill.md`, the agent card and JSON-LD. `null` uses `home.hero.lead`, then the root `meta.json` description |
| `agents.enabled` | `true` | `false` turns every agent file and the page actions off |
| `agents.markdown` | `true` | `<page>.md` next to every page, and `index.md` for the start page |
| `agents.llms_txt` | `true` | `llms.txt` and `.well-known/llms.txt` |
| `agents.llms_full_txt` | `true` | `llms-full.txt` and `.well-known/llms-full.txt` |
| `agents.skill` | `true` | `skill.md` and the Agent Skills indexes below `.well-known/` |
| `agents.agent_card` | `true` | `.well-known/agent-card.json` (needs `site.url`) |
| `agents.robots_txt` | `true` | `robots.txt`, unless a `copy` directory or `output.keep` provides one |
| `agents.sitemap` | `true` | `sitemap.xml` (needs `site.url`) |
| `agents.structured_data` | `true` | JSON-LD in every page's `<head>` |
| `agents.headers` | `true` | The `Link` and `X-Llms-Txt` headers and content negotiation in `.htaccess`, the `_headers` file and `pholio dev` |
| `agents.page_actions` | `true` | "Copy page" next to the page title, with a menu to copy the page or open it in ChatGPT or Claude. Off when `agents.markdown` is off |
| `agents.instructions` | `null` | Text of the `## Notes for agents` section in `llms.txt` and `skill.md`; the heading follows `language` |
| `agents.exclude` | `[]` | Globs matched against page URLs (`/guide/internal/*`) and content file paths (`internal/*.md`); matching pages stay out of `llms.txt`, `llms-full.txt` and `skill.md`. `*` also matches `/` |

A page with `noindex: true` in its frontmatter is still built, but left out of
`llms.txt`, `llms-full.txt`, `skill.md` and `sitemap.xml`, and gets
`<meta name="robots" content="noindex">`.

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
