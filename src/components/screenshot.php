<?php

declare(strict_types=1);

namespace Pholio;

require_once __DIR__ . '/../lib/Html.php';

/**
 * Screenshot with a dark twin (`Screenshot`).
 *
 * Both images declare 1440 × 900, because screenshots are taken in a 1440 × 900
 * viewport at double resolution. The light image carries `dark:hidden` only when
 * there is a twin; the dark one is `aria-hidden` with an empty `alt` text.
 *
 * @param array{src:string,dark?:string|null,alt?:string|null,width?:int,height?:int} $props
 */
function nd_screenshot(array $props): string
{
    $dark = $props['dark'] ?? null;
    $hasDark = $dark !== null && $dark !== '';
    $width = (int) ($props['width'] ?? 1440);
    $height = (int) ($props['height'] ?? 900);

    $light = Html::voidTag('img', [
        'src' => $props['src'],
        'alt' => $props['alt'] ?? null,
        'width' => $width,
        'height' => $height,
        'loading' => 'lazy',
        'decoding' => 'async',
        'class' => $hasDark ? 'nd-figure-light nd-figure-twin' : 'nd-figure-light',
    ]);

    $twin = '';
    if ($hasDark) {
        $twin = Html::voidTag('img', [
            'src' => $dark,
            'alt' => '',
            'width' => $width,
            'height' => $height,
            'loading' => 'lazy',
            'decoding' => 'async',
            'aria-hidden' => 'true',
            'class' => 'nd-figure-dark',
        ]);
    }

    return Html::tag(
        'figure',
        ['class' => 'nd-figure'],
        $light . $twin
    );
}
