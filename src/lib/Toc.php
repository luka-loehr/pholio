<?php

declare(strict_types=1);

namespace Pholio;

require_once __DIR__ . '/Slug.php';

/**
 * Table of contents of a page, the same shape as `fumadocs-core/toc`:
 * a flat list `{ depth, title, url }`; the depth is the heading level
 * (`##` = 2), the URL the anchor `#slug` from `rehype-slug` (github-slugger).
 *
 * Slugs are counted per document; duplicate headings get `-1`, `-2`.
 */
final class Toc
{
    /**
     * @param list<array{level:int, text:string}> $headings headings in document order
     * @return list<array{depth:int, title:string, url:string}>
     */
    public static function build(array $headings, ?Slugger $slugger = null): array
    {
        $slugger ??= new Slugger();
        $items = [];

        foreach ($headings as $heading) {
            $items[] = [
                'depth' => $heading['level'],
                'title' => $heading['text'],
                'url' => '#' . $slugger->slug($heading['text']),
            ];
        }

        return $items;
    }
}
