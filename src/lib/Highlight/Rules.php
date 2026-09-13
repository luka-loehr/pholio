<?php

declare(strict_types=1);

namespace Pholio\Highlight;

use stdClass;

/**
 * Rules and RuleFactory from vscode-textmate (rule.ts).
 *
 * Grammar descriptions are stdClass objects (json_decode without assoc), so the rule ids cached on the descriptions
 * (`desc.id`) act through shared objects as in the original.
 */
abstract class Rule
{
    public const END_RULE_ID = -1;
    public const WHILE_RULE_ID = -2;

    private bool $nameIsCapturing;
    private bool $contentNameIsCapturing;

    public function __construct(public int $id, protected ?string $name, protected ?string $contentName)
    {
        $this->nameIsCapturing = self::hasCaptures($this->name);
        $this->contentNameIsCapturing = self::hasCaptures($this->contentName);
    }

    private static function hasCaptures(?string $source): bool
    {
        return $source !== null && preg_match('/\$(\d+)|\$\{(\d+):\/(downcase|upcase)\}/', $source) === 1;
    }

    /** @param list<?array{0:int,1:int}>|null $captures */
    public function getName(?string $lineText, ?array $captures): ?string
    {
        if (!$this->nameIsCapturing || $this->name === null || $lineText === null || $captures === null) {
            return $this->name;
        }
        return self::replaceCaptures($this->name, $lineText, $captures);
    }

    /** @param list<?array{0:int,1:int}> $captures */
    public function getContentName(string $lineText, array $captures): ?string
    {
        if (!$this->contentNameIsCapturing || $this->contentName === null) {
            return $this->contentName;
        }
        return self::replaceCaptures($this->contentName, $lineText, $captures);
    }

    /** @param list<?array{0:int,1:int}> $captures */
    private static function replaceCaptures(string $source, string $lineText, array $captures): string
    {
        return (string) preg_replace_callback(
            '/\$(\d+)|\$\{(\d+):\/(downcase|upcase)\}/',
            static function (array $m) use ($lineText, $captures): string {
                $index = (int) ($m[1] !== '' ? $m[1] : $m[2]);
                if (!array_key_exists($index, $captures)) {
                    return $m[0];
                }
                $c = $captures[$index];
                // Non-participating groups have start = end = MAX in JavaScript and yield ""
                $result = $c === null ? '' : substr($lineText, $c[0], $c[1] - $c[0]);
                $result = ltrim($result, '.');
                return match ($m[3] ?? '') {
                    'downcase' => mb_strtolower($result, 'UTF-8'),
                    'upcase' => mb_strtoupper($result, 'UTF-8'),
                    default => $result,
                };
            },
            $source
        );
    }

    abstract public function collectPatterns(Grammar $grammar, RegExpSourceList $out): void;

    abstract public function compileAG(Grammar $grammar, ?string $endRegexSource, bool $allowA, bool $allowG): CompiledRule;
}

final class CaptureRule extends Rule
{
    public function __construct(int $id, ?string $name, ?string $contentName, public int $retokenizeCapturedWithRuleId)
    {
        parent::__construct($id, $name, $contentName);
    }

    public function collectPatterns(Grammar $grammar, RegExpSourceList $out): void
    {
        throw new \LogicException('Not supported!');
    }

    public function compileAG(Grammar $grammar, ?string $endRegexSource, bool $allowA, bool $allowG): CompiledRule
    {
        throw new \LogicException('Not supported!');
    }
}

final class MatchRule extends Rule
{
    private RegExpSource $match;
    private ?RegExpSourceList $cachedPatterns = null;

    /** @param list<?CaptureRule> $captures */
    public function __construct(int $id, ?string $name, string $match, public array $captures)
    {
        parent::__construct($id, $name, null);
        $this->match = new RegExpSource($match, $id);
    }

    public function collectPatterns(Grammar $grammar, RegExpSourceList $out): void
    {
        $out->push($this->match);
    }

    public function compileAG(Grammar $grammar, ?string $endRegexSource, bool $allowA, bool $allowG): CompiledRule
    {
        if ($this->cachedPatterns === null) {
            $this->cachedPatterns = new RegExpSourceList();
            $this->collectPatterns($grammar, $this->cachedPatterns);
        }
        return $this->cachedPatterns->compileAG($allowA, $allowG);
    }
}

final class IncludeOnlyRule extends Rule
{
    private ?RegExpSourceList $cachedPatterns = null;

    /** @param list<int> $patterns */
    public function __construct(int $id, ?string $name, ?string $contentName, public array $patterns, public bool $hasMissingPatterns)
    {
        parent::__construct($id, $name, $contentName);
    }

    public function collectPatterns(Grammar $grammar, RegExpSourceList $out): void
    {
        foreach ($this->patterns as $p) {
            $grammar->getRule($p)->collectPatterns($grammar, $out);
        }
    }

    public function compileAG(Grammar $grammar, ?string $endRegexSource, bool $allowA, bool $allowG): CompiledRule
    {
        if ($this->cachedPatterns === null) {
            $this->cachedPatterns = new RegExpSourceList();
            $this->collectPatterns($grammar, $this->cachedPatterns);
        }
        return $this->cachedPatterns->compileAG($allowA, $allowG);
    }
}

final class BeginEndRule extends Rule
{
    private RegExpSource $begin;
    private RegExpSource $end;
    public bool $endHasBackReferences;
    private ?RegExpSourceList $cachedPatterns = null;

    /**
     * @param list<?CaptureRule> $beginCaptures
     * @param list<?CaptureRule> $endCaptures
     * @param list<int> $patterns
     */
    public function __construct(
        int $id,
        ?string $name,
        ?string $contentName,
        string $begin,
        public array $beginCaptures,
        ?string $end,
        public array $endCaptures,
        private bool $applyEndPatternLast,
        public array $patterns,
        public bool $hasMissingPatterns,
    ) {
        parent::__construct($id, $name, $contentName);
        $this->begin = new RegExpSource($begin, $id);
        $this->end = new RegExpSource($end !== null && $end !== '' ? $end : "\u{FFFF}", self::END_RULE_ID);
        $this->endHasBackReferences = $this->end->hasBackReferences;
    }

    /** @param list<?array{0:int,1:int}> $captures */
    public function getEndWithResolvedBackReferences(string $lineText, array $captures): string
    {
        return $this->end->resolveBackReferences($lineText, $captures);
    }

    public function collectPatterns(Grammar $grammar, RegExpSourceList $out): void
    {
        $out->push($this->begin);
    }

    public function compileAG(Grammar $grammar, ?string $endRegexSource, bool $allowA, bool $allowG): CompiledRule
    {
        if ($this->cachedPatterns === null) {
            $this->cachedPatterns = new RegExpSourceList();
            foreach ($this->patterns as $p) {
                $grammar->getRule($p)->collectPatterns($grammar, $this->cachedPatterns);
            }
            $end = $this->end->hasBackReferences ? $this->end->cloneSource() : $this->end;
            if ($this->applyEndPatternLast) {
                $this->cachedPatterns->push($end);
            } else {
                $this->cachedPatterns->unshift($end);
            }
        }
        if ($this->end->hasBackReferences) {
            $index = $this->applyEndPatternLast ? $this->cachedPatterns->length() - 1 : 0;
            $this->cachedPatterns->setSource($index, (string) $endRegexSource);
        }
        return $this->cachedPatterns->compileAG($allowA, $allowG);
    }
}

final class BeginWhileRule extends Rule
{
    private RegExpSource $begin;
    private RegExpSource $while;
    public bool $whileHasBackReferences;
    private ?RegExpSourceList $cachedPatterns = null;
    private ?RegExpSourceList $cachedWhilePatterns = null;

    /**
     * @param list<?CaptureRule> $beginCaptures
     * @param list<?CaptureRule> $whileCaptures
     * @param list<int> $patterns
     */
    public function __construct(
        int $id,
        ?string $name,
        ?string $contentName,
        string $begin,
        public array $beginCaptures,
        string $while,
        public array $whileCaptures,
        public array $patterns,
        public bool $hasMissingPatterns,
    ) {
        parent::__construct($id, $name, $contentName);
        $this->begin = new RegExpSource($begin, $id);
        $this->while = new RegExpSource($while, self::WHILE_RULE_ID);
        $this->whileHasBackReferences = $this->while->hasBackReferences;
    }

    /** @param list<?array{0:int,1:int}> $captures */
    public function getWhileWithResolvedBackReferences(string $lineText, array $captures): string
    {
        return $this->while->resolveBackReferences($lineText, $captures);
    }

    public function collectPatterns(Grammar $grammar, RegExpSourceList $out): void
    {
        $out->push($this->begin);
    }

    public function compileAG(Grammar $grammar, ?string $endRegexSource, bool $allowA, bool $allowG): CompiledRule
    {
        if ($this->cachedPatterns === null) {
            $this->cachedPatterns = new RegExpSourceList();
            foreach ($this->patterns as $p) {
                $grammar->getRule($p)->collectPatterns($grammar, $this->cachedPatterns);
            }
        }
        return $this->cachedPatterns->compileAG($allowA, $allowG);
    }

    public function compileWhileAG(?string $endRegexSource, bool $allowA, bool $allowG): CompiledRule
    {
        if ($this->cachedWhilePatterns === null) {
            $this->cachedWhilePatterns = new RegExpSourceList();
            $this->cachedWhilePatterns->push($this->while->hasBackReferences ? $this->while->cloneSource() : $this->while);
        }
        if ($this->while->hasBackReferences) {
            $this->cachedWhilePatterns->setSource(0, $endRegexSource !== null && $endRegexSource !== '' ? $endRegexSource : "\u{FFFF}");
        }
        return $this->cachedWhilePatterns->compileAG($allowA, $allowG);
    }
}

/** RuleFactory: turns grammar descriptions into rules and assigns ids. */
final class RuleFactory
{
    public static function getCompiledRuleId(stdClass $desc, Grammar $helper, stdClass $repository): int
    {
        if (empty($desc->id)) {
            $id = $helper->allocateRuleId();
            $desc->id = $id;
            $helper->registerRule($id, self::createRule($desc, $id, $helper, $repository));
        }
        return (int) $desc->id;
    }

    private static function createRule(stdClass $desc, int $id, Grammar $helper, stdClass $repository): Rule
    {
        $name = self::str($desc->name ?? null);
        $contentName = self::str($desc->contentName ?? null);
        if (self::truthy($desc->match ?? null)) {
            return new MatchRule($id, $name, (string) $desc->match, self::compileCaptures($desc->captures ?? null, $helper, $repository));
        }
        if (!property_exists($desc, 'begin')) {
            if (isset($desc->repository) && $desc->repository instanceof stdClass) {
                $repository = self::merge($repository, $desc->repository);
            }
            $patterns = $desc->patterns ?? null;
            if ($patterns === null && isset($desc->include)) {
                $patterns = [(object) ['include' => $desc->include]];
            }
            [$list, $missing] = self::compilePatterns($patterns, $helper, $repository);
            return new IncludeOnlyRule($id, $name, $contentName, $list, $missing);
        }
        if (self::truthy($desc->while ?? null)) {
            [$list, $missing] = self::compilePatterns($desc->patterns ?? null, $helper, $repository);
            return new BeginWhileRule(
                $id,
                $name,
                $contentName,
                (string) $desc->begin,
                self::compileCaptures($desc->beginCaptures ?? $desc->captures ?? null, $helper, $repository),
                (string) $desc->while,
                self::compileCaptures($desc->whileCaptures ?? $desc->captures ?? null, $helper, $repository),
                $list,
                $missing,
            );
        }
        [$list, $missing] = self::compilePatterns($desc->patterns ?? null, $helper, $repository);
        return new BeginEndRule(
            $id,
            $name,
            $contentName,
            (string) $desc->begin,
            self::compileCaptures($desc->beginCaptures ?? $desc->captures ?? null, $helper, $repository),
            isset($desc->end) ? (string) $desc->end : null,
            self::compileCaptures($desc->endCaptures ?? $desc->captures ?? null, $helper, $repository),
            self::truthy($desc->applyEndPatternLast ?? null),
            $list,
            $missing,
        );
    }

    private static function truthy(mixed $v): bool
    {
        return !($v === null || $v === false || $v === '' || $v === 0 || $v === 0.0);
    }

    private static function str(mixed $v): ?string
    {
        return is_string($v) && $v !== '' ? $v : null;
    }

    public static function merge(stdClass $a, stdClass $b): stdClass
    {
        $out = new stdClass();
        foreach (get_object_vars($a) as $k => $v) {
            $out->{$k} = $v;
        }
        foreach (get_object_vars($b) as $k => $v) {
            $out->{$k} = $v;
        }
        return $out;
    }

    /** @return list<?CaptureRule> */
    private static function compileCaptures(mixed $captures, Grammar $helper, stdClass $repository): array
    {
        $r = [];
        if (!$captures instanceof stdClass) {
            return $r;
        }
        $vars = get_object_vars($captures);
        $max = 0;
        foreach ($vars as $key => $_) {
            if ($key === '$vscodeTextmateLocation') {
                continue;
            }
            $n = (int) $key;
            if ($n > $max) {
                $max = $n;
            }
        }
        for ($i = 0; $i <= $max; $i++) {
            $r[$i] = null;
        }
        foreach ($vars as $key => $cap) {
            if ($key === '$vscodeTextmateLocation' || !$cap instanceof stdClass) {
                continue;
            }
            $n = (int) $key;
            $retokenize = 0;
            if (isset($cap->patterns)) {
                $retokenize = self::getCompiledRuleId($cap, $helper, $repository);
            }
            $cid = $helper->allocateRuleId();
            $rule = new CaptureRule($cid, self::str($cap->name ?? null), self::str($cap->contentName ?? null), $retokenize);
            $helper->registerRule($cid, $rule);
            $r[$n] = $rule;
        }
        return $r;
    }

    /** @return array{0:list<int>, 1:bool} */
    private static function compilePatterns(mixed $patterns, Grammar $helper, stdClass $repository): array
    {
        $r = [];
        $count = 0;
        if (is_array($patterns)) {
            $count = count($patterns);
            foreach ($patterns as $pattern) {
                $ruleId = -1;
                if (isset($pattern->include)) {
                    $include = (string) $pattern->include;
                    if ($include === '$base' || $include === '$self') {
                        $target = $repository->{$include} ?? null;
                        if ($target instanceof stdClass) {
                            $ruleId = self::getCompiledRuleId($target, $helper, $repository);
                        }
                    } elseif ($include !== '' && $include[0] === '#') {
                        $local = $repository->{substr($include, 1)} ?? null;
                        if ($local instanceof stdClass) {
                            $ruleId = self::getCompiledRuleId($local, $helper, $repository);
                        }
                    } else {
                        $sharp = strpos($include, '#');
                        $scope = $sharp === false ? $include : substr($include, 0, $sharp);
                        $ruleName = $sharp === false ? null : substr($include, $sharp + 1);
                        $external = $helper->getExternalGrammar($scope, $repository);
                        if ($external !== null) {
                            if ($ruleName !== null) {
                                $rule = $external->repository->{$ruleName} ?? null;
                                if ($rule instanceof stdClass) {
                                    $ruleId = self::getCompiledRuleId($rule, $helper, $external->repository);
                                }
                            } else {
                                $ruleId = self::getCompiledRuleId($external->repository->{'$self'}, $helper, $external->repository);
                            }
                        }
                    }
                } else {
                    $ruleId = self::getCompiledRuleId($pattern, $helper, $repository);
                }
                if ($ruleId !== -1) {
                    $rule = $helper->findRule($ruleId);
                    if (($rule instanceof IncludeOnlyRule || $rule instanceof BeginEndRule || $rule instanceof BeginWhileRule)
                        && $rule->hasMissingPatterns && $rule->patterns === []) {
                        continue;
                    }
                    $r[] = $ruleId;
                }
            }
        }
        return [$r, $count !== count($r)];
    }
}
