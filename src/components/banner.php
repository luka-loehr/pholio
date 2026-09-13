<?php

declare(strict_types=1);

namespace Pholio;

require_once __DIR__ . '/../lib/Html.php';
require_once __DIR__ . '/../lib/Icons.php';

/**
 * Announcement bar (`Banner`).
 *
 * The `<style>` elements sit inside the bar: the layout variable
 * `--fd-banner-height` (only with `changeLayout`), the hide rule for
 * `html.nd-banner-<id>` and, for `rainbow`, the `fd-moving-banner` keyframes.
 *
 * Not carried over is the inline `<script>` that sets `nd-banner-<id>` on
 * `<html>` before the first paint once the bar was closed: the Content Security
 * Policy forbids inline scripts. js/banner.js sets the class at startup and
 * removes the bar. A closed bar is therefore briefly visible until the module
 * loads.
 */

/** Base32 encoding of the banner id (alphabet a–z2–7, no padding). */
function nd_banner_base32(string $value): string
{
    $alphabet = 'abcdefghijklmnopqrstuvwxyz234567';
    $encoded = '';
    $buffer = 0;
    $bits = 0;
    // `charCodeAt` yields UTF-16 units; above 0xFF the buffer overflows on purpose, so ids stay stable.
    $units = unpack('v*', (string) mb_convert_encoding($value, 'UTF-16LE', 'UTF-8')) ?: [];
    foreach ($units as $unit) {
        $buffer = (($buffer << 8) | $unit) & 0xFFFFFFFF;
        $bits += 8;
        while ($bits >= 5) {
            $bits -= 5;
            $encoded .= $alphabet[($buffer >> $bits) & 31];
        }
    }
    if ($bits > 0) {
        $encoded .= $alphabet[($buffer << (5 - $bits)) & 31];
    }

    return $encoded;
}

/**
 * @param array{id?:?string, variant?:?string, height?:?string, changeLayout?:bool} $props
 */
function nd_banner(array $props, string $children, string $closeLabel): string
{
    $id = $props['id'] ?? null;
    $variant = $props['variant'] ?? 'normal';
    $height = $props['height'] ?? '3rem';
    $changeLayout = $props['changeLayout'] ?? true;
    $key = $id !== null && $id !== '' ? 'nd-banner-' . nd_banner_base32($id) : null;

    $inner = '';
    if ($changeLayout) {
        $inner .= Html::tag('style', [], $key !== null
            ? ':root:not(.' . $key . ') { --fd-banner-height: ' . $height . '; }'
            : ':root { --fd-banner-height: ' . $height . '; }');
    }
    if ($key !== null) {
        $inner .= Html::tag('style', [], '.' . $key . ' #' . $id . ' { display: none; }');
    }
    if ($variant === 'rainbow') {
        $inner .= nd_banner_flow();
    }
    $inner .= $children;
    if ($key !== null) {
        $inner .= Html::tag('button', [
            'type' => 'button',
            'aria-label' => $closeLabel,
            'class' => 'nd-btn nd-btn-ghost nd-banner-close',
        ], Icons::svg('x'));
    }

    return Html::tag('div', [
        'id' => $key !== null ? $id : null,
        'class' => $variant === 'rainbow' ? 'nd-banner nd-banner-rainbow' : 'nd-banner nd-banner-normal',
        'style' => ['height' => $height],
    ], $inner);
}

/** The moving rainbow gradient with the default colours. */
function nd_banner_flow(): string
{
    $colors = ['rgba(0,149,255,0.56)', 'rgba(231,77,255,0.77)', 'rgba(255,0,0,0.73)', 'rgba(131,255,166,0.66)'];
    $stops = [];
    foreach ([...$colors, $colors[0]] as $i => $color) {
        $stops[] = $color . ' ' . ($i * 50 / count($colors)) . '%';
    }

    $style = 'mask-image:linear-gradient(to bottom,white,transparent), radial-gradient(circle at top center, white, transparent);'
        . 'mask-composite:intersect;'
        . 'animation:fd-moving-banner 20s linear infinite;'
        . 'background-image:repeating-linear-gradient(70deg, ' . implode(', ', $stops) . ');'
        . 'background-size:200% 100%;'
        . 'filter:saturate(2)';

    return Html::tag('div', ['class' => 'nd-banner-flow', 'style' => $style])
        . Html::tag('style', [], "@keyframes fd-moving-banner {\n            from { background-position: 0% 0;  }\n"
            . "            to { background-position: 100% 0;  }\n         }");
}
