---
title: Roadmap
description: What exists, what is next, and what is deliberately out of scope.
icon: map
---

## Now

**Status: 0.1.0.** `pholio init` creates a project, `pholio dev` previews it
while you edit, `pholio build` writes the static site and `pholio check`
compares a build with committed output. A build stops on a broken link, fragment
or image. The content format covers every
component on the
[components page](/docs/components), and every build publishes the files AI
agents look for.

## Next

1. **The planned keys.** `base_url` with canonical links and Open Graph;
   `theme.default_scheme`; `search.enabled`; icon links
   in `nav`; `strict_content`.
2. **Extension points.** `slots` for sidebar, table of contents and page footer,
   and `components` for tags registered in PHP.
3. **More languages.** Interface translations beyond English and German, and
   more bundled grammars for code blocks.
4. **More consumers,** to find the assumptions that only look general.

## Out of scope

These are decisions, not omissions.

- **No source extraction.** No docblock parsing, no API reference generation.
  Documentation is written, not derived.
- **No MDX.** Tags carry attributes and nest; they never import or evaluate.
- **No runtime PHP.** The output is static files. A PHP runtime in production is
  never required.
- **No package manager.** Pholio is copied in, not installed. That is what
  "zero dependencies" has to mean to be worth saying.
