<?php

declare(strict_types=1);

namespace Pholio;

require_once __DIR__ . '/Exceptions.php';

/**
 * User interface strings.
 *
 * Keys are the reference design's translation keys: the English source text followed by
 * the context note that `useTranslations({ note })` appends, for example
 * `Search(search dialog)` next to `Search(search trigger)`. Each shipped
 * language is one file in `src/i18n/<language>.php` returning key => text;
 * `en.php` defines the complete key set.
 *
 * Unknown keys are errors, never a silent fallback: a key the code asks for
 * that no table knows is a LogicException (a generator bug), an unknown key in
 * the `translations` config is a ConfigException.
 */
final class I18n
{
    public const DEFAULT_LANGUAGE = 'en';

    private static string $language = self::DEFAULT_LANGUAGE;

    /** @var array<string, string>|null */
    private static ?array $table = null;

    /**
     * Select the language and apply single-entry overrides.
     *
     * A language without a shipped table is accepted only when $overrides
     * translates every key.
     *
     * @param array<string, string> $overrides key => text
     * @throws ConfigException
     */
    public static function use(string $language, array $overrides = []): void
    {
        $keys = self::load(self::DEFAULT_LANGUAGE);
        foreach ($overrides as $key => $text) {
            if (!array_key_exists((string) $key, $keys)) {
                throw new ConfigException('translations: unknown key "' . $key . '"');
            }
            if (!is_string($text)) {
                throw new ConfigException('translations.' . $key . ': expected a string');
            }
        }

        if (self::shipped($language)) {
            $table = self::load($language);
        } else {
            $missing = array_diff_key($keys, $overrides);
            if ($missing !== []) {
                throw new ConfigException(sprintf(
                    'language "%s" has no shipped translation (shipped: %s); translations must then cover all %d keys, %d missing, e.g. "%s"',
                    $language,
                    implode(', ', self::languages()),
                    count($keys),
                    count($missing),
                    array_key_first($missing),
                ));
            }
            $table = [];
        }

        self::$language = $language;
        self::$table = array_replace($table, $overrides);
    }

    /** Translation of a key in the current language. */
    public static function t(string $key): string
    {
        $table = self::table();
        if (!array_key_exists($key, $table)) {
            throw new \LogicException('unknown translation key: ' . $key);
        }

        return $table[$key];
    }

    public static function has(string $key): bool
    {
        return array_key_exists($key, self::table());
    }

    /** @return array<string, string> the current table */
    public static function all(): array
    {
        return self::table();
    }

    /** @return list<string> every valid key */
    public static function keys(): array
    {
        return array_keys(self::load(self::DEFAULT_LANGUAGE));
    }

    public static function language(): string
    {
        return self::$language;
    }

    /** @return list<string> languages with a shipped table */
    public static function languages(): array
    {
        $out = [];
        foreach (glob(__DIR__ . '/i18n/*.php') ?: [] as $file) {
            $out[] = basename($file, '.php');
        }
        sort($out);

        return $out;
    }

    /**
     * The shipped table of a language, unchanged.
     *
     * @return array<string, string>
     */
    public static function load(string $language): array
    {
        if (!self::shipped($language)) {
            throw new \LogicException('no shipped translation for language: ' . $language);
        }
        $table = require __DIR__ . '/i18n/' . $language . '.php';
        if (!is_array($table)) {
            throw new \LogicException('translation file does not return an array: ' . $language);
        }

        return $table;
    }

    private static function shipped(string $language): bool
    {
        return preg_match('/^[a-z]{2,3}(-[A-Za-z0-9]+)?$/', $language) === 1
            && is_file(__DIR__ . '/i18n/' . $language . '.php');
    }

    /** @return array<string, string> */
    private static function table(): array
    {
        return self::$table ??= self::load(self::$language);
    }
}
