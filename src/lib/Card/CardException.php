<?php

declare(strict_types=1);

namespace Pholio\Card;

/**
 * A font or logo the share cards cannot use: not TrueType, no outlines, an SVG
 * element outside the supported subset, a character the font lacks. `$source` is
 * the file at fault; the caller turns it into a ConfigException or a
 * ContentException that names the configuration key or the page.
 */
final class CardException extends \RuntimeException
{
    public function __construct(string $message, public readonly string $source)
    {
        parent::__construct($message);
    }
}
