<?php

declare(strict_types=1);

namespace Pholio;

require_once __DIR__ . '/../lib/Html.php';

/**
 * Zoomable image (`ImageZoom`), with the markup and behaviour of
 * react-medium-image-zoom 5.4.9 (`zoomMargin: 20`, `wrapElement: "span"`).
 *
 * The static page carries only the state before the image has loaded: wrapper
 * and content with `data-rmiz-content="not-found"`.
 * Ghost, zoom button and dialog portal are created by js/image-zoom.js once the
 * image is decoded.
 *
 * The image is served from its real path, without `srcset`: a static site has
 * no image optimiser. `sizes`, `data-nimg` and `style="color:transparent"` are
 * kept because the styles select on them.
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
