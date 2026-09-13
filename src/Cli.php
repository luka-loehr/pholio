<?php

declare(strict_types=1);

namespace Pholio;

require_once __DIR__ . '/Exceptions.php';
require_once __DIR__ . '/Fs.php';
require_once __DIR__ . '/I18n.php';
require_once __DIR__ . '/Config.php';
require_once __DIR__ . '/Htaccess.php';
require_once __DIR__ . '/Builder.php';
require_once __DIR__ . '/Init.php';

/**
 * The `pholio` command line: argument parsing, the init, build, check and dev
 * commands, and the mapping of errors to exit codes. Without --config, a
 * command works on a project directory (default: the current one) that
 * follows the convention described in Config.
 *
 * Exit codes: 0 success, 1 check found differences, 2 usage or configuration
 * error, 3 content error, 4 I/O error, 70 internal error (with a trace when
 * PHOLIO_DEBUG=1).
 */
final class Cli
{
    public const OK = 0;
    public const DIFFERENCES = 1;
    public const USAGE = 2;
    public const INTERNAL = 70;

    private const SHORT_HELP = <<<'TEXT'
Pholio – static documentation sites from Markdown.

  pholio init [dir]     Create a project: content/, assets/, pholio.config.php
  pholio dev [dir]      Build, serve at http://127.0.0.1:8080, rebuild on changes
  pholio build [dir]    Write the site into public/
  pholio check [dir]    Compare a fresh build with public/

[dir] defaults to the current directory. Run "pholio --help" for all options.
TEXT;

    private const USAGE_TEXT = <<<'TEXT'
Pholio – static documentation sites from Markdown.

Usage:
  pholio init   [dir] [--name <site name>] [--lang en|de] [--force]
  pholio build  [dir] [--config <file>] [--profile <name>] [--content <dir>] [--out <dir>]
                [--only <url-part>] [--dev] [--quiet] [--set <key.path>=<value>]
  pholio check  [dir] [--config <file>] [--profile <name>] [--content <dir>] [--against <dir>]
                [--dev] [--set <key.path>=<value>]
  pholio dev    [dir] [--config <file>] [--profile <name>] [--content <dir>] [--host 127.0.0.1]
                [--port 8080] [--no-watch] [--set <key.path>=<value>]
  pholio --help | <command> --help | --version

Project layout ([dir], default: the current directory):
  pholio.config.php     optional; every key has a default
  content/              Markdown pages and meta.json files
  assets/               images and files, published at /assets/
  public/               the built site

Commands:
  init    Create that layout with a small sample site. Refuses to overwrite
          existing files unless --force.
  build   Render the site into output_dir.
  check   Render into a temporary directory and compare it with --against
          (default output_dir) in both directions. Differences are printed as
          "missing:", "stale:" and "extra:" lines; paths in output.keep are ignored.
  dev     Build with drafts into output_dir, serve it with PHP's built-in server
          and rebuild when content, config or copied files change.

Options:
  --config <file>        Configuration file. Default: <dir>/pholio.config.php if present
  --profile <name>       Merge profiles.<name> over the configuration.
  --content <dir>        Override content_dir.
  --out <dir>            Override output_dir.
  --against <dir>        Directory check compares with. Default: output_dir
  --only <url-part>      Render only pages whose URL contains this string.
  --dev                  Include pages whose file name starts with "_".
  --quiet                No summary line.
  --set <key>=<value>    Override a string config key, e.g. --set search.tokenizer=german
  --host <host>          dev server host. Default: 127.0.0.1
  --port <port>          dev server port. Default: 8080
  --no-watch             dev: serve without rebuilding on changes.
  --name <site name>     init: site title. Default: the directory name
  --lang <language>      init: UI language, en or de. Default: en
  --force                init: overwrite existing files.

Exit codes:
  0 success, 1 check found differences, 2 usage or configuration error,
  3 content error, 4 I/O error, 70 internal error (PHOLIO_DEBUG=1 prints the trace)
TEXT;

    /** @var array<string, array<string, bool>> command => option => takes a value */
    private const OPTIONS = [
        'init' => [
            'name' => true, 'lang' => true, 'force' => false, 'help' => false,
        ],
        'build' => [
            'config' => true, 'profile' => true, 'content' => true, 'out' => true, 'only' => true,
            'dev' => false, 'quiet' => false, 'set' => true, 'check' => false, 'help' => false,
        ],
        'check' => [
            'config' => true, 'profile' => true, 'content' => true, 'against' => true, 'dev' => false,
            'set' => true, 'help' => false,
        ],
        'dev' => [
            'config' => true, 'profile' => true, 'content' => true, 'host' => true, 'port' => true,
            'no-watch' => false, 'set' => true, 'help' => false,
        ],
    ];

    /** @var null|\Closure(array, bool, ?string): Builder replaced by tests */
    public static ?\Closure $builderFactory = null;

    /** How this process was started, for commands printed back to the user. */
    private string $program = 'pholio';

    /** @var resource */
    private $out;

    /** @var resource */
    private $err;

    /**
     * @param resource|null $out
     * @param resource|null $err
     */
    public function __construct($out = null, $err = null)
    {
        $this->out = $out ?? STDOUT;
        $this->err = $err ?? STDERR;
    }

    /** @param list<string> $argv including the program name */
    public static function main(array $argv): int
    {
        return (new self())->run($argv);
    }

    /** @param list<string> $argv including the program name */
    public function run(array $argv): int
    {
        $this->program = (string) ($argv[0] ?? 'pholio');
        try {
            return $this->dispatch(array_slice($argv, 1));
        } catch (Exception $e) {
            $this->error($e->describe());

            return $e->exitCode();
        } catch (\Throwable $e) {
            $this->error('internal error: ' . $e->getMessage());
            if (getenv('PHOLIO_DEBUG') === '1') {
                fwrite($this->err, get_class($e) . ' at ' . $e->getFile() . ':' . $e->getLine() . "\n" . $e->getTraceAsString() . "\n");
            } else {
                fwrite($this->err, "pholio: run with PHOLIO_DEBUG=1 for the stack trace\n");
            }

            return self::INTERNAL;
        }
    }

    /** @param list<string> $args */
    private function dispatch(array $args): int
    {
        $command = $args[0] ?? null;
        if ($command === null) {
            fwrite($this->out, self::SHORT_HELP . "\n");

            return self::OK;
        }
        if ($command === '--help' || $command === '-h' || $command === 'help') {
            fwrite($this->out, self::USAGE_TEXT . "\n");

            return self::OK;
        }
        if ($command === '--version' || $command === '-V') {
            fwrite($this->out, 'pholio ' . self::version() . "\n");

            return self::OK;
        }
        if (!isset(self::OPTIONS[$command])) {
            throw new ConfigException(
                (str_starts_with($command, '-') ? 'unknown option: ' : 'unknown command: ') . $command
                . ' (commands: init, dev, build, check; see pholio --help)',
            );
        }

        $options = self::parseOptions($command, array_slice($args, 1));
        if (isset($options['help'])) {
            fwrite($this->out, self::USAGE_TEXT . "\n");

            return self::OK;
        }

        return match ($command) {
            'init' => $this->init($options),
            'build' => isset($options['check']) ? $this->deprecatedCheck($options) : $this->build($options),
            'check' => $this->check($options),
            'dev' => $this->dev($options),
        };
    }

    /**
     * @param list<string> $args
     * @return array<string, string|true|list<string>> "set" collects a list, "dir" is the positional argument
     */
    public static function parseOptions(string $command, array $args): array
    {
        $known = self::OPTIONS[$command];
        $options = [];
        for ($i = 0; $i < count($args); $i++) {
            $arg = $args[$i];
            if (!str_starts_with($arg, '--')) {
                if (isset($options['dir'])) {
                    throw new ConfigException("unexpected argument: {$arg}; only one project directory is allowed (see pholio {$command} --help)");
                }
                $options['dir'] = $arg;
                continue;
            }
            $name = substr($arg, 2);
            $value = null;
            if (str_contains($name, '=')) {
                [$name, $value] = explode('=', $name, 2);
            }
            if (!array_key_exists($name, $known)) {
                throw new ConfigException("unknown option for {$command}: --{$name} (see pholio {$command} --help)");
            }
            if (!$known[$name]) {
                if ($value !== null) {
                    throw new ConfigException("--{$name} takes no value");
                }
                $options[$name] = true;
                continue;
            }
            if ($value === null) {
                if (!isset($args[$i + 1]) || str_starts_with($args[$i + 1], '--')) {
                    throw new ConfigException("--{$name} needs a value");
                }
                $value = $args[++$i];
            }
            if ($name === 'set') {
                $options['set'][] = $value;
            } else {
                $options[$name] = $value;
            }
        }

        return $options;
    }

    public static function version(): string
    {
        $file = dirname(__DIR__) . '/VERSION';

        return is_file($file) ? trim((string) file_get_contents($file)) : 'unknown';
    }

    // ------------------------------------------------------------ commands

    /** @param array<string, mixed> $options */
    private function build(array $options): int
    {
        $config = $this->config($options);
        self::requireContent($config);
        $dev = isset($options['dev']);
        $result = $this->builder($config, $dev, $options['only'] ?? null)->build($config['outDir']);

        if (!isset($options['quiet'])) {
            fwrite($this->err, sprintf(
                "pholio: %d pages, %d in the search index, %d redirects%s%s, written to %s\n",
                $result['pages'],
                $result['indexed'],
                $result['redirects'],
                $config['profile'] === null ? '' : ', profile ' . $config['profile'],
                $dev ? ', with drafts' : '',
                $config['outDir'],
            ));
        }

        return self::OK;
    }

    /** @param array<string, mixed> $options */
    private function deprecatedCheck(array $options): int
    {
        fwrite($this->err, "pholio: \"build --check\" is deprecated, use \"pholio check\"\n");
        unset($options['check']);
        foreach (['only', 'quiet'] as $unsupported) {
            if (isset($options[$unsupported])) {
                throw new ConfigException("--{$unsupported} cannot be combined with --check");
            }
        }
        if (isset($options['out'])) {
            $options['against'] = $options['out'];
            unset($options['out']);
        }

        return $this->check($options);
    }

    /** @param array<string, mixed> $options */
    private function check(array $options): int
    {
        $config = $this->config($options);
        self::requireContent($config);
        $against = rtrim(isset($options['against']) ? Config::absolute($options['against'], (string) getcwd()) : $config['outDir'], '/');

        $root = Fs::tempDir();
        try {
            $target = Builder::targetBelow($config, $root);
            $result = $this->builder($config, isset($options['dev']), null)->build($target);
            $differences = Fs::compare($target, $against, $config['keep']);
            foreach ($differences as $line) {
                fwrite($this->out, $line . "\n");
            }
            if ($differences !== []) {
                fwrite($this->err, sprintf("pholio: %d difference(s) against %s\n", count($differences), $against));

                return self::DIFFERENCES;
            }
            fwrite($this->err, sprintf("pholio: %d pages and all assets match %s\n", $result['pages'], $against));

            return self::OK;
        } finally {
            Fs::removeDir($root);
        }
    }

    /** @param array<string, mixed> $options */
    private function dev(array $options): int
    {
        $host = $options['host'] ?? '127.0.0.1';
        $port = $options['port'] ?? '8080';
        if (preg_match('/^\d{1,5}$/', $port) !== 1 || (int) $port < 1 || (int) $port > 65535) {
            throw new ConfigException('--port: expected a number between 1 and 65535, got ' . $port);
        }
        if (preg_match('/^[A-Za-z0-9.:\[\]-]+$/', $host) !== 1) {
            throw new ConfigException('--host: invalid host ' . $host);
        }

        $config = $this->config($options);
        self::requireContent($config);
        // Measured before building: a failed build may leave partial output behind.
        $hadOutput = Fs::treeFiles($config['outDir']) !== [];
        [, $code] = $this->devBuild($options);
        $failed = $code !== self::OK;
        if ($failed) {
            if (!$hadOutput) {
                fwrite($this->err, "pholio: initial build failed with exit code {$code}; nothing to serve\n");

                return $code;
            }
            fwrite($this->err, "pholio: last build failed with exit code {$code}; serving the previous output from {$config['outDir']} until a rebuild succeeds\n");
        }
        $root = Builder::siteRoot($config, $config['outDir']);

        $process = proc_open(
            [PHP_BINARY, '-S', $host . ':' . $port, '-t', $root, __DIR__ . '/DevServer.php'],
            [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]],
            $pipes,
        );
        if (!is_resource($process)) {
            throw new IoException("cannot start the PHP built-in server on {$host}:{$port}");
        }
        // The server log is forwarded through this process, so it never
        // overwrites pholio's own lines when stderr is redirected to a file.
        $serverLog = $pipes[1];
        stream_set_blocking($serverLog, false);
        $url = 'http://' . $host . ':' . $port . ($config['homeUrl'] === '/' ? '/' : $config['homeUrl']);
        fwrite($this->err, "pholio: serving {$root} at {$url}" . (isset($options['no-watch']) ? '' : ', watching for changes') . "\n");

        $signalled = false;
        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
            $stop = static function () use (&$signalled): void {
                $signalled = true;
            };
            pcntl_signal(SIGINT, $stop);
            pcntl_signal(SIGTERM, $stop);
        }

        $watched = $this->watchedPaths($config);
        $stamp = self::stamp($watched);
        while (!$signalled && proc_get_status($process)['running']) {
            $this->forward($serverLog, 1.0);
            if (isset($options['no-watch'])) {
                continue;
            }
            $now = self::stamp($watched);
            if ($now === $stamp) {
                continue;
            }
            [$next, $code] = $this->devBuild($options);
            if ($next !== null) {
                $config = $next;
                $watched = $this->watchedPaths($config);
            }
            $stamp = self::stamp($watched);
            if ($code !== self::OK) {
                fwrite($this->err, "pholio: rebuild failed with exit code {$code}; still serving the previous output, fix the error to rebuild\n");
                $failed = true;
            } elseif ($failed) {
                fwrite($this->err, "pholio: rebuild succeeded after a failed build, serving the new output\n");
                $failed = false;
            }
        }

        proc_terminate($process);
        $this->forward($serverLog, 0.2);
        fclose($serverLog);
        proc_close($process);

        return self::OK;
    }

    /**
     * Copy whatever $stream has to stderr for up to $seconds.
     *
     * @param resource $stream non-blocking
     */
    private function forward($stream, float $seconds): void
    {
        $deadline = microtime(true) + $seconds;
        while (($left = $deadline - microtime(true)) > 0) {
            $read = [$stream];
            $write = $except = null;
            $ready = @stream_select($read, $write, $except, (int) $left, (int) (($left - (int) $left) * 1_000_000));
            if ($ready === false) {
                // Interrupted by a signal: let the caller check it.
                return;
            }
            if ($ready === 0) {
                continue;
            }
            $chunk = fread($stream, 65536);
            if ($chunk === false || $chunk === '') {
                if (feof($stream)) {
                    usleep((int) ($left * 1_000_000));

                    return;
                }
                continue;
            }
            fwrite($this->err, $chunk);
        }
    }

    /**
     * A build with drafts in a child process, so edited components and a changed
     * config are picked up without restarting. The child prints its own error.
     *
     * @param array<string, mixed> $options
     * @return array{0: ?array<string, mixed>, 1: int} the config the build used
     *         (null when the config itself failed to load) and the exit code
     */
    private function devBuild(array $options): array
    {
        try {
            $config = $this->config($options);
        } catch (Exception $e) {
            $this->error($e->describe());

            return [null, $e->exitCode()];
        }

        if (self::$builderFactory !== null) {
            try {
                $this->builder($config, true, null)->build($config['outDir']);
            } catch (Exception $e) {
                $this->error($e->describe());

                return [$config, $e->exitCode()];
            } catch (\Throwable $e) {
                $this->error('internal error: ' . $e->getMessage());

                return [$config, self::INTERNAL];
            }

            return [$config, self::OK];
        }

        $args = [PHP_BINARY, dirname(__DIR__) . '/bin/pholio', 'build', '--dev'];
        array_push($args, ...($config['configFile'] !== '' ? ['--config', $config['configFile']] : [$config['configDir']]));
        foreach (['profile', 'content'] as $name) {
            if (isset($options[$name])) {
                array_push($args, '--' . $name, $options[$name]);
            }
        }
        foreach ($options['set'] ?? [] as $set) {
            array_push($args, '--set', $set);
        }
        $process = proc_open($args, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes);
        if (!is_resource($process)) {
            return [$config, self::INTERNAL];
        }
        stream_copy_to_stream($pipes[1], $this->err);
        fclose($pipes[1]);

        return [$config, proc_close($process)];
    }

    /**
     * @param array<string, mixed> $config
     * @return list<string>
     */
    private function watchedPaths(array $config): array
    {
        $paths = [$config['configDir'] . '/' . Config::DEFAULT_FILE, $config['contentDir']];
        if ($config['configFile'] !== '') {
            $paths[] = $config['configFile'];
        }
        foreach ($config['copy'] as $copy) {
            $paths[] = $copy['from'];
        }
        foreach ([$config['theme']['paletteCss'], $config['redirectsFile']] as $file) {
            if ($file !== null) {
                $paths[] = $file;
            }
        }

        return $paths;
    }

    /** @param list<string> $paths */
    private static function stamp(array $paths): string
    {
        clearstatcache();
        $parts = [];
        foreach ($paths as $path) {
            if (is_file($path)) {
                $parts[] = $path . '@' . filemtime($path) . ':' . filesize($path);
                continue;
            }
            foreach (Fs::treeFiles($path) as $relative) {
                $file = $path . '/' . $relative;
                $parts[] = $file . '@' . @filemtime($file) . ':' . @filesize($file);
            }
        }

        return hash('xxh128', implode("\n", $parts));
    }

    // ------------------------------------------------------------ helpers

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function config(array $options): array
    {
        $overrides = [];
        $cwd = (string) getcwd();
        if (isset($options['content'])) {
            $overrides['content_dir'] = Config::absolute($options['content'], $cwd);
        }
        if (isset($options['out'])) {
            $overrides['output_dir'] = Config::absolute($options['out'], $cwd);
        }
        foreach ($options['set'] ?? [] as $set) {
            if (!str_contains($set, '=')) {
                throw new ConfigException("--set expects key.path=value, got {$set}");
            }
            [$key, $value] = explode('=', $set, 2);
            $overrides[$key] = $value;
        }

        if (isset($options['config'])) {
            if (isset($options['dir'])) {
                throw new ConfigException('give either a project directory or --config, not both');
            }

            return Config::load($options['config'], $options['profile'] ?? null, $overrides);
        }

        return Config::forDirectory($options['dir'] ?? $cwd, $options['profile'] ?? null, $overrides);
    }

    /** @param array<string, mixed> $config */
    private static function requireContent(array $config): void
    {
        if (is_dir($config['contentDir'])) {
            return;
        }
        if ($config['configFile'] === '') {
            throw new ConfigException(sprintf(
                'no %s and no %s/ directory in %s; create a project with "pholio init" or pass --config <file>',
                Config::DEFAULT_FILE,
                Config::CONTENT_DIR,
                $config['configDir'],
            ));
        }

        throw new ConfigException('content directory not found: ' . $config['contentDir'], $config['configFile']);
    }

    /** @param array<string, mixed> $options */
    private function init(array $options): int
    {
        $cwd = (string) getcwd();
        $given = $options['dir'] ?? '.';
        $dir = rtrim(Config::absolute($given, $cwd), '/');
        $name = $options['name'] ?? self::titleFromDirectory($dir);
        $language = $options['lang'] ?? I18n::DEFAULT_LANGUAGE;
        if (!in_array($language, I18n::languages(), true)) {
            throw new ConfigException("--lang: expected one of " . implode(', ', I18n::languages()) . ", got {$language}");
        }

        $files = Init::create($dir, $name, $language, isset($options['force']));

        fwrite($this->out, "Created {$name} in {$dir}:\n");
        foreach ($files as $file) {
            fwrite($this->out, "  {$file}\n");
        }
        $command = $this->invocation();
        $target = $given !== '.' && realpath($dir) !== realpath($cwd) ? ' ' . self::shellWord($given) : '';
        fwrite($this->out, "\nNext steps:\n");
        fwrite($this->out, "  {$command} dev{$target}      preview at http://127.0.0.1:8080, rebuilds when you save\n");
        fwrite($this->out, "  {$command} build{$target}    write the static site into public/\n");

        return self::OK;
    }

    /**
     * The command a user types to run this Pholio again from the current
     * directory: "pholio" when that name on PATH is this very script, otherwise
     * "php <script path as it was called>".
     */
    private function invocation(): string
    {
        $self = realpath($this->program);
        if ($self !== false && basename($this->program) === 'pholio') {
            foreach (explode(PATH_SEPARATOR, (string) getenv('PATH')) as $dir) {
                $candidate = rtrim($dir, '/') . '/pholio';
                if ($dir !== '' && is_file($candidate) && realpath($candidate) === $self) {
                    return 'pholio';
                }
            }
        }

        return 'php ' . self::shellWord($this->program);
    }

    private static function shellWord(string $word): string
    {
        return preg_match('#^[A-Za-z0-9_./:@%+=,-]+$#', $word) === 1 ? $word : escapeshellarg($word);
    }

    /** "my-docs" gives "My Docs". */
    private static function titleFromDirectory(string $dir): string
    {
        $base = basename($dir === '' ? '/' : $dir);
        $words = trim((string) preg_replace('/[-_.\s]+/', ' ', $base));

        return $words === '' ? 'Documentation' : ucwords($words);
    }

    /** @param array<string, mixed> $config */
    private function builder(array $config, bool $dev, ?string $only): Builder
    {
        return self::$builderFactory !== null
            ? (self::$builderFactory)($config, $dev, $only)
            : new Builder($config, $dev, $only);
    }

    private function error(string $message): void
    {
        fwrite($this->err, 'pholio: ' . $message . "\n");
    }
}
