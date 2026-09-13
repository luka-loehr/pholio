<?php

declare(strict_types=1);

namespace Pholio;

/**
 * Hydration ids in the Base UI format.
 *
 * Base UI assigns its ids with React's `useId` and writes them into the DOM as
 * `base-ui-_R_<random>_` or `base-ui-_R_<random>_-viewport`. The random part
 * changes with every hydration, so verify/golden-dom.mjs replaces it (regex
 * `/_R_[0-9a-z]*_/g`) with `#id1`, `#id2`, … in order of first appearance in
 * the document.
 *
 * For the comparison only this counts: the same number of distinct ids, the
 * same order of first appearance, the same references (`aria-controls`,
 * `data-id`). The generator therefore assigns `base-ui-_R_1_`, `base-ui-_R_2_`,
 * … in document order and resets the counter before every page.
 */
final class Ids
{
    private static int $counter = 0;

    /** Reset the counter before every page. */
    public static function reset(): void
    {
        self::$counter = 0;
    }

    /** Next id. */
    public static function next(): string
    {
        self::$counter++;

        return 'base-ui-_R_' . self::$counter . '_';
    }
}
