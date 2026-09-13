<?php

declare(strict_types=1);

namespace Pholio;

require_once __DIR__ . '/../lib/Html.php';
require_once __DIR__ . '/../lib/Icons.php';

/**
 * Heading in the article, rebuilt from `fumadocs-ui/dist/components/heading.js`.
 *
 * Without `id` the original prints the bare heading. With `id` it gets the group
 * classes, an anchor `a[data-card]` around the text and the copy button on the
 * right (`buttonVariants({variant:"ghost", size:"icon-xs"})` plus the extra classes
 * from heading.js). The icon is `Link`; the JavaScript swaps it for `CopyCheck`
 * for 1.5 s.
 *
 * @param array{level:int,id?:string|null,copyLabel?:string} $props
 */
function nd_heading(array $props, string $children): string
{
    $tag = 'h' . (int) $props['level'];
    $id = $props['id'] ?? null;

    if ($id === null || $id === '') {
        return Html::tag($tag, [], $children);
    }

    $anchor = Html::tag('a', ['data-card' => '', 'href' => '#' . $id], $children);
    $button = Html::tag(
        'button',
        [
            'aria-label' => $props['copyLabel'] ?? '',
            'class' => nd_heading_button_class(),
        ],
        Icons::svg('link')
    );

    return Html::tag(
        $tag,
        [
            'id' => $id,
            'class' => 'nd-heading',
        ],
        $anchor . $button
    );
}

/**
 * Class list of the copy button: `buttonVariants({variant:"ghost", size:"icon-xs"})`
 * from `components/ui/button.js`, followed by the additions from heading.js.
 * `not-prose` keeps its reference name, because the typography rules point at it.
 */
function nd_heading_button_class(): string
{
    return 'nd-btn nd-btn-ghost nd-btn-icon-xs nd-heading-copy not-prose';
}
