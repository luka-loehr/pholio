<?php

declare(strict_types=1);

namespace Pholio;

require_once __DIR__ . '/Slug.php';

/**
 * Table of contents of a page:
 * a flat list `{ depth, title, url }`; the depth is the heading level
 * (`##` = 2), the URL the anchor `#slug` from `rehype-slug` (github-slugger).
 *
 * Slugs are counted per document; duplicate headings get `-1`, `-2`. A heading with
 * a fixed `id` (the footnotes label) still counts its slug but links to that id.
 */
final class Toc
{
    /**
     * @param list<array{level:int, text:string, id?:string}> $headings headings in document order
     * @return list<array{depth:int, title:string, url:string}>
     */
    public static function build(array $headings, ?Slugger $slugger = null): array
    {
        $slugger ??= new Slugger();
        $items = [];

        foreach ($headings as $heading) {
            $slug = $slugger->slug($heading['text']);
            $items[] = [
                'depth' => $heading['level'],
                'title' => $heading['text'],
                'url' => '#' . ($heading['id'] ?? $slug),
            ];
        }

        return $items;
    }
}
