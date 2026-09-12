---
title: Verification
description: The four stages that turn "looks the same" into a measurement.
icon: check-circle
---

"Identical to the reference" is not a matter of taste here. A reference build
is frozen once, and every Pholio build is compared against it in four stages.
All four must be green, and each reports a diff rather than a score.

## 1. Golden DOM

The reference pages are read from the browser after hydration, normalised
(framework scripts, generated ids and framework comments removed, whitespace
collapsed, class lists mapped through a translation table), and then compared
element by element and attribute by attribute against the Pholio output.

Differences that cannot be removed, such as hydration ids, live in an
allow-list where each entry carries a written reason. An entry without a reason
is not accepted.

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
between: the exact order in which state attributes appear when a dialog opens,
frame by frame; the keyframes, durations, easings and delays reported by
`getAnimations()`; the focused element after every step; keyboard navigation
through the result list; hotkeys; scroll locking; click-outside; the hover
timing of the collapsed sidebar; and the result lists for a fixed set of search
queries, including their order and their highlighting.

<Callout type="info" title="Why a fixed question set">
Search is the one part where a plausible approximation would go unnoticed for
months. A frozen set of about forty queries, including word beginnings, umlauts,
two-word queries and deliberate misses, makes any divergence in tokenisation or
ranking visible on the next run.
</Callout>

## Running it

The comparison lives in `verify/` and needs Node and Playwright. It is the only
part of the project with dependencies, and it never reaches a published site.
