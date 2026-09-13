<?php

declare(strict_types=1);

namespace Pholio;

require_once __DIR__ . '/../lib/Html.php';
require_once __DIR__ . '/../lib/Icons.php';
require_once __DIR__ . '/../lib/Ids.php';

/**
 * Collapsible table of contents inside the text, `InlineTOC` from
 * `fumadocs-ui/dist/components/inline-toc.js` (Base UI `Collapsible`).
 *
 * While closed the list isn't mounted and sits in
 * `<template data-collapsible-panel>` (js/collapsible.js). The arrow carries
 * `group-data-open:rotate-180`, but the group is the trigger with
 * `data-panel-open`, so in the reference it never rotates.
 *
 * @param list<array{depth:int, title:string, url:string}> $items
 */
function nd_inline_toc(string $label, array $items): string
{
    $trigger = Html::tag('button', [
        'type' => 'button',
        'tabindex' => '0',
        'aria-disabled' => 'false',
        'aria-expanded' => 'false',
        'class' => 'nd-inlinetoc-trigger',
    ], Html::e($label) . Icons::svg('chevron-down', 'nd-inlinetoc-chevron'));

    $links = '';
    foreach ($items as $item) {
        // `paddingInlineStart: 12 * max(depth - 1, 0)`; React writes 0 without a unit.
        $padding = 12 * max($item['depth'] - 1, 0);
        $links .= Html::tag('a', [
            'href' => $item['url'],
            'class' => 'nd-inlinetoc-item',
            'style' => ['padding-inline-start' => $padding === 0 ? '0' : $padding . 'px'],
        ], Html::e($item['title']));
    }

    $panel = Html::tag(
        'template',
        ['data-collapsible-panel' => true],
        Html::tag(
            'div',
            ['id' => Ids::next(), 'class' => 'nd-collapsible-panel'],
            Html::tag('div', ['class' => 'nd-inlinetoc-list'], $links)
        )
    );

    return Html::tag('div', ['data-closed' => true, 'class' => 'nd-inlinetoc not-prose'], $trigger . $panel);
}
