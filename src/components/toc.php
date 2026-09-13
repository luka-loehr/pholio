<?php

declare(strict_types=1);

namespace Pholio;

require_once __DIR__ . '/../I18n.php';
require_once __DIR__ . '/../lib/Html.php';
require_once __DIR__ . '/../lib/Icons.php';

/** Indentation of the entry text per heading depth. */
function nd_toc_item_offset(int $depth): int
{
    if ($depth <= 2) {
        return 20;
    }

    return $depth === 3 ? 32 : 44;
}

/** `getLineOffset`: horizontal position of the vertical line. */
function nd_toc_line_offset(int $depth): int
{
    if ($depth <= 2) {
        return 8;
    }

    return $depth === 3 ? 20 : 32;
}

/**
 * The table of contents in the main column, with the line graphic.
 *
 * Without headings only a placeholder is rendered that keeps the column width
 * at 0. The progress thumb is missing here on purpose: js/toc.js creates it once
 * it has measured the elements.
 *
 * @param list<array{depth:int, title:string, url:string}> $items
 */
function nd_toc(array $items): string
{
    if ($items === []) {
        return Html::tag('div', [
            'id' => 'nd-toc-placeholder',
            'class' => 'nd-toc-empty',
        ], '');
    }

    $title = Html::tag('h3', [
        'id' => 'toc-title',
        'class' => 'nd-toc-title',
    ], Icons::svg('text', 'nd-toc-title-icon') . Html::e(I18n::t('On this page(table of contents)')));

    $list = '';
    foreach ($items as $i => $item) {
        $list .= nd_toc_item($items, $i);
    }

    $scroll = nd_toc_scroll_area(Html::tag('div', ['class' => 'nd-toc-list'], $list));

    return Html::tag('div', [
        'id' => 'nd-toc',
        'class' => 'nd-toc',
    ], $title . $scroll);
}

/** `TOCScrollArea`: the scrollable area with a 16 px mask at the top and bottom. */
function nd_toc_scroll_area(string $children): string
{
    return Html::tag('div', [
        'class' => 'nd-toc-scroll',
    ], $children);
}

/**
 * One entry with its line graphic.
 *
 * The graphic depends only on the depths of the previous entry, this entry and
 * the next one, so it can be computed statically. When the depth changes towards
 * the next entry, `bottom-1.5 h-full` replaces `bottom-0` and the height class;
 * that is why there are two separate class lists instead of a concatenation.
 *
 * @param list<array{depth:int, title:string, url:string}> $items
 */
function nd_toc_item(array $items, int $index): string
{
    $item = $items[$index];
    $isFirst = $index === 0;
    $isLast = $index === count($items) - 1;

    $l1 = nd_toc_line_offset($item['depth']);
    $l0 = $isFirst ? $l1 : nd_toc_line_offset($items[$index - 1]['depth']);
    $l2 = $isLast ? $l1 : nd_toc_line_offset($items[$index + 1]['depth']);

    $path = $l0 !== $l1
        ? Html::tag('path', [
            'd' => 'M ' . ($l0 + 0.5) . ' 0 L ' . ($l0 + 0.5) . ' 0 ' . ($l1 + 0.5) . ' 12',
            'stroke' => 'black',
            'stroke-width' => '1',
            'fill' => 'none',
            'class' => 'nd-toc-line-stroke',
        ], '')
        : '';
    $line = Html::tag('line', [
        'x1' => $l1 + 0.5,
        'y1' => $l0 === $l1 ? '6' : '12',
        'x2' => $l1 + 0.5,
        'y2' => '100%',
        'stroke-width' => '1',
        'class' => 'nd-toc-line-stroke',
    ], '');

    $svg = Html::tag('svg', [
        'xmlns' => 'http://www.w3.org/2000/svg',
        'class' => $l1 !== $l2 ? 'nd-toc-line nd-toc-line-turn' : 'nd-toc-line',
        'style' => ['width' => max($l0, $l1) + 9 . 'px'],
    ], $path . $line);

    return Html::tag('a', [
        'href' => $item['url'],
        // Which entries are active is decided later by the IntersectionObserver.
        'data-active' => 'false',
        // The first and last entry lose their padding through :first-of-type/:last-of-type rules in
        // components.css; the class `prose` contributes only five declarations,
        // which .nd-toc-item there contains as well.
        'class' => 'nd-toc-item',
        'style' => ['padding-inline-start' => nd_toc_item_offset($item['depth']) . 'px'],
    ], $svg . Html::e($item['title']));
}
