<?php

declare(strict_types=1);

namespace Pholio;

require_once __DIR__ . '/../lib/Html.php';
require_once __DIR__ . '/../lib/Icons.php';
require_once __DIR__ . '/../lib/Ids.php';

/**
 * Type table (`TypeTable` and `TypeProp`).
 *
 * Every row is a Base UI collapsible. While closed the panel isn't mounted and
 * sits in `<template data-collapsible-panel>` (js/collapsible.js). Every row
 * carries `nd-typetable-item`, and the CSS styles the open and closed states
 * from the root's `data-open`.
 *
 * The arrow doesn't rotate: its group is the trigger, which carries
 * `data-panel-open`, never `data-open`.
 *
 * The table has no `id` (the Pholio syntax has none), so the rows have no id
 * and are not opened by the URL hash.
 */

/**
 * @param list<string> $rows rendered rows (`nd_type_prop`)
 * @param array{prop:string, type:string} $labels
 */
function nd_type_table(array $rows, array $labels): string
{
    $head = Html::tag(
        'div',
        ['class' => 'nd-typetable-head not-prose'],
        Html::tag('p', ['class' => 'nd-typetable-head-name'], Html::e($labels['prop']))
        . Html::tag('p', ['class' => 'nd-typetable-hide-narrow'], Html::e($labels['type']))
    );

    return Html::tag('div', ['class' => 'nd-typetable'], $head . implode('', $rows));
}

/**
 * @param array{name:string, type:string, default?:?string, typeDescription?:?string,
 *              typeDescriptionLink?:?string, required?:bool, deprecated?:bool} $props
 *        `typeDescriptionLink` already rewritten
 * @param string $description rendered description
 * @param array{type:string, default:string} $labels
 */
function nd_type_prop(array $props, string $description, array $labels): string
{
    $required = (bool) ($props['required'] ?? false);
    $deprecated = (bool) ($props['deprecated'] ?? false);

    // `[name, !required && "?"]`: the two text nodes are separated by a comment.
    $name = Html::e($props['name']) . ($required ? '' : '<!-- -->?');
    $code = Html::tag('code', [
        'class' => $deprecated ? 'nd-typetable-name-deprecated' : 'nd-typetable-name',
    ], $name);

    $link = $props['typeDescriptionLink'] ?? null;
    if ($link !== null && $link !== '') {
        $linkAttrs = ['href' => $link, 'class' => 'nd-typetable-hide-narrow nd-typetable-type-link'];
        if (Html::isExternal($link)) {
            $linkAttrs['rel'] = 'noreferrer noopener';
            $linkAttrs['target'] = '_blank';
        }
        $type = Html::tag('a', $linkAttrs, Html::e($props['type']));
    } else {
        $type = Html::tag('span', ['class' => 'nd-typetable-hide-narrow'], Html::e($props['type']));
    }

    $trigger = Html::tag('button', [
        'type' => 'button',
        'tabindex' => '0',
        'aria-disabled' => 'false',
        'aria-expanded' => 'false',
        'class' => 'nd-typetable-trigger not-prose',
    ], $code . $type . Icons::svg('chevron-down', 'nd-typetable-chevron'));

    $body = Html::tag('div', ['class' => 'nd-typetable-desc prose prose-no-margin'], $description);
    foreach ([['typeDescription', $labels['type']], ['default', $labels['default']]] as [$key, $label]) {
        $value = $props[$key] ?? null;
        if ($value === null || $value === '') {
            continue;
        }
        $body .= Html::tag('p', ['class' => 'nd-typetable-field not-prose'], Html::e($label))
            . Html::tag('p', ['class' => 'nd-typetable-value not-prose'], Html::e($value));
    }

    $panel = Html::tag(
        'template',
        ['data-collapsible-panel' => true],
        Html::tag(
            'div',
            ['id' => Ids::next(), 'class' => 'nd-collapsible-panel'],
            Html::tag('div', ['class' => 'nd-typetable-body fd-scroll-container'], $body)
        )
    );

    return Html::tag('div', ['data-closed' => true, 'class' => 'nd-typetable-item'], $trigger . $panel);
}
