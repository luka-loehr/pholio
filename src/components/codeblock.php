<?php

declare(strict_types=1);

namespace Pholio;

require_once __DIR__ . '/../lib/Html.php';
require_once __DIR__ . '/../lib/Icons.php';

/**
 * Code block frame, `CodeBlock` and `Pre` from `fumadocs-ui/dist/components/codeblock.js`.
 *
 * Only the frame: a `figure` with a title bar (language icon, `figcaption`, copy
 * button) or a floating copy button, the scrollable area and `pre`. Colours,
 * the `shiki` classes including `has-highlighted`/`has-diff`, the `--shiki-*`
 * variables and the content of `pre` come from lib/Highlight.php.
 *
 * Variants of the figure classes (tailwind-merge in the original):
 *   standalone        my-4 bg-fd-card rounded-xl
 *   inside a code tab bg-fd-secondary -mx-px -mb-px last:rounded-b-xl
 *   DynamicCodeBlock  bg-fd-card rounded-xl my-0
 *
 * @param array{title?:?string, icon?:?string, allowCopy?:bool, variant:string, style?:string|array<string,string>,
 *              figureClass:list<string>, figureAttrs?:array<string,mixed>, style?:string,
 *              lineNumbersStart?:?int} $props
 *        `icon` is finished SVG markup (fumadocs transformerIcon), `figureClass` the Shiki classes
 *        (`preClass` from Highlight::code), `style` the `--shiki-*` variables (`preStyle`).
 * @param string $code content of `pre` (the `code` lines)
 * @param string $copyLabel `Copy Text(code block)(aria-label)`
 */
function nd_code_block(array $props, string $code, string $copyLabel): string
{
    $variants = [
        'standalone' => 'nd-codeblock nd-codeblock-card nd-codeblock-spaced',
        'tab' => 'nd-codeblock nd-codeblock-tab',
        'dynamic' => 'nd-codeblock nd-codeblock-card nd-codeblock-flush',
    ];
    if (!isset($variants[$props['variant']])) {
        throw new \LogicException('unknown code block variant: ' . $props['variant']);
    }

    $allowCopy = $props['allowCopy'] ?? true;
    $copy = $allowCopy
        ? Html::tag('button', [
            'type' => 'button',
            'class' => 'nd-btn nd-btn-icon-xs nd-codeblock-copy',
            'aria-label' => $copyLabel,
        ], Icons::svg('clipboard'))
        : '';

    $title = $props['title'] ?? null;
    if ($title !== null && $title !== '') {
        $bar = '';
        if (($props['icon'] ?? null) !== null) {
            $bar .= Html::tag('div', ['class' => 'nd-codeblock-icon'], (string) $props['icon']);
        }
        $bar .= Html::tag('figcaption', ['class' => 'nd-codeblock-caption'], Html::e($title));
        $bar .= Html::tag('div', ['class' => 'nd-codeblock-actions nd-codeblock-actions-inline'], $copy);
        $head = Html::tag('div', ['class' => 'nd-codeblock-title'], $bar);
    } else {
        $head = Html::tag('div', ['class' => 'nd-codeblock-actions nd-codeblock-actions-float'], $copy);
    }

    $start = $props['lineNumbersStart'] ?? null;
    $viewport = Html::tag('div', [
        'role' => 'region',
        'tabindex' => '0',
        'class' => 'nd-codeblock-viewport fd-scroll-container',
        'style' => [
            '--padding-right' => $title === null || $title === '' ? 'calc(var(--spacing) * 8)' : null,
            'counter-set' => $start !== null ? 'line ' . ($start - 1) : null,
        ],
    ], Html::tag('pre', ['class' => 'nd-codeblock-pre'], $code));

    return Html::tag('figure', [
        'dir' => 'ltr',
        ...($props['figureAttrs'] ?? []),
        // CodeBlock sets `shiki` itself and Shiki repeats it in `preClass`: twice, as in the reference.
        'class' => [$variants[$props['variant']], 'shiki', 'not-prose', ...$props['figureClass']],
        'style' => $props['style'] ?? [],
        'tabindex' => '-1',
    ], $head . $viewport);
}
