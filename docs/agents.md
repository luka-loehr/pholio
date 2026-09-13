---
title: Agents
description: What a build publishes for AI agents, where it lives and how servers hand it out.
icon: bot
---

Language models read documentation too: coding agents, chat assistants that fetch a
page for a user, crawlers that index it. HTML is an expensive way to give them the
text. Every Pholio build therefore also publishes the documentation as Markdown,
indexes that list every page, a skill that explains how to use them, and the
headers that point agents there.

All of it comes from the same page tree as the sidebar and the search index: the
order from `meta.json`, titles, descriptions and keywords from the frontmatter,
drafts left out. There is nothing to maintain by hand, and nothing is written by a
model.

## The files

Paths are relative to `base_path`. A site served under `/manuals/` publishes
`/manuals/llms.txt`.

| File | Contents | Needs |
| --- | --- | --- |
| `<page>.md`, `index.md` | The Markdown twin of every page and of the start page | |
| `llms.txt`, `.well-known/llms.txt` | Index of all pages in navigation order | |
| `_llms/**.md` | Section indexes, only when `llms.txt` would grow past 100,000 characters | |
| `llms-full.txt`, `.well-known/llms-full.txt` | Every page in full | |
| `skill.md` | A skill describing the documentation and how to read it | |
| `.well-known/agent-skills/index.json` | Agent Skills discovery index 0.2.0, with digests | |
| `.well-known/skills/index.json` | The same skills in the 0.1.0 format | |
| `.well-known/agent-card.json` | A2A agent card, protocol 0.3 | `site.url` |
| `robots.txt` | Allows every crawler and names the sitemap | |
| `sitemap.xml` | Every indexable page | `site.url` |
| `_headers` | Discovery headers for Cloudflare Pages and Netlify | |

Every HTML page also gets JSON-LD (`WebSite`, `TechArticle`, `BreadcrumbList`), a
`<link rel="alternate" type="text/markdown">` to its twin and the page actions next
to its title.

Set `site.url` to the origin the site is served from. Without it, the files use
base-path URLs (`/manuals/guide/install.md`), and the sitemap and the agent card,
which need absolute URLs, are skipped with a warning.

```php
'site' => [
    'url' => 'https://docs.example.org',
    'description' => 'How to install, configure and run Example.',
],
```

## Markdown twins

A page at `/guide/install` has its twin at `/guide/install.md`; the start page's is
`index.md`. The twin is the page's own Markdown, cleaned up:

```
> ## Documentation Index
> Fetch the complete documentation index at: https://docs.example.org/llms.txt
> Use this file to discover all available pages before exploring further.

# Installation

> Install Example with your package manager of choice.

## Requirements

…

## Related topics

- [Configuration](https://docs.example.org/guide/configuration.md)
- Previous: [Overview](https://docs.example.org/guide.md)
- Next: [Configuration](https://docs.example.org/guide/configuration.md)
```

The frontmatter becomes the title and the description. Component tags become what
they mean in plain Markdown:

| Tag | In the twin |
| --- | --- |
| `Callout` | A blockquote starting with its type and title: `> **Warning:** Careful` |
| `Tabs`, `Accordions`, code tabs | One heading per tab or item, a level below the surrounding heading |
| `Cards` | A list of links with their descriptions |
| `Steps` | Numbered headings, or an ordered list when a step has no heading |
| `Files` | A nested list, folders with a trailing slash |
| `TypeTable` | A list of properties with type, default and description |
| `Screenshot`, `ImageZoom`, images | Images with their alt text and absolute URLs |
| `Banner` | A blockquote |
| `InlineTOC` | Left out |

Links to other pages point at their twins. Notation comments in code blocks
(`// [!code highlight]`) are removed.

## llms.txt

`llms.txt` follows [llmstxt.org](https://llmstxt.org): the site title, the site
description as a blockquote, an `## Agent Instructions` section when
`agents.instructions` is set, one section per top-level folder with a line per
page, and the external navigation links under `## Optional`.

```
# Example

> How to install, configure and run Example.

## Guide

- [Installation](https://docs.example.org/guide/install.md): Install Example with your package manager of choice.
```

The summary is the page's `description`, or its first paragraph cut at 300
characters. When the file would exceed 100,000 characters, `llms.txt` lists section
indexes below `_llms/` instead, with their page counts, and tells agents to follow
them recursively. A section index that is still too long lists its subfolders'
indexes, and a folder with too many pages is split into parts. No page is ever left
out.

`llms-full.txt` has every listed page in navigation order: its title, a `Source:`
line with the page URL, the description and the whole twin. It has no size limit.

## Skills and the agent card

`skill.md` is a [SKILL.md](https://agentskills.io/specification) generated from the
configuration and the content: what the documentation covers, section by section,
how to fetch `llms.txt` and the Markdown pages, and how to search them. The same
file is published below `.well-known/agent-skills/<name>/` and listed in the
discovery index with its SHA-256 digest, so agents can tell when it changed.

To write the skill yourself, put `skill.md` next to `pholio.config.php`. Further
skills go into `skills/<name>/SKILL.md`; a skill with the generated skill's name
replaces it. Each file needs a one-line `name` and `description` in its
frontmatter.

`.well-known/agent-card.json` describes the site as an A2A agent with Markdown and
plain text as input and output modes and the skills above. The site is static, so
the card advertises no endpoint beyond the documentation.

## Choosing what agents see

- Drafts (file names starting with `_`) are never listed.
- `noindex: true` in the frontmatter keeps a page out of `llms.txt`,
  `llms-full.txt`, `skill.md` and the sitemap, and adds
  `<meta name="robots" content="noindex">`. The page and its twin are still built.
- `agents.exclude` takes globs on page URLs and content files, for example
  `['/guide/internal/*', 'drafts/*.md']`. Matching pages stay out of `llms.txt`,
  `llms-full.txt` and `skill.md`.
- Each file can be switched off in the `agents` block, and `agents.enabled => false`
  switches off all of them. See [Configuration](/docs/configuration#agents).
- A `robots.txt` that a `copy` directory already provides is left alone.

## Serving

Agents find the files through headers and ask for Markdown with the `Accept`
header. On every response, 404s included, Pholio's server configurations send:

- `Link` with `rel="llms-txt"`, `rel="llms-full-txt"`, `rel="agent-card"` and
  `rel="agent-skills"`, for the files the build wrote
- `X-Llms-Txt` with the URL of `llms.txt`
- `Vary: Accept, User-Agent`

A page URL is answered with its twin for `Accept: text/markdown` and for the user
agents of AI assistants fetching a page for a user (`Claude-User`, `ChatGPT-User`,
`OAI-SearchBot`, `PerplexityBot`, `Perplexity-User`, `Google-Agent`,
`MistralAI-User`, `DuckAssistBot`, `cohere-ai`), and as `text/plain` for
`Accept: text/plain`. Browsers get the HTML page as before.

**Apache.** The generated `.htaccess` does all of this with `mod_rewrite` and
`mod_headers`.

**pholio dev** behaves like production. Try it:

```bash
curl -H 'Accept: text/markdown' http://127.0.0.1:8080/guide/install
curl -A 'Claude-User' http://127.0.0.1:8080/
```

**Cloudflare Pages, Netlify, Workers static assets** read the `_headers` file, so
the headers work there. Choosing the twin per request needs a small edge function:
`examples/cloudflare-worker` in the repository is a ready Worker for Cloudflare, and
the rules above are all a Netlify Edge Function has to reproduce. Without one,
agents still find everything through `llms.txt` and the `.md` URLs.

`robots.txt` only counts at the root of a host. When the site lives below a base
path, copy its lines into the host's own `robots.txt`.

## Checking a site

`verify/agent-score.mjs` runs the checks of Mintlify's `mint score` against any
running site: `llms.txt` and `llms-full.txt` (format, size, links), `skill.md` and its
digests, content negotiation for Markdown and plain text, `robots.txt`, the sitemap,
JSON-LD, the `Link` header on a page and on a 404, and response time.

```bash
node verify/agent-score.mjs --base https://docs.example.org
node verify/agent-score.mjs --demo --min-score 100
```

`node verify/run.mjs` runs the second line: the demo served by the
`pholio dev` router has to score 100.

## An MCP server

Pholio's output is static, so it ships no MCP server. A host that runs PHP could
add one later without touching the build: a small endpoint that answers `search`
from `search-index.json`, which already ranks pages and sections, and `fetch` from
the Markdown twins. It would be listed in the agent card and the `Link` header
next to the files above.
