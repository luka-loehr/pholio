<?php

declare(strict_types=1);

namespace Pholio;

/**
 * Element ids in the Base UI format.
 *
 * Components link elements through ids (`aria-controls`, `aria-labelledby`,
 * `data-id`), written as `base-ui-_R_<n>_` or `base-ui-_R_<n>_-viewport`. The
 * generator assigns `base-ui-_R_1_`, `base-ui-_R_2_`, … in document order and
 * resets the counter before every page, so a page's ids are stable across
 * builds.
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
