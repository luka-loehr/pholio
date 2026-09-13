<?php

declare(strict_types=1);

namespace Pholio;

require_once __DIR__ . '/../lib/Html.php';

/**
 * Inline image.
 *
 * The image is passed through unchanged with `loading`, `decoding` and the frame
 * classes. `width`/`height` are measured from the real image file and are
 * omitted when the size is unknown.
 *
 * @param array{src:string,alt?:string|null,width?:int|null,height?:int|null} $props
 */
function nd_image(array $props): string
{
    return Html::voidTag('img', [
        'alt' => $props['alt'] ?? null,
        'src' => $props['src'],
        'width' => $props['width'] ?? null,
        'height' => $props['height'] ?? null,
        'loading' => 'lazy',
        'decoding' => 'async',
        'class' => 'nd-image',
    ]);
}
