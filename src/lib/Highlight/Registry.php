<?php

declare(strict_types=1);

namespace Pholio\Highlight;

use InvalidArgumentException;

/**
 * Language and theme registry over `vendor-data/shiki` (grammars from @shikijs/langs, themes from @shikijs/themes).
 *
 * As in Shiki, aliases from the grammars (`aliases`) are resolved; an unknown language is an error with the same
 * message as in Shiki ("Language `x` not found, you may need to load it first"), and the build stops
 * instead of falling back to plain text.
 */
final class Registry
{
    public const PLAIN = ['plaintext', 'txt', 'text', 'plain'];

    private static ?self $instance = null;

    /** @var array<string, string> Name => JSON */
    private array $json = [];
    /** @var array<string, string> Scope => Name */
    private array $scopes = [];
    /** @var array<string, string> Alias => Name */
    private array $aliases = [];
    /** @var array<string, Grammar> */
    private array $grammars = [];
    /** @var array<string, Theme> */
    private array $themes = [];
    /** @var array<string, true> grammars already warned about */
    private array $simplified = [];

    /** @var null|\Closure(string):void receives the simplified-colours warning; null writes it to STDERR */
    public static ?\Closure $warn = null;

    private function __construct(private string $dir)
    {
        foreach (glob($this->dir . '/langs/*.json') ?: [] as $file) {
            $json = (string) file_get_contents($file);
            $head = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
            $name = (string) $head['name'];
            $this->json[$name] = $json;
            $this->scopes[(string) $head['scopeName']] = $name;
            foreach ($head['aliases'] ?? [] as $alias) {
                $this->aliases[(string) $alias] = $name;
            }
        }
    }

    public static function instance(): self
    {
        return self::$instance ??= new self(dirname(__DIR__, 3) . '/vendor-data/shiki');
    }

    public function isPlain(string $lang): bool
    {
        return in_array($this->resolveAlias($lang), self::PLAIN, true);
    }

    public function resolveAlias(string $lang): string
    {
        return $this->aliases[$lang] ?? $lang;
    }

    /** Check like `isLanguageLoaded` plus the plain-text special cases. */
    public function has(string $lang): bool
    {
        $name = $this->resolveAlias($lang);
        return in_array($name, self::PLAIN, true) || isset($this->json[$name]);
    }

    public function grammar(string $lang): Grammar
    {
        $name = $this->resolveAlias($lang);
        if (!isset($this->json[$name])) {
            throw new InvalidArgumentException('Language `' . $lang . '` not found, you may need to load it first');
        }
        if (!isset($this->grammars[$name])) {
            $head = json_decode($this->json[$name], true, 512, JSON_THROW_ON_ERROR);
            $lookup = function (string $scope): ?string {
                $n = $this->scopes[$scope] ?? null;
                return $n === null ? null : $this->json[$n];
            };
            $this->grammars[$name] = new Grammar((string) $head['scopeName'], $this->json[$name], \Closure::fromCallable($lookup), $this->theme('github-light'));
        }
        return $this->grammars[$name];
    }

    /**
     * Warns once per grammar that the pre-10.43 fallback disabled patterns while tokenizing it: the build still
     * succeeds, but some tokens lose their colour.
     */
    public function warnSimplified(string $lang): void
    {
        $name = $this->resolveAlias($lang);
        if (isset($this->simplified[$name])) {
            return;
        }
        $this->simplified[$name] = true;
        $message = 'pholio: PCRE2 ' . OnigRegex::pcreVersion() . ' is older than 10.43; some syntax colours are simplified ('
            . $name . ")\n";
        if (self::$warn !== null) {
            (self::$warn)($message);
        } else {
            fwrite(STDERR, $message);
        }
    }

    public function theme(string $name): Theme
    {
        return $this->themes[$name] ??= Theme::fromFile($this->dir . '/themes/' . $name . '.json');
    }
}
