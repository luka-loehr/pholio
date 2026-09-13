<?php

declare(strict_types=1);

namespace Pholio;

/**
 * Base of every expected generator error. The command line maps each subclass
 * to an exit code and prints `pholio: <file>:<line>: <message>` when a location
 * is known.
 */
abstract class Exception extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?string $sourceFile = null,
        public readonly ?int $sourceLine = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /** Exit code of the command line for this kind of error. */
    abstract public function exitCode(): int;

    /** "file:line: message", "file: message" or "message". */
    public function describe(): string
    {
        $location = $this->sourceFile ?? '';
        if ($location !== '' && $this->sourceLine !== null) {
            $location .= ':' . $this->sourceLine;
        }

        return $location === '' ? $this->getMessage() : $location . ': ' . $this->getMessage();
    }
}

/** Usage or configuration error: unknown flag or key, invalid value, planned key. Exit code 2. */
class ConfigException extends Exception
{
    public function exitCode(): int
    {
        return 2;
    }
}

/** Content error: Markdown, meta.json, broken link, unknown icon or language, missing image. Exit code 3. */
class ContentException extends Exception
{
    public function exitCode(): int
    {
        return 3;
    }
}

/** I/O error: output not writable, copy failed. Exit code 4. */
class IoException extends Exception
{
    public function exitCode(): int
    {
        return 4;
    }
}
