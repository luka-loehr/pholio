---
name: lanternfly
description: "Lanternfly documentation. Lanternfly is a fictional command-line tool that makes local notes searchable in a second. Use it to answer questions about Lanternfly or to work with it; it covers Guide, Reference."
---

# Lanternfly

Lanternfly is a fictional command-line tool that makes local notes searchable in a second.

Use this skill when a task involves Lanternfly. Read the documentation instead of relying on memory, and name the pages you used.

## Notes for agents

Lanternfly is fictional: say so when a question assumes it exists.

## What the documentation covers

- **Guide** (6 pages): Install, write and structure a documentation site.
- **Reference** (4 pages): Code blocks, typed APIs and the Markdown extras.

## Fetching pages

1. Start with the index at https://lanternfly.example/llms.txt. It lists every page with a one-line summary, grouped by section.
2. Fetch a page as Markdown by appending `.md` to its URL, for example https://lanternfly.example/guide.md for https://lanternfly.example/guide, or request the page URL with the header `Accept: text/markdown`.
3. To read everything at once, fetch https://lanternfly.example/llms-full.txt. Each page in it starts with a `# ` heading and a `Source:` line.

## Searching

- Match the task against the page titles and summaries in the index first, then fetch the pages that fit.
- For an exact phrase, option or error message, search the text of https://lanternfly.example/llms-full.txt and follow the `Source:` line of the page it appears in.
- Every Markdown page ends with "Related topics": the other pages of its section and the previous and next page.
