<?php

declare(strict_types=1);

namespace Pholio\Highlight;

use stdClass;

/**
 * Grammar and line tokenizer from vscode-textmate (grammar.ts, tokenizeString.ts).
 *
 * As in the original, `tokenizeLine2()` returns alternating start offsets and metadata; consecutive tokens with the
 * same metadata are already merged. The metadata encode language, token type, bracket bit, font style and the
 * colour ids of the currently set theme.
 */
final class Grammar
{
    public stdClass $grammar;
    private int $rootId = -1;
    private int $lastRuleId = 0;
    /** @var array<int, Rule> */
    private array $rules = [];
    /** @var array<string, stdClass> */
    private array $includedGrammars = [];
    /** @var list<array{matcher:\Closure, ruleId:int, priority:int}>|null */
    private ?array $injections = null;
    public Theme $theme;
    /** @var array<string, array{0:int,1:int}> Scope => [languageId, tokenType] */
    private array $basicAttributes = [];

    /**
     * @param \Closure(string):?string $lookup returns the grammar JSON for a scope name
     */
    public function __construct(private string $rootScopeName, string $json, private \Closure $lookup, Theme $theme)
    {
        $this->grammar = self::init(json_decode($json, false, 512, JSON_THROW_ON_ERROR), null);
        $this->theme = $theme;
    }

    private static function init(stdClass $grammar, ?stdClass $base): stdClass
    {
        if (!isset($grammar->repository) || !$grammar->repository instanceof stdClass) {
            $grammar->repository = new stdClass();
        }
        $self = new stdClass();
        $self->patterns = $grammar->patterns ?? null;
        $self->name = $grammar->scopeName;
        $grammar->repository->{'$self'} = $self;
        $grammar->repository->{'$base'} = $base ?? $self;
        return $grammar;
    }

    public function allocateRuleId(): int
    {
        return ++$this->lastRuleId;
    }

    public function registerRule(int $id, Rule $rule): void
    {
        $this->rules[$id] = $rule;
    }

    public function getRule(int $id): Rule
    {
        return $this->rules[$id];
    }

    /** Rule, or null while it is still being built (recursive includes, `undefined` in the original). */
    public function findRule(int $id): ?Rule
    {
        return $this->rules[$id] ?? null;
    }

    public function getExternalGrammar(string $scopeName, ?stdClass $repository): ?stdClass
    {
        if (isset($this->includedGrammars[$scopeName])) {
            return $this->includedGrammars[$scopeName];
        }
        $json = ($this->lookup)($scopeName);
        if ($json === null) {
            return null;
        }
        $base = $repository !== null ? ($repository->{'$base'} ?? null) : null;
        return $this->includedGrammars[$scopeName] = self::init(json_decode($json, false, 512, JSON_THROW_ON_ERROR), $base);
    }

    /** @return list<array{matcher:\Closure, ruleId:int, priority:int}> */
    private function getInjections(): array
    {
        if ($this->injections !== null) {
            return $this->injections;
        }
        $result = [];
        if (isset($this->grammar->injections) && $this->grammar->injections instanceof stdClass) {
            foreach (get_object_vars($this->grammar->injections) as $selector => $rule) {
                $ruleId = RuleFactory::getCompiledRuleId($rule, $this, $this->grammar->repository);
                foreach (ScopeSelector::createMatchers((string) $selector) as $m) {
                    $result[] = ['matcher' => $m['matcher'], 'ruleId' => $ruleId, 'priority' => $m['priority']];
                }
            }
        }
        // Sort stably by priority
        $indexed = [];
        foreach ($result as $i => $r) {
            $indexed[] = [$r['priority'], $i, $r];
        }
        usort($indexed, static fn ($a, $b) => [$a[0], $a[1]] <=> [$b[0], $b[1]]);
        return $this->injections = array_map(static fn ($x) => $x[2], $indexed);
    }

    /** @return array{0:int,1:int} languageId, tokenType */
    public function basicAttributes(string $scopeName): array
    {
        if (isset($this->basicAttributes[$scopeName])) {
            return $this->basicAttributes[$scopeName];
        }
        $type = 8;
        if (preg_match('/\b(comment|string|regex|meta\.embedded)\b/', $scopeName, $m)) {
            $type = ['comment' => 1, 'string' => 2, 'regex' => 3, 'meta.embedded' => 0][$m[1]];
        }
        return $this->basicAttributes[$scopeName] = [0, $type];
    }

    /**
     * @return array{tokens:list<int>, ruleStack:StateStack}
     */
    public function tokenizeLine2(string $lineText, ?StateStack $prevState): array
    {
        if ($this->rootId === -1) {
            $this->rootId = RuleFactory::getCompiledRuleId($this->grammar->repository->{'$self'}, $this, $this->grammar->repository);
            $this->getInjections();
        }
        if ($prevState === null || $prevState === StateStack::null()) {
            $isFirstLine = true;
            [$defFont, $defFg, $defBg] = $this->theme->defaults();
            $defaultMetadata = Metadata::set(0, 1, 8, null, $defFont, $defFg, $defBg);
            $rootScopeName = $this->getRule($this->rootId)->getName(null, null);
            if ($rootScopeName !== null) {
                $scopeList = AttributedScopeStack::createRootAndLookUpScopeName($rootScopeName, $defaultMetadata, $this);
            } else {
                $scopeList = new AttributedScopeStack(null, new ScopeStack(null, 'unknown'), $defaultMetadata);
            }
            $prevState = new StateStack(null, $this->rootId, -1, -1, false, null, $scopeList, $scopeList);
        } else {
            $isFirstLine = false;
            $prevState->reset();
        }
        $lineText .= "\n";
        $lineLength = strlen($lineText);
        $lineTokens = new LineTokens();
        $stack = $this->tokenizeString($lineText, $isFirstLine, 0, $prevState, $lineTokens, true);
        return ['tokens' => $lineTokens->getBinaryResult($stack, $lineLength), 'ruleStack' => $stack];
    }

    private function tokenizeString(string $lineText, bool $isFirstLine, int $linePos, StateStack $stack, LineTokens $lineTokens, bool $checkWhileConditions): StateStack
    {
        $lineLength = strlen($lineText);
        $anchorPosition = -1;
        if ($checkWhileConditions) {
            [$stack, $linePos, $anchorPosition, $isFirstLine] = $this->checkWhileConditions($lineText, $isFirstLine, $linePos, $stack, $lineTokens);
        }
        while (true) {
            $r = $this->matchRuleOrInjections($lineText, $isFirstLine, $linePos, $stack, $anchorPosition);
            if ($r === null) {
                $lineTokens->produce($stack, $lineLength);
                return $stack;
            }
            $captures = $r['captures'];
            $matchedRuleId = $r['ruleId'];
            $hasAdvanced = $captures[0][1] > $linePos;
            if ($matchedRuleId === Rule::END_RULE_ID) {
                /** @var BeginEndRule $poppedRule */
                $poppedRule = $stack->getRule($this);
                $lineTokens->produce($stack, $captures[0][0]);
                $stack = $stack->withContentNameScopesList($stack->nameScopesList);
                $this->handleCaptures($lineText, $isFirstLine, $stack, $lineTokens, $poppedRule->endCaptures, $captures);
                $lineTokens->produce($stack, $captures[0][1]);
                $popped = $stack;
                $stack = $stack->parent;
                $anchorPosition = $popped->anchorPos;
                if (!$hasAdvanced && $popped->enterPos === $linePos) {
                    $stack = $popped;
                    $lineTokens->produce($stack, $lineLength);
                    return $stack;
                }
            } else {
                $rule = $this->getRule($matchedRuleId);
                $lineTokens->produce($stack, $captures[0][0]);
                $beforePush = $stack;
                $scopeName = $rule->getName($lineText, $captures);
                $nameScopesList = $stack->contentNameScopesList->pushAttributed($scopeName, $this);
                $stack = $stack->push($matchedRuleId, $linePos, $anchorPosition, $captures[0][1] === $lineLength, null, $nameScopesList, $nameScopesList);
                if ($rule instanceof BeginEndRule || $rule instanceof BeginWhileRule) {
                    $this->handleCaptures($lineText, $isFirstLine, $stack, $lineTokens, $rule->beginCaptures, $captures);
                    $lineTokens->produce($stack, $captures[0][1]);
                    $anchorPosition = $captures[0][1];
                    $contentName = $rule->getContentName($lineText, $captures);
                    $contentNameScopesList = $nameScopesList->pushAttributed($contentName, $this);
                    $stack = $stack->withContentNameScopesList($contentNameScopesList);
                    if ($rule instanceof BeginEndRule && $rule->endHasBackReferences) {
                        $stack = $stack->withEndRule($rule->getEndWithResolvedBackReferences($lineText, $captures));
                    } elseif ($rule instanceof BeginWhileRule && $rule->whileHasBackReferences) {
                        $stack = $stack->withEndRule($rule->getWhileWithResolvedBackReferences($lineText, $captures));
                    }
                    if (!$hasAdvanced && $beforePush->hasSameRuleAs($stack)) {
                        $stack = $stack->pop();
                        $lineTokens->produce($stack, $lineLength);
                        return $stack;
                    }
                } else {
                    /** @var MatchRule $rule */
                    $this->handleCaptures($lineText, $isFirstLine, $stack, $lineTokens, $rule->captures, $captures);
                    $lineTokens->produce($stack, $captures[0][1]);
                    $stack = $stack->pop();
                    if (!$hasAdvanced) {
                        $stack = $stack->safePop();
                        $lineTokens->produce($stack, $lineLength);
                        return $stack;
                    }
                }
            }
            if ($captures[0][1] > $linePos) {
                $linePos = $captures[0][1];
                $isFirstLine = false;
            }
        }
    }

    /** @return array{0:StateStack, 1:int, 2:int, 3:bool} */
    private function checkWhileConditions(string $lineText, bool $isFirstLine, int $linePos, StateStack $stack, LineTokens $lineTokens): array
    {
        $anchorPosition = $stack->beginRuleCapturedEOL ? 0 : -1;
        $whileRules = [];
        for ($node = $stack; $node !== null; $node = $node->pop()) {
            $rule = $node->getRule($this);
            if ($rule instanceof BeginWhileRule) {
                $whileRules[] = [$rule, $node];
            }
        }
        while (($entry = array_pop($whileRules)) !== null) {
            [$rule, $node] = $entry;
            $scanner = $rule->compileWhileAG($node->endRule, $isFirstLine, $linePos === $anchorPosition);
            $r = $scanner->findNextMatch($lineText, $linePos);
            if ($r !== null) {
                if ($r['ruleId'] !== Rule::WHILE_RULE_ID) {
                    $stack = $node->pop();
                    break;
                }
                $c = $r['captures'];
                if ($c !== []) {
                    $lineTokens->produce($node, $c[0][0]);
                    $this->handleCaptures($lineText, $isFirstLine, $node, $lineTokens, $rule->whileCaptures, $c);
                    $lineTokens->produce($node, $c[0][1]);
                    $anchorPosition = $c[0][1];
                    if ($c[0][1] > $linePos) {
                        $linePos = $c[0][1];
                        $isFirstLine = false;
                    }
                }
            } else {
                $stack = $node->pop();
                break;
            }
        }
        return [$stack, $linePos, $anchorPosition, $isFirstLine];
    }

    /** @return array{ruleId:int, captures:list<?array{0:int,1:int}>}|null */
    private function matchRuleOrInjections(string $lineText, bool $isFirstLine, int $linePos, StateStack $stack, int $anchorPosition): ?array
    {
        $rule = $stack->getRule($this);
        $match = $rule->compileAG($this, $stack->endRule, $isFirstLine, $linePos === $anchorPosition)
            ->findNextMatch($lineText, $linePos);
        $injections = $this->getInjections();
        if ($injections === []) {
            return $match;
        }
        $injection = $this->matchInjections($injections, $lineText, $isFirstLine, $linePos, $stack, $anchorPosition);
        if ($injection === null) {
            return $match;
        }
        if ($match === null) {
            return $injection;
        }
        $matchScore = $match['captures'][0][0];
        $injectionScore = $injection['captures'][0][0];
        if ($injectionScore < $matchScore || ($injection['priorityMatch'] && $injectionScore === $matchScore)) {
            return $injection;
        }
        return $match;
    }

    /**
     * @param list<array{matcher:\Closure, ruleId:int, priority:int}> $injections
     * @return array{ruleId:int, captures:list<?array{0:int,1:int}>, priorityMatch:bool}|null
     */
    private function matchInjections(array $injections, string $lineText, bool $isFirstLine, int $linePos, StateStack $stack, int $anchorPosition): ?array
    {
        $bestRating = PHP_INT_MAX;
        $best = null;
        $bestPriority = 0;
        $scopes = $stack->contentNameScopesList->getScopeNames();
        foreach ($injections as $injection) {
            if (!($injection['matcher'])($scopes)) {
                continue;
            }
            $rule = $this->getRule($injection['ruleId']);
            $r = $rule->compileAG($this, null, $isFirstLine, $linePos === $anchorPosition)->findNextMatch($lineText, $linePos);
            if ($r === null) {
                continue;
            }
            $rating = $r['captures'][0][0];
            if ($rating >= $bestRating) {
                continue;
            }
            $bestRating = $rating;
            $best = $r;
            $bestPriority = $injection['priority'];
            if ($rating === $linePos) {
                break;
            }
        }
        if ($best === null) {
            return null;
        }
        return ['ruleId' => $best['ruleId'], 'captures' => $best['captures'], 'priorityMatch' => $bestPriority === -1];
    }

    /**
     * @param list<?CaptureRule> $captureRules
     * @param list<?array{0:int,1:int}> $captures
     */
    private function handleCaptures(string $lineText, bool $isFirstLine, StateStack $stack, LineTokens $lineTokens, array $captureRules, array $captures): void
    {
        if ($captureRules === []) {
            return;
        }
        $len = min(count($captureRules), count($captures));
        $localStack = [];
        $maxEnd = $captures[0][1];
        for ($i = 0; $i < $len; $i++) {
            $captureRule = $captureRules[$i];
            if ($captureRule === null) {
                continue;
            }
            $capture = $captures[$i];
            if ($capture === null || $capture[1] - $capture[0] === 0) {
                continue;
            }
            if ($capture[0] > $maxEnd) {
                break;
            }
            while ($localStack !== [] && $localStack[count($localStack) - 1][1] <= $capture[0]) {
                $top = array_pop($localStack);
                $lineTokens->produceFromScopes($top[0], $top[1]);
            }
            if ($localStack !== []) {
                $lineTokens->produceFromScopes($localStack[count($localStack) - 1][0], $capture[0]);
            } else {
                $lineTokens->produce($stack, $capture[0]);
            }
            if ($captureRule->retokenizeCapturedWithRuleId) {
                $scopeName = $captureRule->getName($lineText, $captures);
                $nameScopesList = $stack->contentNameScopesList->pushAttributed($scopeName, $this);
                $contentName = $captureRule->getContentName($lineText, $captures);
                $contentNameScopesList = $nameScopesList->pushAttributed($contentName, $this);
                $stackClone = $stack->push($captureRule->retokenizeCapturedWithRuleId, $capture[0], -1, false, null, $nameScopesList, $contentNameScopesList);
                $this->tokenizeString(substr($lineText, 0, $capture[1]), $isFirstLine && $capture[0] === 0, $capture[0], $stackClone, $lineTokens, false);
                continue;
            }
            $captureScopeName = $captureRule->getName($lineText, $captures);
            if ($captureScopeName !== null) {
                $base = $localStack !== [] ? $localStack[count($localStack) - 1][0] : $stack->contentNameScopesList;
                $localStack[] = [$base->pushAttributed($captureScopeName, $this), $capture[1]];
            }
        }
        while ($localStack !== []) {
            $top = array_pop($localStack);
            $lineTokens->produceFromScopes($top[0], $top[1]);
        }
    }
}

/** EncodedTokenMetadata. */
final class Metadata
{
    public static function set(int $meta, int $languageId, int $tokenType, ?bool $balanced, int $fontStyle, int $foreground, int $background): int
    {
        $lang = $meta & 255;
        $type = ($meta & 768) >> 8;
        $bb = ($meta & 1024) !== 0 ? 1 : 0;
        $font = ($meta & 30720) >> 11;
        $fg = ($meta & 16744448) >> 15;
        $bg = ($meta & 4278190080) >> 24;
        if ($languageId !== 0) {
            $lang = $languageId;
        }
        if ($tokenType !== 8) {
            $type = $tokenType;
        }
        if ($balanced !== null) {
            $bb = $balanced ? 1 : 0;
        }
        if ($fontStyle !== -1) {
            $font = $fontStyle;
        }
        if ($foreground !== 0) {
            $fg = $foreground;
        }
        if ($background !== 0) {
            $bg = $background;
        }
        return ($lang | ($type << 8) | ($bb << 10) | ($font << 11) | ($fg << 15) | ($bg << 24)) & 0xFFFFFFFF;
    }

    public static function fontStyle(int $meta): int
    {
        return ($meta & 30720) >> 11;
    }

    public static function foreground(int $meta): int
    {
        return ($meta & 16744448) >> 15;
    }
}

/** ScopeStack. */
final class ScopeStack
{
    public function __construct(public ?ScopeStack $parent, public string $scopeName)
    {
    }

    /** @return list<string> */
    public function getSegments(): array
    {
        $out = [];
        for ($item = $this; $item !== null; $item = $item->parent) {
            $out[] = $item->scopeName;
        }
        return array_reverse($out);
    }
}

/** AttributedScopeStack. */
final class AttributedScopeStack
{
    public function __construct(public ?AttributedScopeStack $parent, public ScopeStack $scopePath, public int $tokenAttributes)
    {
    }

    public static function createRootAndLookUpScopeName(string $scopeName, int $tokenAttributes, Grammar $grammar): self
    {
        $path = new ScopeStack(null, $scopeName);
        return new self(null, $path, self::mergeAttributes($tokenAttributes, $grammar->basicAttributes($scopeName), $grammar->theme->match($path)));
    }

    /**
     * @param array{0:int,1:int} $basic
     * @param array{0:int,1:int,2:int}|null $style
     */
    private static function mergeAttributes(int $existing, array $basic, ?array $style): int
    {
        $font = -1;
        $fg = 0;
        $bg = 0;
        if ($style !== null) {
            [$font, $fg, $bg] = $style;
        }
        return Metadata::set($existing, $basic[0], $basic[1], null, $font, $fg, $bg);
    }

    public function pushAttributed(?string $scopePath, Grammar $grammar): self
    {
        if ($scopePath === null) {
            return $this;
        }
        if (!str_contains($scopePath, ' ')) {
            return self::pushOne($this, $scopePath, $grammar);
        }
        $result = $this;
        foreach (explode(' ', $scopePath) as $scope) {
            $result = self::pushOne($result, $scope, $grammar);
        }
        return $result;
    }

    private static function pushOne(self $target, string $scopeName, Grammar $grammar): self
    {
        $path = new ScopeStack($target->scopePath, $scopeName);
        return new self($target, $path, self::mergeAttributes($target->tokenAttributes, $grammar->basicAttributes($scopeName), $grammar->theme->match($path)));
    }

    /** @return list<string> */
    public function getScopeNames(): array
    {
        return $this->scopePath->getSegments();
    }
}

/** StateStackImpl. */
final class StateStack
{
    private static ?StateStack $null = null;
    public int $depth;

    public function __construct(
        public ?StateStack $parent,
        public int $ruleId,
        public int $enterPos,
        public int $anchorPos,
        public bool $beginRuleCapturedEOL,
        public ?string $endRule,
        public ?AttributedScopeStack $nameScopesList,
        public ?AttributedScopeStack $contentNameScopesList,
    ) {
        $this->depth = $parent !== null ? $parent->depth + 1 : 1;
    }

    public static function null(): self
    {
        return self::$null ??= new self(null, 0, 0, 0, false, null, null, null);
    }

    public function reset(): void
    {
        for ($el = $this; $el !== null; $el = $el->parent) {
            $el->enterPos = -1;
            $el->anchorPos = -1;
        }
    }

    public function pop(): ?self
    {
        return $this->parent;
    }

    public function safePop(): self
    {
        return $this->parent ?? $this;
    }

    public function push(int $ruleId, int $enterPos, int $anchorPos, bool $beginRuleCapturedEOL, ?string $endRule, AttributedScopeStack $nameScopesList, AttributedScopeStack $contentNameScopesList): self
    {
        return new self($this, $ruleId, $enterPos, $anchorPos, $beginRuleCapturedEOL, $endRule, $nameScopesList, $contentNameScopesList);
    }

    public function getRule(Grammar $grammar): Rule
    {
        return $grammar->getRule($this->ruleId);
    }

    public function withContentNameScopesList(AttributedScopeStack $list): self
    {
        if ($this->contentNameScopesList === $list) {
            return $this;
        }
        return $this->parent->push($this->ruleId, $this->enterPos, $this->anchorPos, $this->beginRuleCapturedEOL, $this->endRule, $this->nameScopesList, $list);
    }

    public function withEndRule(string $endRule): self
    {
        if ($this->endRule === $endRule) {
            return $this;
        }
        return new self($this->parent, $this->ruleId, $this->enterPos, $this->anchorPos, $this->beginRuleCapturedEOL, $endRule, $this->nameScopesList, $this->contentNameScopesList);
    }

    public function hasSameRuleAs(self $other): bool
    {
        for ($el = $this; $el !== null && $el->enterPos === $other->enterPos; $el = $el->parent) {
            if ($el->ruleId === $other->ruleId) {
                return true;
            }
        }
        return false;
    }
}

/** LineTokens in binary mode (balancedBracketSelectors ["*"]: bracket bit always set). */
final class LineTokens
{
    /** @var list<int> */
    private array $tokens = [];
    private int $lastEnd = 0;

    public function produce(StateStack $stack, int $endIndex): void
    {
        $this->produceFromScopes($stack->contentNameScopesList, $endIndex);
    }

    public function produceFromScopes(?AttributedScopeStack $scopes, int $endIndex): void
    {
        if ($this->lastEnd >= $endIndex) {
            return;
        }
        $metadata = ($scopes?->tokenAttributes ?? 0) | 1024;
        $n = count($this->tokens);
        if ($n > 0 && $this->tokens[$n - 1] === $metadata) {
            $this->lastEnd = $endIndex;
            return;
        }
        $this->tokens[] = $this->lastEnd;
        $this->tokens[] = $metadata;
        $this->lastEnd = $endIndex;
    }

    /** @return list<int> */
    public function getBinaryResult(StateStack $stack, int $lineLength): array
    {
        $n = count($this->tokens);
        if ($n > 0 && $this->tokens[$n - 2] === $lineLength - 1) {
            array_pop($this->tokens);
            array_pop($this->tokens);
        }
        if ($this->tokens === []) {
            $this->lastEnd = -1;
            $this->produce($stack, $lineLength);
            $this->tokens[count($this->tokens) - 2] = 0;
        }
        return $this->tokens;
    }
}

/** Scope selectors (matcher.ts) for injections. */
final class ScopeSelector
{
    /** @return list<array{matcher:\Closure, priority:int}> */
    public static function createMatchers(string $selector): array
    {
        preg_match_all('/([LR]:|[\w\.:][\w\.:\-]*|[\,\|\-\(\)])/', $selector, $m);
        $tokens = $m[0];
        $pos = 0;
        $token = $tokens[$pos] ?? null;
        $next = static function () use (&$pos, &$token, $tokens): void {
            $pos++;
            $token = $tokens[$pos] ?? null;
        };
        $isIdentifier = static fn (?string $t): bool => $t !== null && preg_match('/[\w\.:]+/', $t) === 1;
        $parseOperand = null;
        $parseConjunction = null;
        $parseInner = null;
        $parseOperand = static function () use (&$token, $next, $isIdentifier, &$parseOperand, &$parseInner): ?\Closure {
            if ($token === '-') {
                $next();
                $neg = $parseOperand();
                return static fn (array $scopes): bool => $neg !== null && !$neg($scopes);
            }
            if ($token === '(') {
                $next();
                $inner = $parseInner();
                if ($token === ')') {
                    $next();
                }
                return $inner;
            }
            if ($isIdentifier($token)) {
                $ids = [];
                do {
                    $ids[] = $token;
                    $next();
                } while ($isIdentifier($token));
                return static fn (array $scopes): bool => self::nameMatcher($ids, $scopes);
            }
            return null;
        };
        $parseConjunction = static function () use ($parseOperand): \Closure {
            $matchers = [];
            while (($m = $parseOperand()) !== null) {
                $matchers[] = $m;
            }
            return static function (array $scopes) use ($matchers): bool {
                foreach ($matchers as $m) {
                    if (!$m($scopes)) {
                        return false;
                    }
                }
                return true;
            };
        };
        $parseInner = static function () use (&$token, $next, $parseConjunction): \Closure {
            $matchers = [];
            $m = $parseConjunction();
            while (true) {
                $matchers[] = $m;
                if ($token === '|' || $token === ',') {
                    do {
                        $next();
                    } while ($token === '|' || $token === ',');
                } else {
                    break;
                }
                $m = $parseConjunction();
            }
            return static function (array $scopes) use ($matchers): bool {
                foreach ($matchers as $m) {
                    if ($m($scopes)) {
                        return true;
                    }
                }
                return false;
            };
        };
        $results = [];
        while ($token !== null) {
            $priority = 0;
            if (strlen($token) === 2 && $token[1] === ':') {
                $priority = $token[0] === 'R' ? 1 : ($token[0] === 'L' ? -1 : 0);
                $next();
            }
            $results[] = ['matcher' => $parseConjunction(), 'priority' => $priority];
            if ($token !== ',') {
                break;
            }
            $next();
        }
        return $results;
    }

    /**
     * @param list<string> $identifiers
     * @param list<string> $scopes
     */
    private static function nameMatcher(array $identifiers, array $scopes): bool
    {
        if (count($scopes) < count($identifiers)) {
            return false;
        }
        $last = 0;
        foreach ($identifiers as $id) {
            $found = false;
            for ($i = $last, $n = count($scopes); $i < $n; $i++) {
                $s = $scopes[$i];
                if ($s === $id || (strlen($s) > strlen($id) && str_starts_with($s, $id) && $s[strlen($id)] === '.')) {
                    $last = $i + 1;
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                return false;
            }
        }
        return true;
    }
}
