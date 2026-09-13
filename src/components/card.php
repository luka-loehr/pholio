<?php

declare(strict_types=1);

namespace Pholio;

require_once __DIR__ . '/../lib/Html.php';

/**
 * Card grid, `Cards` from the reference UI's `dist/components/card.js`.
 */
function nd_cards(array $props, string $children): string
{
    return Html::tag('div', ['class' => 'nd-cards'], $children);
}

/**
 * A single card, `Card` from card.js.
 *
 * With `href` the element becomes an `a` (through the reference `link` helper, so with
 * `rel`/`target` only for external targets), otherwise a `div`. `data-card` is
 * always present and renders as `data-card="true"`, because React prints the
 * boolean that way. The description is a `p`; the child `div` stays in the DOM
 * even when empty (`empty:hidden` hides it through CSS).
 *
 * @param array{title?:string,description?:string|null,href?:string|null,icon?:string|null} $props
 */
function nd_card(array $props, string $children = ''): string
{
    $href = $props['href'] ?? null;
    $isLink = $href !== null && $href !== '';

    $class = Html::classes(['nd-card', $isLink ? 'nd-card-link' : null]);

    $inner = '';
    $icon = $props['icon'] ?? null;
    if ($icon !== null && $icon !== '') {
        $inner .= Html::tag(
            'div',
            ['class' => 'nd-card-icon not-prose'],
            $icon
        );
    }

    $inner .= Html::tag('h3', ['class' => 'nd-card-title not-prose'], (string) ($props['title'] ?? ''));

    $description = $props['description'] ?? null;
    if ($description !== null && $description !== '') {
        $inner .= Html::tag('p', ['class' => 'nd-card-desc'], $description);
    }

    $inner .= Html::tag('div', ['class' => 'nd-card-body prose-no-margin'], $children);

    if (!$isLink) {
        return Html::tag('div', ['data-card' => 'true', 'class' => $class], $inner);
    }

    $attrs = ['data-card' => 'true', 'class' => $class, 'href' => $href];
    if (Html::isExternal((string) $href)) {
        $attrs['rel'] = 'noreferrer noopener';
        $attrs['target'] = '_blank';
    }

    return Html::tag('a', $attrs, $inner);
}
