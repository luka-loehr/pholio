---
title: Verification
description: The three test tiers, and the four stages that turn "looks the same" into a measurement.
icon: check-circle
---

"Identical to the reference" is not a matter of taste here. A reference build
is frozen once, and a Pholio build is compared against it in four stages: DOM,
computed styles, pixels, behaviour. Each stage reports a diff rather than a
score. What a machine can run depends on what it has installed, so the checks
are split into three tiers.

## Tiers

| Tier | Needs | Runs | Command |
| --- | --- | --- | --- |
| 1 | PHP 8.2, PCRE2 10.43 | `php -l` on every tracked PHP file, `php tests/run.php`, and the demo built against `tests/snapshots/demo` | `./scripts/check.sh` |
| 2 | Tier 1, Node, `npm ci` in `verify/`, Chromium | The selftests of the DOM, style and pixel tools, the search oracle over the demo with `verify/fixtures/demo/queries.json`, the lucide oracle | `node verify/run.mjs --tier 2` |
| 3 | Tier 2, a reference export and its rewrites file | Golden DOM, computed styles, pixels, behaviour and component states against the reference | `node verify/run.mjs --tier 3 --reference <dir> --rewrites <file.json> --candidate <url>` |

`verify/run.mjs` runs every lower tier first, including tier 0, a Node-only
selftest of `verify/lib`. Setup for tiers 2 and 3:

```bash
cd verify
npm ci
npx playwright install chromium-headless-shell
```

PHP tests that need Node or a reference print `SKIP: <reason>` and pass.
`php tests/run.php --require-node` or `--require-reference` turns every skip into
a failure, for release checks.

### The demo snapshot

`tests/snapshots/demo` is the committed output of the demo build.
`tests/PipelineTest.php` and `scripts/check.sh` run `pholio check` against it, so
any change to the generated HTML, CSS, JavaScript or search index shows up as a
`missing:`, `stale:` or `extra:` line. After an intended change, regenerate it
and review the diff:

```bash
./scripts/update-snapshots.sh
git diff --stat tests/snapshots/demo
```

The snapshot is generated output and is committed as one commit per run.

## 1. Golden DOM

The reference pages are read from the browser after hydration, normalised
(framework scripts, generated ids and framework comments removed, whitespace
collapsed, class lists mapped through a translation table), and then compared
element by element and attribute by attribute against the Pholio output.

Differences that can't be removed, such as hydration ids, live in an allow list
where each entry carries a written reason. An entry without a reason is not
accepted.

## 2. Computed styles

Because stage one guarantees the same tree, both documents can be walked in
lockstep. For every element about seventy CSS properties are compared: box,
typography, colour, borders, shadows, transforms, transitions, animations,
masks, filters, position, z-index, overflow and grid templates.

This runs at three widths and in both colour schemes, and it repeats with
elements hovered and focused, because a hover rule that is subtly wrong is
invisible in a screenshot of a resting page.

## 3. Pixels

Full-page screenshots of both versions per page, width and colour scheme, with
zero tolerance outside a mask covering text antialiasing. Beyond resting pages
this covers the states that only exist while something is open: search with
results, search empty, sidebar collapsed, collapsed and hovered, the
table-of-contents popover, the mobile drawer, the tabs dropdown, both theme
states, and the page scrolled so a later heading is active.

## 4. Behaviour and motion

Scripts drive both versions through the same actions and compare the states in
between: the order in which state attributes appear when a dialog opens, frame
by frame; the keyframes, durations, easings and delays reported by
`getAnimations()`; the focused element after every step; keyboard navigation
through the result list; hotkeys; scroll locking; click-outside; the hover
timing of the collapsed sidebar; and the result lists for a fixed set of search
queries, including their order and their highlighting.

<Callout type="info" title="Why a fixed query set">
Search is the one part where a plausible approximation would go unnoticed for
months. A frozen query set, including word beginnings, accented letters,
two-word queries and deliberate misses, makes any divergence in tokenisation or
ranking visible on the next run.
</Callout>

## What Pholio can't prove alone

Tier 3 needs a reference app built from the same content. This repository has
none, so DOM, style and pixel comparisons run in the tier-3 run of the site
Pholio was first built for, against that site's own reference build.

## Last reference run

Each release records the tier-3 run it was verified with: date, Pholio commit,
reference export and pass counts per stage. Entries are added
when a release is tagged, starting with 0.1.0.
