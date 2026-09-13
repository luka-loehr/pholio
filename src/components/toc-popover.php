<?php

declare(strict_types=1);

namespace Pholio;

require_once __DIR__ . '/../lib/Html.php';
require_once __DIR__ . '/../lib/Icons.php';
require_once __DIR__ . '/../lib/Ids.php';
require_once __DIR__ . '/toc.php';

/**
 * The TOC popover below `xl` (`PageTOCPopoverTrigger` in
 * `layouts/notebook/page/slots/toc.js`).
 *
 * While closed, Base UI renders only the trigger; the panel appears only on
 * opening and is therefore missing from the reference DOM as well. The generator
 * puts it as `<template data-collapsible-panel>` after the trigger inside the
 * `header` (structure as the reference's open panel at 1024 px);
 * js/collapsible.js mounts it on opening. The progress thumb inside is created
 * by js/toc.js, because it depends on measured row heights.
 * The progress circle and the switch between the two stacked labels depend on
 * the active heading, which only JavaScript knows; the generator writes the state
 * "first heading active", which the reference also shows after hydration at the
 * top of the page.
 *
 * @param list<array{depth:int, title:string, url:string}> $items
 */
function nd_toc_popover(array $items, string $pageName): string
{
    if ($items === []) {
        return '';
    }

    $trigger = Html::tag('button', [
        'type' => 'button',
        'data-toc-popover-trigger' => true,
        'aria-disabled' => 'false',
        'aria-expanded' => 'false',
        'tabindex' => '0',
        'class' => 'nd-tocpop-trigger',
    ], nd_progress_circle() . nd_toc_popover_labels($pageName, $items[0]['title'])
        . Icons::svg('chevron-down', 'nd-tocpop-chevron'));

    $header = Html::tag('header', ['class' => 'nd-tocpop-header'], $trigger . nd_toc_popover_panel($items));

    return Html::tag('div', [
        'data-toc-popover' => true,
        'data-closed' => true,
        'class' => 'nd-tocpop',
    ], $header);
}

/**
 * The panel of the popover as a template: the same entries as the TOC column,
 * inside `nd-tocpop-content` with `max-h-[50vh]`.
 *
 * @param list<array{depth:int, title:string, url:string}> $items
 */
function nd_toc_popover_panel(array $items): string
{
    $list = '';
    foreach ($items as $i => $item) {
        $list .= nd_toc_item($items, $i);
    }

    $panel = Html::tag('div', [
        'id' => Ids::next(),
        'data-toc-popover-content' => true,
        'class' => 'nd-collapsible-panel',
        'style' => [
            '--collapsible-panel-height' => 'auto',
            '--collapsible-panel-width' => 'auto',
        ],
    ], Html::tag('div', ['class' => 'nd-tocpop-content'], nd_toc_scroll_area(
        Html::tag('div', ['class' => 'nd-toc-list'], $list),
    )));

    return Html::tag('template', ['data-collapsible-panel' => true], $panel);
}

/**
 * The two stacked labels: page name and active heading. Initial state as in
 * the reference after hydration at the top of the page: the page name is pushed
 * up out of view (`nd-tocpop-label-up`); js/toc-popover.js switches it.
 */
function nd_toc_popover_labels(string $pageName, string $heading): string
{
    $inner = Html::tag('span', [
        'class' => 'nd-tocpop-label-page nd-tocpop-label-up',
    ], Html::e($pageName))
        . Html::tag('span', ['class' => 'nd-tocpop-label-active'], Html::e($heading));

    return Html::tag('span', ['class' => 'nd-tocpop-labels'], $inner);
}

/**
 * The progress circle (`ProgressCircle`, `size 18`, `strokeWidth 1.5`).
 *
 * Without JavaScript the progress is 0; `js/toc-popover.js` corrects
 * `aria-valuenow` and `stroke-dashoffset`.
 */
function nd_progress_circle(): string
{
    $radius = 18 / 2 - 1.5;
    $circumference = 2 * M_PI * $radius;

    $base = ['cx' => 9, 'cy' => 9, 'r' => nd_number($radius), 'fill' => 'none', 'stroke-width' => nd_number(1.5)];
    $ring = Html::tag('circle', [...$base, 'class' => 'nd-tocpop-ring'], '');
    $progress = Html::tag('circle', [
        ...$base,
        'stroke' => 'currentColor',
        'stroke-dasharray' => nd_number($circumference),
        'stroke-dashoffset' => nd_number($circumference),
        'stroke-linecap' => 'round',
        'transform' => 'rotate(-90 9 9)',
        'class' => 'nd-tocpop-bar',
    ], '');

    return Html::tag('svg', [
        'role' => 'progressbar',
        'viewBox' => '0 0 18 18',
        'aria-valuenow' => '0',
        'aria-valuemin' => '0',
        'aria-valuemax' => '1',
        'class' => 'nd-tocpop-progress',
        'style' => ['width' => '18px', 'height' => '18px'],
    ], $ring . $progress);
}

/** A number as JavaScript writes it into an attribute (shortest round-trip form). */
function nd_number(float $value): string
{
    return (string) json_encode($value);
}
