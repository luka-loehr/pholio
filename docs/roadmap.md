---
title: Roadmap
description: What exists, what is next, and what is deliberately out of scope.
icon: map
---

## Now

**Status: pre-release, 0.1.0.** The generator, the theme, the verification
tooling and the tests are in this repository. `php bin/pholio build` builds the
demo site in `examples/demo/`, `pholio check` compares a build with committed
output, and `pholio dev` serves a site while you edit it.

The content format covers the full component catalogue: callouts, cards,
screenshots, image zoom, banners, tabs, accordions, steps, file trees, type
tables, an inline table of contents and highlighted code blocks.

<Callout type="warn" title="Pre-release">
No version is tagged yet. The configuration schema and the content format are
settled, but a key or a message may still change before 0.1.0.
</Callout>

## Next

1. **Release 0.1.0.** A release check over the whole history, then the tag.
2. **Link and image checks, after 0.1.** Stop the build on an internal link to
   a page that doesn't exist, on a missing image and on a `Screenshot` whose
   dark image is missing.
3. **The planned keys.** `base_url` with canonical links, Open Graph and a
   sitemap; `theme.default_scheme` and `theme.custom_css`; `search.enabled`;
   icon links in `nav`; `strict_content`.
4. **Extension points.** `slots` for sidebar, table of contents and page footer,
   and `components` for tags registered in PHP.
5. **A neutral reference app,** so that tier 3 runs inside this repository and
   not only in the site Pholio was first built for.
6. **More consumers,** to find the assumptions that only look general.

## Out of scope

These are decisions, not omissions.

- **No source extraction.** No docblock parsing, no API reference generation.
  Documentation is written, not derived.
- **No MDX.** Tags carry attributes and nest; they never import or evaluate.
- **No runtime PHP.** The output is static files. A PHP runtime in production is
  never required.
- **No package manager.** Pholio is copied in, not installed. That is what
  "zero dependencies" has to mean to be worth saying.
