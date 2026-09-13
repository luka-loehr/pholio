<?php

declare(strict_types=1);

// Search relevance and budgets over the demo: every case of verify/fixtures/demo/search-relevance.json
// ranks the expected pages first, and index size, engine start and keystroke time stay within budget,
// on the demo and on the demo built twenty times side by side. The Node checks skip without Node;
// tier 2 runs the same commands.

require __DIR__ . '/run.php';

const RELEVANCE_ROOT = __DIR__ . '/..';
const RELEVANCE_FIXTURE = RELEVANCE_ROOT . '/verify/fixtures/demo/search-relevance.json';
const RELEVANCE_DEMO = ['--content', RELEVANCE_ROOT . '/examples/demo/content', '--base-url', '/', '--tokenizer', 'english'];

/** Runs verify/search-parity.mjs with Node; skips when Node is missing. */
function relevance_node(array $args): array
{
    exec('command -v node 2>/dev/null', $found, $status);
    if ($status !== 0) {
        skip('node: not on PATH');
    }
    $command = 'node ' . escapeshellarg(RELEVANCE_ROOT . '/verify/search-parity.mjs') . ' '
        . implode(' ', array_map('escapeshellarg', $args)) . ' 2>&1';
    exec($command, $output, $status);

    return [$status, implode("\n", $output)];
}

test('fixture: at least 30 cases with a query and an expectation each', function (): void {
    $fixture = json_decode((string) file_get_contents(RELEVANCE_FIXTURE), true, 512, JSON_THROW_ON_ERROR);
    assert_true(count($fixture['cases']) >= 30, 'cases: ' . count($fixture['cases']));
    foreach ($fixture['cases'] as $case) {
        assert_true(is_string($case['query'] ?? null) && trim($case['query']) !== '', 'query');
        assert_true(isset($case['top1']) || isset($case['top3']) || ($case['none'] ?? false) === true, 'expectation for ' . $case['query']);
    }
    $queries = array_column($fixture['cases'], 'query');
    assert_same(count($queries), count(array_unique($queries)), 'queries unique');
});

test('node: every demo relevance case ranks the expected pages first', function (): void {
    [$status, $output] = relevance_node(['--relevance', ...RELEVANCE_DEMO, '--fixture', RELEVANCE_FIXTURE]);
    assert_same(0, $status, $output);
    assert_true(preg_match('/(\d+)\/\1 relevance cases pass/', $output) === 1, $output);
});

test('node: the demo index and its keystrokes stay within budget', function (): void {
    [$status, $output] = relevance_node([
        '--perf', ...RELEVANCE_DEMO, '--fixture', RELEVANCE_FIXTURE,
        '--max-bytes', '16000', '--max-gzip', '6500', '--max-load-ms', '5', '--max-p95-ms', '1', '--max-ms', '25',
    ]);
    assert_same(0, $status, $output);
    assert_contains('5/5 budgets passed', $output);
});

test('node: twenty copies of the demo stay within budget', function (): void {
    [$status, $output] = relevance_node([
        '--perf', ...RELEVANCE_DEMO, '--fixture', RELEVANCE_FIXTURE, '--copies', '20',
        '--max-bytes', '160000', '--max-gzip', '12000', '--max-load-ms', '10', '--max-p95-ms', '2', '--max-ms', '50',
    ]);
    assert_same(0, $status, $output);
    assert_contains('200 pages', $output);
    assert_contains('5/5 budgets passed', $output);
});
