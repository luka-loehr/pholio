---
title: Roadmap
description: What exists, what is next, and what is deliberately out of scope.
icon: map
---

## Now

The generator is being written inside the project it was built for, and moved
here once the first phase is accepted. This repository currently holds the
skeleton: documentation, configuration schema, licence, checks and the CLI
entry point.

<Callout type="warn" title="Pre-release">
Nothing here is versioned yet. Treat every path and every key as settled but
the code as absent.
</Callout>

## Next

1. **Move the generator.** `src/` and `theme/` arrive by subtree split, so the
   one-file-per-commit history survives rather than landing as one import.
2. **Wire the CLI.** `php bin/pholio build --config <file>`, plus `--check`,
   `--dev` and `--only`.
3. **Dogfood.** Build this documentation with Pholio and publish it, which is
   also the first test that the package works from outside.
4. **The component catalogue.** Tabs, accordions, steps, file trees, type
   tables, banners, inline tables of contents, image zoom, and code blocks with
   titles, line numbers and highlighting.
5. **Extension points.** Slot overrides and registered custom tags.
6. **A second consumer.** One project other than the first one, to find the
   assumptions that only look general.

## Out of scope

These are decisions, not omissions.

- **No source extraction.** No docblock parsing, no API reference generation.
  Documentation is written, not derived.
- **No MDX.** Tags carry attributes and nest; they never import or evaluate.
- **No runtime PHP.** The output is static files. A PHP runtime in production is
  never required.
- **No package manager.** Pholio is copied in, not installed. That is what
  "zero dependencies" has to mean to be worth saying.
