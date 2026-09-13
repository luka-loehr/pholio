<?php

declare(strict_types=1);

/**
 * Plain PHP test runner, no PHPUnit.
 *
 * As a script it runs every tests/*Test.php in a fresh PHP process:
 *
 *   php tests/run.php [--only Config,Cli] [--require-node] [--require-reference]
 *
 * A test file passes with exit code 0 and fails otherwise. A line starting with
 * "SKIP:" marks skipped work; --require-node and --require-reference turn skips
 * into failures (the test prints "SKIP: node" or "SKIP: reference" style reasons,
 * and both flags reject any skip).
 *
 * Included from a test file (`require __DIR__ . '/run.php';`) it defines the
 * helpers below instead and prints a summary when the file ends:
 *
 *   test(string $name, callable $fn)
 *   assert_same(mixed $expected, mixed $actual, string $message = '')
 *   assert_true(bool $condition, string $message = '')
 *   assert_contains(string $needle, string $haystack, string $message = '')
 *   assert_throws(string $class, callable $fn, ?string $messageContains = null): Throwable
 *   skip(string $reason)       skip the current test
 *   skip_all(string $reason)   skip the rest of the file, exit 0
 */

if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    exit(pholio_run_suite(array_slice($argv, 1)));
}

if (!function_exists('test')) {
    final class TestFailure extends \Exception
    {
    }

    final class TestSkipped extends \Exception
    {
    }

    /** @var array{pass:int, fail:int, skip:int} */
    $GLOBALS['pholio_test_counts'] = ['pass' => 0, 'fail' => 0, 'skip' => 0];

    register_shutdown_function(static function (): void {
        $c = $GLOBALS['pholio_test_counts'];
        if ($c['pass'] + $c['fail'] + $c['skip'] === 0) {
            return;
        }
        printf("%d passed, %d failed, %d skipped\n", $c['pass'], $c['fail'], $c['skip']);
        if ($c['fail'] > 0) {
            exit(1);
        }
    });

    function test(string $name, callable $fn): void
    {
        try {
            $fn();
            $GLOBALS['pholio_test_counts']['pass']++;
            echo "ok   {$name}\n";
        } catch (TestSkipped $e) {
            $GLOBALS['pholio_test_counts']['skip']++;
            echo "SKIP: {$name}: {$e->getMessage()}\n";
        } catch (\Throwable $e) {
            $GLOBALS['pholio_test_counts']['fail']++;
            $detail = $e instanceof TestFailure ? $e->getMessage() : get_class($e) . ': ' . $e->getMessage()
                . ' at ' . $e->getFile() . ':' . $e->getLine();
            echo "FAIL {$name}\n     " . str_replace("\n", "\n     ", $detail) . "\n";
        }
    }

    function assert_same(mixed $expected, mixed $actual, string $message = ''): void
    {
        if ($expected !== $actual) {
            $show = static fn(mixed $v): string => var_export($v, true);
            throw new TestFailure(($message !== '' ? $message . "\n" : '') . 'expected ' . $show($expected) . "\n     got      " . $show($actual));
        }
    }

    function assert_true(bool $condition, string $message = ''): void
    {
        if (!$condition) {
            throw new TestFailure($message !== '' ? $message : 'expected true');
        }
    }

    function assert_contains(string $needle, string $haystack, string $message = ''): void
    {
        if (!str_contains($haystack, $needle)) {
            throw new TestFailure(($message !== '' ? $message . "\n" : '') . 'expected to contain ' . var_export($needle, true) . "\n     in " . var_export($haystack, true));
        }
    }

    function assert_throws(string $class, callable $fn, ?string $messageContains = null): \Throwable
    {
        try {
            $fn();
        } catch (\Throwable $e) {
            if (!$e instanceof $class) {
                throw new TestFailure("expected {$class}, got " . get_class($e) . ': ' . $e->getMessage());
            }
            if ($messageContains !== null && !str_contains($e->getMessage(), $messageContains)) {
                throw new TestFailure("expected message containing '{$messageContains}', got '{$e->getMessage()}'");
            }

            return $e;
        }
        throw new TestFailure("expected {$class}, nothing was thrown");
    }

    function skip(string $reason): never
    {
        throw new TestSkipped($reason);
    }

    function skip_all(string $reason): never
    {
        echo "SKIP: {$reason}\n";
        exit(0);
    }
}

/** @param list<string> $args */
function pholio_run_suite(array $args): int
{
    $only = null;
    $requireSkipsFree = false;
    for ($i = 0; $i < count($args); $i++) {
        $arg = $args[$i];
        if ($arg === '--only' && isset($args[$i + 1])) {
            $only = array_filter(array_map('trim', explode(',', $args[++$i])), 'strlen');
        } elseif (str_starts_with($arg, '--only=')) {
            $only = array_filter(array_map('trim', explode(',', substr($arg, 7))), 'strlen');
        } elseif ($arg === '--require-node' || $arg === '--require-reference') {
            $requireSkipsFree = true;
        } else {
            fwrite(STDERR, "run.php: unknown argument: {$arg}\nusage: php tests/run.php [--only Name,Name] [--require-node] [--require-reference]\n");

            return 2;
        }
    }

    $files = glob(__DIR__ . '/*Test.php') ?: [];
    sort($files);
    if ($only !== null) {
        $wanted = array_map(static fn(string $n): string => preg_replace('/Test$/', '', $n) . 'Test.php', $only);
        $unknown = array_diff($wanted, array_map('basename', $files));
        if ($unknown !== []) {
            fwrite(STDERR, 'run.php: no such test file: ' . implode(', ', $unknown) . "\n");

            return 2;
        }
        $files = array_values(array_filter($files, static fn(string $f): bool => in_array(basename($f), $wanted, true)));
    }

    $failed = [];
    foreach ($files as $file) {
        $name = basename($file, '.php');
        echo "== {$name}\n";
        $output = [];
        $process = proc_open([PHP_BINARY, $file], [1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes);
        if (!is_resource($process)) {
            $failed[] = $name;
            continue;
        }
        while (($line = fgets($pipes[1])) !== false) {
            echo '   ', $line;
            $output[] = $line;
        }
        fclose($pipes[1]);
        $code = proc_close($process);
        $skipped = array_filter($output, static fn(string $l): bool => str_starts_with($l, 'SKIP:'));
        if ($code !== 0 || ($requireSkipsFree && $skipped !== [])) {
            $failed[] = $name . ($code === 0 ? ' (skips not allowed)' : " (exit {$code})");
        }
    }

    echo "\n", count($files) - count($failed), '/', count($files), " test files passed\n";
    if ($failed !== []) {
        echo 'failed: ', implode(', ', $failed), "\n";

        return 1;
    }

    return 0;
}
