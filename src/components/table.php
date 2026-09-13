<?php

declare(strict_types=1);

namespace Pholio;

require_once __DIR__ . '/../lib/Html.php';

/**
 * Table: a scrollable
 * frame around the unchanged table.
 */
function nd_table(array $props, string $children): string
{
    return Html::tag(
        'div',
        ['class' => 'nd-table prose-no-margin'],
        Html::tag('table', [], $children)
    );
}

/**
 * Cell with alignment. `mdast-util-to-hast` writes the alignment as
 * `style="text-align:…"`, not as an `align` attribute; without alignment the
 * cell has no attributes.
 */
function nd_table_cell(string $tag, ?string $align, string $children): string
{
    $attrs = [];
    if ($align !== null && $align !== '') {
        $attrs['style'] = ['text-align' => $align];
    }

    return Html::tag($tag, $attrs, $children);
}
