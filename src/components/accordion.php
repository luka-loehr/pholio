<?php

declare(strict_types=1);

namespace Pholio;

require_once __DIR__ . '/../lib/Html.php';
require_once __DIR__ . '/../lib/Icons.php';
require_once __DIR__ . '/../lib/Ids.php';

/**
 * Accordion list (`Accordions`, `Accordion`, a copy-link button per item) on the
 * markup of Base UI `Accordion` (@base-ui/react 1.8.0).
 *
 * Details that are deliberate:
 * - `Accordions` only passes `type` through; Base UI doesn't know it and writes
 *   it as an attribute on the root. So there is never multiple selection
 *   (`multiple` stays false), not even with `type="multiple"`.
 * - Panels stay mounted while closed (`hiddenUntilFound`): `hidden="until-found"`,
 *   `data-starting-style`, height variables `auto`.
 * - Initially the trigger carries `data-value=""` and the first item has no
 *   `data-index`; only opening writes the value (js/accordion.js).
 * - Trigger and panel ids come from one `useId` with the suffixes `H2` (trigger)
 *   and `H1` (panel).
 */

/**
 * @param array{type?:?string, defaultValue?:list<string>} $props
 * @param list<array{title:string, id:?string, value:?string, html:string}> $items titles unescaped
 * @param string $copyLabel aria-label of the copy button (`Copy Link(accordion)(aria-label)`)
 */
function nd_accordions(array $props, array $items, string $copyLabel): string
{
    $open = $props['defaultValue'] ?? [];
    $out = '';
    foreach ($items as $index => $item) {
        $out .= nd_accordion_item($index, $item, in_array($item['value'] ?? $item['title'], $open, true), $copyLabel);
    }

    return Html::tag('div', [
        'data-orientation' => 'vertical',
        'type' => $props['type'] ?? null,
        'class' => 'nd-accordions',
    ], $out);
}

/** @param array{title:string, id:?string, value:?string, html:string} $item */
function nd_accordion_item(int $index, array $item, bool $open, string $copyLabel): string
{
    $value = $item['value'] ?? $item['title'];
    // One useId, two derivations: `…H1_` for the panel, `…H2_` for the trigger.
    $base = substr(Ids::next(), 0, -1);
    $panelId = $base . 'H1_';
    $triggerId = $base . 'H2_';

    $state = [
        'data-orientation' => 'vertical',
        'data-hidden' => !$open,
        'data-index' => (string) $index,
        'data-open' => $open,
        'data-closed' => !$open,
    ];

    $chevron = Icons::svg('chevron-right', 'nd-accordion-chevron');
    $trigger = Html::tag('button', [
        'type' => 'button',
        'data-value' => $open ? $value : '',
        'data-orientation' => 'vertical',
        'data-hidden' => !$open,
        'data-index' => $index > 0 ? (string) $index : null,
        'tabindex' => '0',
        'aria-disabled' => 'false',
        'aria-expanded' => $open ? 'true' : 'false',
        'id' => $triggerId,
        'data-panel-open' => $open,
        'aria-controls' => $open ? $panelId : null,
        'class' => 'nd-accordion-trigger',
    ], $chevron . Html::e($item['title']));

    $copy = '';
    if ($item['id'] !== null && $item['id'] !== '') {
        $copy = Html::tag('button', [
            'type' => 'button',
            'aria-label' => $copyLabel,
            'class' => 'nd-btn nd-btn-ghost nd-accordion-copy',
        ], Icons::svg('link', 'nd-accordion-copy-icon'));
    }

    $header = Html::tag('h3', [
        ...$state,
        'id' => $item['id'] ?: null,
        'data-accordion-value' => $value,
        'class' => 'nd-accordion-header not-prose',
    ], $trigger . $copy);

    $panel = Html::tag('div', [
        ...$state,
        'data-starting-style' => !$open,
        'hidden' => $open ? null : 'until-found',
        'id' => $panelId,
        'aria-labelledby' => $triggerId,
        'role' => 'region',
        'style' => [
            '--accordion-panel-height' => 'auto',
            '--accordion-panel-width' => 'auto',
            'animation-name' => $open ? 'none' : null,
        ],
        'class' => 'nd-accordion-panel',
    ], Html::tag('div', ['class' => 'nd-accordion-content prose-no-margin'], $item['html']));

    return Html::tag('div', $state, $header . $panel);
}
