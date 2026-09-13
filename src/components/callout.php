<?php

declare(strict_types=1);

namespace Pholio;

require_once __DIR__ . '/../lib/Html.php';
require_once __DIR__ . '/../lib/Icons.php';

/**
 * Callout box, rebuilt from `fumadocs-ui/dist/components/callout.js`
 * (`Callout` = `CalloutContainer` + `CalloutTitle` + `CalloutDescription`).
 *
 * Structure: a container with `--callout-color`, inside it the colour stripe
 * (`role="none"`), the type icon and a column of optional title and description.
 * The description stays in the DOM even when empty, because `empty:hidden`
 * hides it through CSS.
 *
 * @param array{type?:string|null,title?:string|null} $props the title is already rendered HTML
 */
function nd_callout(array $props, string $children): string
{
    $type = nd_callout_type((string) ($props['type'] ?? 'info'));

    $stripe = Html::tag('div', ['role' => 'none', 'class' => 'nd-callout-stripe']);
    $icon = nd_callout_icon($type);

    $inner = '';
    $title = $props['title'] ?? null;
    if ($title !== null && $title !== '') {
        $inner .= Html::tag('p', ['class' => 'nd-callout-title'], $title);
    }
    $inner .= Html::tag('div', ['class' => 'nd-callout-desc prose-no-margin'], $children);

    return Html::tag(
        'div',
        [
            'class' => 'nd-callout',
            'style' => ['--callout-color' => 'var(--color-fd-' . $type . ', var(--color-fd-muted))'],
        ],
        $stripe . $icon . Html::tag('div', ['class' => 'nd-callout-body'], $inner)
    );
}

/** Aliases as in `resolveAlias` in callout.js: `warn` becomes `warning`, `tip` becomes `info`. */
function nd_callout_type(string $type): string
{
    if ($type === '') {
        return 'info';
    }
    if ($type === 'warn') {
        return 'warning';
    }
    if ($type === 'tip') {
        return 'info';
    }

    return $type;
}

/** The type icon. `idea` brings its own colour, all others use the card colour. */
function nd_callout_icon(string $type): string
{
    $names = [
        'info' => 'info',
        'warning' => 'triangle-alert',
        'error' => 'circle-x',
        'success' => 'circle-check',
        'idea' => 'lightbulb',
    ];

    if (!isset($names[$type])) {
        return '';
    }

    $class = $type === 'idea' ? 'nd-callout-icon nd-callout-icon-idea' : 'nd-callout-icon';

    return Icons::svg($names[$type], $class);
}
