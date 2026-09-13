# Changelog

All notable changes to Pholio are recorded in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and Pholio adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).
While the version is below 1.0.0, a minor version may change the content format
or the configuration schema; such changes are listed under **Changed**.

## [0.1.0] - 2026-09-13

The first release: beautiful documentation, powered by Markdown. Pholio turns a
folder of Markdown files into a static documentation site, built by plain PHP
with no dependencies.

### Added

- **Markdown to site.** A strict Markdown subset with frontmatter, GitHub tables,
  task lists and footnotes, one `meta.json` per folder for the page tree, heading
  anchors and a table of contents. Unknown constructs, components, icons or
  languages, invalid `meta.json` and duplicate slugs stop the build with file and
  line. Images resolve relative to the page or through the copied directories and
  get their width and height.
- **The theme.** Sidebar with collapsible folders and a collapsed mode, header
  tabs, table of contents with a popover on small screens, breadcrumbs, previous
  and next links, light and dark schemes with a keyboard shortcut, Inter and the
  lucide icons, in plain CSS and small JavaScript modules.
- **Color presets.** `theme.preset` selects one of eleven built-in presets for
  light and dark: neutral (the default), black, vitepress, dusk, catppuccin,
  ocean, purple, solar, emerald, ruby and aspen. `theme.light`, `theme.dark` and
  `theme.palette_css` adjust single tokens or add a palette on top.
- **Components** as declarative tags: callouts, cards, tabs, steps, accordions,
  file trees, type tables, screenshots with a dark variant, zoomable images,
  banners, an inline table of contents and dynamic code blocks.
- **Syntax highlighting** at build time from TextMate grammars with the GitHub
  light and dark themes: titles, line numbers, highlighted, focused and diff
  lines, highlighted words and code tabs.
- **Search without a backend.** An inverted index built at compile time and
  ranked in a Web Worker: title and phrase matches first, then a score weighted
  by word rarity and query coverage, with prefix, compound, inflection and typo
  matching, English and German tokenizers and `keywords` in the frontmatter.
- **Agent-ready output.** A Markdown twin of every page, `llms.txt` and
  `llms-full.txt`, `skill.md` with Agent Skills discovery, an agent card,
  `robots.txt`, `sitemap.xml`, JSON-LD, and content negotiation with discovery
  headers in `.htaccess`, `_headers` and `pholio dev`.
- **Page menu.** "Copy page" next to the page title, with a menu to copy the page
  as Markdown or open it in ChatGPT or Claude.
- **Install script.** `curl -fsSL https://pholio.lukaloehr.com/install.sh | sh`
  installs the latest release after verifying its checksum, PHP and PCRE2, with
  `--version`, `--system`, `--prune` and `--dir` for a per-project copy.
- **Command line.** `pholio init` creates a project with a sample site,
  `pholio build` writes the static site, `pholio check` compares a build with a
  directory in both directions, and `pholio dev` previews with live rebuilds.
  Every command takes the project directory, `--config`, `--profile` and `--set`,
  and maps errors to documented exit codes.
- **Configuration.** Optional: every key has a default. One PHP file returning
  plain data, validated with errors naming the key, with profiles for staging
  builds.
- **Deployment output.** Static HTML per page, one stylesheet, the search index,
  an Apache `.htaccess` with security headers and redirects, and a Cloudflare
  Workers example.
- **Interface languages** English and German.
- **Documentation** in `docs/`, written in Pholio's own format, and a demo site
  in `examples/demo/` that uses every component.

[0.1.0]: https://github.com/luka-loehr/pholio/releases/tag/v0.1.0
