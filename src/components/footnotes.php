<?php

declare(strict_types=1);

namespace Pholio;

require_once __DIR__ . '/../I18n.php';
require_once __DIR__ . '/../lib/Html.php';
require_once __DIR__ . '/../lib/Icons.php';
require_once __DIR__ . '/heading.php';

/**
 * GFM footnotes, the output of `mdast-util-to-hast`:
 * `lib/handlers/footnote-reference.js` (reference in the text) and `lib/footer.js`
 * (section at the end of the page) with the defaults `clobberPrefix: "user-content-"`,
 * `footnoteLabel: "Footnotes"`, `footnoteLabelTagName: "h2"`,
 * `footnoteLabelProperties: {className: ["sr-only"]}`.
 *
 * The label renders like every heading, with an anchor and a copy button, but
 * keeps its fixed id `footnote-label` and the class `sr-only`. The theme has no
 * rule for `sr-only`, so the label is visible. The label "Footnotes" stays
 * English; the back link label comes from `Back to reference(footnote)(aria-label)`.
 */

/** `normalizeUri` from micromark-util-sanitize-uri: percent-encode everything but the safe characters. */
function nd_footnote_safe_id(string $identifier): string
{
    return (string) preg_replace_callback(
        '/%(?![0-9A-Fa-f]{2})|[^A-Za-z0-9!#$&\'()*+,\-.\/:;=?@_~%]/u',
        static fn (array $m): string => rawurlencode($m[0]),
        mb_strtolower(mb_strtoupper($identifier, 'UTF-8'), 'UTF-8')
    );
}

/** Reference `<sup><a …>n</a></sup>`; `$reuse` counts from 1 per footnote. */
function nd_footnote_ref(string $safeId, int $counter, int $reuse): string
{
    return Html::tag('sup', [], Html::tag('a', [
        'href' => '#user-content-fn-' . $safeId,
        'id' => 'user-content-fnref-' . $safeId . ($reuse > 1 ? '-' . $reuse : ''),
        'data-footnote-ref' => 'true',
        'aria-describedby' => 'footnote-label',
    ], (string) $counter));
}

/**
 * Back links of a footnote (`defaultFootnoteBackContent`/`…BackLabel`).
 *
 * @param int $index position of the footnote in reference order, from 0
 */
function nd_footnote_backrefs(string $safeId, int $index, int $count): string
{
    $label = I18n::t('Back to reference(footnote)(aria-label)');
    $out = '';
    for ($reuse = 1; $reuse <= $count; $reuse++) {
        if ($reuse > 1) {
            $out .= ' ';
        }
        $out .= Html::tag('a', [
            'href' => '#user-content-fnref-' . $safeId . ($reuse > 1 ? '-' . $reuse : ''),
            'data-footnote-backref' => true,
            'aria-label' => $label . ' ' . ($index + 1) . ($reuse > 1 ? '-' . $reuse : ''),
            'class' => 'data-footnote-backref',
        ], '↩' . ($reuse > 1 ? Html::tag('sup', [], (string) $reuse) : ''));
    }

    return $out;
}

/**
 * The `section.footnotes`.
 *
 * @param list<array{safeId:string, html:string}> $items `html` is the finished content of the `li`
 */
function nd_footnotes_section(array $items, string $copyLabel): string
{
    $heading = Html::tag('h2', [
        'class' => 'nd-heading sr-only',
        'id' => 'footnote-label',
    ], Html::tag('a', ['data-card' => '', 'href' => '#footnote-label'], 'Footnotes')
        . Html::tag('button', ['aria-label' => $copyLabel, 'class' => nd_heading_button_class()], Icons::svg('link')));

    $list = '';
    foreach ($items as $item) {
        $list .= Html::tag('li', ['id' => 'user-content-fn-' . $item['safeId']], $item['html']);
    }

    return Html::tag('section', ['data-footnotes' => 'true', 'class' => 'footnotes'], $heading . Html::tag('ol', [], $list));
}
