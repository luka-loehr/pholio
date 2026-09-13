<?php

declare(strict_types=1);

namespace Pholio;

require_once __DIR__ . '/../lib/Html.php';

/**
 * Zoomable image, `ImageZoom` from `fumadocs-ui/dist/components/image-zoom.js`:
 * `Uncontrolled` from react-medium-image-zoom 5.4.9 with `zoomMargin: 20` and
 * `wrapElement: "span"`, holding `Image` from `fumadocs-core/framework` (next/image).
 *
 * The static page carries only what the hydrated reference shows before the
 * image has loaded: wrapper and content with `data-rmiz-content="not-found"`.
 * Ghost, zoom button and dialog portal are created by js/image-zoom.js once the
 * image is decoded.
 *
 * Deviation from the reference: next/image serves `src`/`srcset` through its
 * image optimiser (`/_next/image?url=…&w=…`), which doesn't exist without Node.
 * The rebuild writes the real path and no `srcset`. `sizes`, `data-nimg` and
 * `style="color:transparent"` stay as in the original.
 *
 * @param array{src:string, alt?:?string, width?:?int, height?:?int} $props
 */
function nd_image_zoom(array $props): string
{
    $img = Html::voidTag('img', [
        'alt' => $props['alt'] ?? '',
        'loading' => 'lazy',
        'width' => $props['width'] ?? null,
        'height' => $props['height'] ?? null,
        'decoding' => 'async',
        'data-nimg' => '1',
        'style' => ['color' => 'transparent'],
        'sizes' => '(max-width: 768px) 100vw, (max-width: 1200px) 70vw, 900px',
        'src' => $props['src'],
    ]);

    return Html::tag(
        'span',
        ['data-rmiz' => true],
        Html::tag('span', ['data-rmiz-content' => 'not-found', 'style' => ['visibility' => 'visible']], $img)
    );
}
