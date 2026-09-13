<?php

declare(strict_types=1);

namespace Pholio;

require_once __DIR__ . '/Exceptions.php';
require_once __DIR__ . '/I18n.php';

/**
 * Loads `pholio.config.php`, validates it and normalises it into the internal
 * shape every other part of the generator reads.
 *
 * Pipeline: `require` the file (it returns one array) → merge the selected
 * profile recursively over it → apply command-line overrides (`--content`,
 * `--out`, `--set key.path=value`) → validate against the schema (unknown key,
 * wrong type, invalid value or planned key: ConfigException, exit code 2) →
 * fill defaults → resolve paths relative to the config file's directory →
 * resolve the `{site}`, `{home}`, `{docs}` and `{assets}` placeholders in
 * string values → build the internal shape below.
 *
 * Public keys are snake_case and documented in docs/configuration.md. The
 * internal keys are camelCase so the two can never be confused in code.
 *
 * Project convention. Every key has a default, so a directory laid out like
 * this builds without a config file (Config::forDirectory()):
 *
 *   my-docs/
 *     pholio.config.php   optional
 *     content/            Markdown pages and meta.json files   (content_dir)
 *     assets/             images and files, served at /assets/  (default copy)
 *     public/             the built site                        (output_dir)
 *
 * The theme's own CSS, JavaScript and fonts go to /pholio/ (asset_base), so
 * they never collide with files in assets/.
 *
 * Internal shape (the contract; components and libraries rely on it)
 * ---------------------------------------------------------------------
 *
 * URL rule: every URL path below is absolute, starts with "/" and has no
 * trailing slash, except the site root, which is "/". Join a child path with
 * `rtrim($url, '/') . '/' . $child`. URLs that carry a scheme are kept as given.
 *
 * array{
 *   configFile: string,             absolute path of the loaded config file
 *   configDir: string,              its directory; base of relative paths
 *   profile: ?string,               selected profile name
 *
 *   // site
 *   title: string,                  site name ({site})
 *   titleTemplate: string,          page <title> pattern; {site} resolved, {title} left for the page
 *   homeTitle: string,              <title> of the start page
 *   lang: string,                   "en" | "de" (or a fully translated language)
 *   translations: array<string,string>, i18n overrides, already applied via I18n::use()
 *
 *   // URLs
 *   homeUrl: string,                start page URL (from base_path)
 *   baseUrl: string,                docs root URL, the Tree's base (from docs_path)
 *   assetBase: string,              theme assets: css/, js/, fonts/, LICENSES/
 *   docsRootSuffix: ?string,        appended to the docs root page's output path when
 *                                   baseUrl === homeUrl and a start page exists, e.g. "/overview"
 *
 *   // input and output
 *   contentDir: string,             absolute, no trailing slash
 *   outDir: string,                 absolute, the directory that holds homeUrl's index.html
 *   content: array{
 *     extensions: list<string>,     page file extensions without dot, default ["md"]
 *     linkPrefix: ?string,          URL prefix of internal content links rewritten to baseUrl, null: no rewrite
 *     assetPrefix: ?string,         URL prefix of content images to rewrite, null: no rewrite
 *     assetTarget: string,          URL the prefix maps to (default: baseUrl)
 *     assetRoot: ?string,           absolute directory mirroring assetTarget, for image sizes;
 *                                   null: sizes are measured relative to contentDir
 *     frontmatterAliases: array<string,string>, e.g. ["stand" => "updated"]
 *   },
 *   copy: list<array{from:string, to:string, optional:bool}>, absolute source dir => URL dir,
 *                                   copied verbatim without *.md files. Without a `copy` key the
 *                                   default is assets/ => {home}/assets, optional (skipped when
 *                                   the directory does not exist); configured entries must exist
 *   keep: list<string>,             paths relative to outDir that Pholio neither writes nor
 *                                   reports in `check`; a directory covers everything below it
 *
 *   // header
 *   nav: array{
 *     title: string,                wordmark next to the logo (= title)
 *     logo: ?string,                logo URL, null: wordmark only
 *     logoSize: int,                width and height in px, default 24
 *     url: string,                  where the wordmark links (home_link, default homeUrl)
 *   },
 *   links: list<array{
 *     title: string, href: string,
 *     active: 'exact'|'prefix',     'prefix': current while the path starts with href
 *     external: bool,               opens in a new tab with rel="noreferrer noopener"
 *   }>,
 *
 *   // start page, null when the first content page is the root
 *   home: null|array{
 *     title: string,                = homeTitle
 *     hero: null|array{
 *       kicker: string, headline: string ("\n" separates lines), lead: string,
 *       image: ?string, imageDark: ?string, icon: ?string, iconSize: int,
 *       buttons: list<array{label:string, href:string, variant:'primary'|'secondary', icon:?string}>,
 *     },
 *     cards: null|array{
 *       title: string,
 *       linkLabel: string,          default I18n::t('Open(home card)')
 *       fromTree: bool,             derive items from the root folders' meta.json
 *       items: list<array{title:string, description:string, href:string, icon:?string}>,
 *     },
 *   },
 *
 *   // theme
 *   theme: array{
 *     light: array<string,string>,  token (without --color-fd-) => CSS colour
 *     dark: array<string,string>,
 *     paletteCss: ?string,          absolute path of a stylesheet inserted at the palette marker
 *     fontClass: string,            extra class on <html>, default ""
 *     hotkey: string,               theme toggle key, default "d"
 *     defaultScheme: 'system',
 *   },
 *   head: array{
 *     icons: list<array{rel:string, type:?string, sizes:?string, href:string}>,
 *     manifest: ?string,            manifest URL
 *     themeColor: ?string,
 *   },
 *
 *   // search
 *   search: array{
 *     enabled: true,
 *     indexPath: string,            relative to baseUrl's directory, default "search-index.json"
 *     indexUrl: string,             baseUrl joined with indexPath, for <meta name="nd-search-index">
 *     tokenizer: 'english'|'german', follows lang when not set ("de" gives "german")
 *     hotkey: list<string>,         keys shown in the search field, default ["⌘", "K"]
 *   },
 *
 *   // server
 *   redirects: list<array{from:string, to:string, reason:?string}>, from the config only
 *   redirectsFile: ?string,         absolute path of a JSON list of {from, to, reason}, read at build
 *   server: array{htaccess: bool, csp: string},
 *
 *   // agents (see docs/agents.md)
 *   site: array{
 *     url: ?string,                 origin without trailing slash, e.g. "https://docs.example.org";
 *                                   null: agent files use base-path URLs, absolute-only files are skipped
 *     description: ?string,         one-sentence site summary for llms.txt, skill.md, JSON-LD
 *   },
 *   agents: array{
 *     markdown: bool,               <page>.md twins
 *     llmsTxt: bool, llmsFullTxt: bool, skill: bool, agentCard: bool,
 *     robotsTxt: bool, sitemap: bool, structuredData: bool,
 *     headers: bool,                Link and X-Llms-Txt headers and content negotiation (.htaccess, _headers)
 *     pageActions: bool,            "Copy page" and its menu next to the title; false when markdown is off
 *     instructions: ?string,        "## Notes for agents" block of llms.txt and skill.md (heading in the site language)
 *     exclude: list<string>,        fnmatch globs on page URLs and content file paths
 *   },                              every flag is false when agents.enabled is false
 * }
 */
final class Config
{
    public const DEFAULT_FILE = 'pholio.config.php';

    /** Directory names of the project convention, relative to the project directory. */
    public const CONTENT_DIR = 'content';
    public const ASSETS_DIR = 'assets';
    public const OUTPUT_DIR = 'public';

    /** Content-Security-Policy written into the generated .htaccess by default. */
    public const DEFAULT_CSP = "default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; "
        . "img-src 'self' data:; media-src 'self'; font-src 'self' data:; connect-src 'self'; "
        . "object-src 'none'; base-uri 'self'; form-action 'none'; frame-ancestors 'self'";

    /**
     * The public schema. A leaf is a type string, optionally with a default:
     *   'string!'           required string
     *   'string', '?string' string with default, nullable string
     *   'int', 'bool'
     *   'list<string>'      list of strings
     *   'map<string>'       string keys => string values
     *   'redirects'         map old => new, or list of {from, to, reason}
     *   'profiles'          name => partial config (validated after merging)
     * An array with key 0 => 'list' is a list of structs described by key 1.
     * An array with key 0 => 'nullable' is null or the struct described by key 1.
     * Other arrays are nested structs.
     *
     * @return array<string, mixed>
     */
    private static function schema(): array
    {
        $button = ['label' => 'string!', 'href' => 'string!', 'variant' => ['enum', ['primary', 'secondary'], 'primary'], 'icon' => ['?string', null]];
        $card = ['title' => 'string!', 'description' => ['string', ''], 'href' => 'string!', 'icon' => ['?string', null]];

        return [
            'title' => ['string', 'Documentation'],
            'title_template' => ['string', '{title} – {site}'],
            'logo' => ['?string', null],
            'logo_size' => ['int', 24],
            'home_link' => ['string', '{home}'],
            'base_url' => ['?string', null],
            'base_path' => ['string', '/'],
            'docs_path' => ['?string', null],
            'docs_root_suffix' => ['?string', null],
            'asset_base' => ['string', 'pholio/'],
            'language' => ['string', 'en'],
            'translations' => ['map<string>', []],

            'content_dir' => ['string', self::CONTENT_DIR],
            'output_dir' => ['string', self::OUTPUT_DIR],
            'content' => [
                'extensions' => ['list<string>', ['md']],
                'link_prefix' => ['?string', null],
                'asset_prefix' => ['?string', null],
                'asset_target' => ['string', '{docs}'],
                'asset_root' => ['?string', null],
                'frontmatter_aliases' => ['map<string>', []],
            ],
            'copy' => ['map<string>', []],
            'output' => [
                'keep' => ['list<string>', []],
            ],

            'nav' => ['list', [
                'title' => 'string!',
                'href' => 'string!',
                'active' => ['enum', ['exact', 'prefix'], 'exact'],
                'external' => ['bool', false],
                'icon' => ['?string', null],
                'icon_only' => ['bool', false],
            ]],

            'home' => ['nullable', [
                'title' => ['string', '{site}'],
                'hero' => ['nullable', [
                    'kicker' => ['string', ''],
                    'headline' => 'string!',
                    'lead' => ['string', ''],
                    'image' => ['?string', null],
                    'image_dark' => ['?string', null],
                    'icon' => ['?string', null],
                    'icon_size' => ['int', 48],
                    'buttons' => ['list', $button],
                ]],
                'cards' => ['nullable', [
                    'title' => ['string', ''],
                    'link_label' => ['?string', null],
                    'from_tree' => ['bool', false],
                    'items' => ['list', $card],
                ]],
            ]],

            'theme' => [
                'light' => ['map<string>', []],
                'dark' => ['map<string>', []],
                'palette_css' => ['?string', null],
                'font_class' => ['string', ''],
                'hotkey' => ['string', 'd'],
                'default_scheme' => ['enum', ['system', 'light', 'dark'], 'system'],
                'custom_css' => ['?string', null],
            ],
            'head' => [
                'icons' => ['list', [
                    'rel' => 'string!',
                    'type' => ['?string', null],
                    'sizes' => ['?string', null],
                    'href' => 'string!',
                ]],
                'manifest' => ['?string', null],
                'theme_color' => ['?string', null],
            ],

            'search' => [
                'enabled' => ['bool', true],
                'index_path' => ['string', 'search-index.json'],
                'tokenizer' => ['?enum', ['english', 'german'], null],
                'hotkey' => ['list<string>', ['⌘', 'K']],
            ],

            'redirects' => ['redirects', []],
            'redirects_file' => ['?string', null],
            'server' => [
                'htaccess' => ['bool', true],
                'csp' => ['string', self::DEFAULT_CSP],
            ],

            'site' => [
                'url' => ['?string', null],
                'description' => ['?string', null],
            ],
            'agents' => [
                'enabled' => ['bool', true],
                'markdown' => ['bool', true],
                'llms_txt' => ['bool', true],
                'llms_full_txt' => ['bool', true],
                'skill' => ['bool', true],
                'agent_card' => ['bool', true],
                'robots_txt' => ['bool', true],
                'sitemap' => ['bool', true],
                'structured_data' => ['bool', true],
                'headers' => ['bool', true],
                'page_actions' => ['bool', true],
                'instructions' => ['?string', null],
                'exclude' => ['list<string>', []],
            ],

            'profiles' => ['profiles', []],

            'slots' => ['map<string>', []],
            'components' => ['any-map', []],
            'strict_content' => ['bool', false],
        ];
    }

    /**
     * Keys that the schema accepts but the generator does not honour yet. A value
     * other than the default is a ConfigException starting with "planned:".
     * `[]` as path segment means "every list item".
     *
     * @return list<array{0:list<string>, 1:mixed}> path, allowed value
     */
    private static function planned(): array
    {
        return [
            [['base_url'], null],
            [['theme', 'default_scheme'], 'system'],
            [['theme', 'custom_css'], null],
            [['search', 'enabled'], true],
            [['nav', '[]', 'icon'], null],
            [['nav', '[]', 'icon_only'], false],
            [['slots'], []],
            [['components'], []],
            [['strict_content'], false],
        ];
    }

    /**
     * Load, validate and normalise a configuration file.
     *
     * @param array<string, string> $overrides public key path ("content_dir",
     *        "search.tokenizer") => string value, applied after the profile
     * @return array<string, mixed> the internal shape documented on the class
     * @throws ConfigException
     */
    public static function load(string $file, ?string $profile = null, array $overrides = []): array
    {
        $path = self::absolute($file, (string) getcwd());
        if (!is_file($path)) {
            throw new ConfigException('config file not found: ' . $file);
        }

        $raw = (static function (string $__file) {
            return require $__file;
        })($path);
        if (!is_array($raw)) {
            throw new ConfigException('the config file must return an array', $path);
        }

        return self::fromArray($raw, dirname($path), $path, $profile, $overrides);
    }

    /**
     * The configuration of a project directory: its pholio.config.php when there
     * is one, otherwise the convention defaults with paths relative to $dir.
     *
     * @param array<string, string> $overrides
     * @return array<string, mixed>
     * @throws ConfigException
     */
    public static function forDirectory(string $dir, ?string $profile = null, array $overrides = []): array
    {
        $dir = rtrim(self::absolute($dir, (string) getcwd()), '/');
        if (!is_dir($dir)) {
            throw new ConfigException('project directory not found: ' . $dir);
        }
        $file = $dir . '/' . self::DEFAULT_FILE;
        if (is_file($file)) {
            return self::load($file, $profile, $overrides);
        }

        return self::fromArray([], $dir, null, $profile, $overrides);
    }

    /**
     * Normalise a configuration array. Relative paths resolve against $baseDir.
     *
     * @param array<string, mixed> $raw
     * @param array<string, string> $overrides
     * @return array<string, mixed>
     * @throws ConfigException
     */
    public static function fromArray(
        array $raw,
        string $baseDir,
        ?string $configFile = null,
        ?string $profile = null,
        array $overrides = [],
    ): array {
        $baseDir = rtrim($baseDir, '/');
        $schema = self::schema();

        $profiles = $raw['profiles'] ?? [];
        if (!is_array($profiles)) {
            throw new ConfigException('profiles: expected an array of profile name => config', $configFile);
        }
        unset($raw['profiles']);
        if ($profile !== null) {
            if (!isset($profiles[$profile]) || !is_array($profiles[$profile])) {
                $known = $profiles === [] ? 'none defined' : 'known: ' . implode(', ', array_keys($profiles));
                throw new ConfigException("unknown profile \"{$profile}\" ({$known})", $configFile);
            }
            if (array_key_exists('profiles', $profiles[$profile])) {
                throw new ConfigException("profiles.{$profile}.profiles: profiles cannot be nested", $configFile);
            }
            $raw = array_replace_recursive($raw, $profiles[$profile]);
        }
        foreach ($profiles as $name => $partial) {
            if (!is_array($partial)) {
                throw new ConfigException("profiles.{$name}: expected an array", $configFile);
            }
            self::checkKeys($partial, $schema, 'profiles.' . $name, $configFile);
        }

        foreach ($overrides as $keyPath => $value) {
            $raw = self::override($raw, $schema, (string) $keyPath, $value, $configFile);
        }
        $defaultCopy = !array_key_exists('copy', $raw);

        $c = self::validate($raw, $schema, '', $configFile);
        foreach (self::planned() as [$plannedPath, $allowed]) {
            self::checkPlanned($c, $plannedPath, $allowed, '', $configFile);
        }

        return self::build($c, $baseDir, $configFile, $profile, $defaultCopy);
    }

    // ------------------------------------------------------------ validation

    /**
     * @param array<string, mixed> $value
     * @param array<string, mixed> $struct
     * @return array<string, mixed> the value with defaults filled in
     */
    private static function validate(array $value, array $struct, string $prefix, ?string $file): array
    {
        if (array_is_list($value) && $value !== []) {
            throw new ConfigException(self::label($prefix) . 'expected keys, got a list', $file);
        }
        foreach (array_keys($value) as $key) {
            if (!array_key_exists((string) $key, $struct)) {
                throw new ConfigException('unknown key: ' . $prefix . $key . self::suggest((string) $key, $struct), $file);
            }
        }

        $out = [];
        foreach ($struct as $key => $spec) {
            $path = $prefix . $key;
            $present = array_key_exists($key, $value);
            $out[$key] = self::validateLeaf($present, $present ? $value[$key] : null, $spec, $path, $file);
        }

        return $out;
    }

    private static function validateLeaf(bool $present, mixed $value, mixed $spec, string $path, ?string $file): mixed
    {
        if (is_string($spec) && str_ends_with($spec, '!')) {
            if (!$present || $value === null) {
                throw new ConfigException('missing required key: ' . $path, $file);
            }
            $spec = [substr($spec, 0, -1)];
        }
        if (!is_array($spec) || !is_string($spec[0] ?? null)) {
            // Nested struct.
            if (!$present) {
                $value = [];
            }
            if (!is_array($value)) {
                throw new ConfigException($path . ': expected an array', $file);
            }

            return self::validate($value, $spec, $path . '.', $file);
        }

        $type = $spec[0];
        if ($type === 'list' || $type === 'nullable') {
            if (!$present || ($type === 'nullable' && $value === null)) {
                return $type === 'list' ? [] : null;
            }
            if (!is_array($value)) {
                throw new ConfigException($path . ': expected ' . ($type === 'list' ? 'a list' : 'an array or null'), $file);
            }
            if ($type === 'nullable') {
                return self::validate($value, $spec[1], $path . '.', $file);
            }
            if (!array_is_list($value)) {
                throw new ConfigException($path . ': expected a list', $file);
            }
            $items = [];
            foreach ($value as $i => $item) {
                if (!is_array($item)) {
                    throw new ConfigException("{$path}.{$i}: expected an array", $file);
                }
                $items[] = self::validate($item, $spec[1], "{$path}.{$i}.", $file);
            }

            return $items;
        }

        if ($type === 'enum' || $type === '?enum') {
            if (!$present) {
                return $spec[2] ?? null;
            }
            if ($value === null && $type === '?enum') {
                return null;
            }
            if (!in_array($value, $spec[1], true)) {
                throw new ConfigException($path . ': expected one of ' . implode(', ', $spec[1]) . ', got ' . self::show($value), $file);
            }

            return $value;
        }

        if (!$present) {
            return $spec[1] ?? null;
        }

        $ok = match ($type) {
            'string' => is_string($value),
            '?string' => $value === null || is_string($value),
            'int' => is_int($value),
            'bool' => is_bool($value),
            'list<string>' => is_array($value) && array_is_list($value) && self::allStrings($value),
            'map<string>' => is_array($value) && ($value === [] || !array_is_list($value)) && self::allStrings($value),
            'any-map', 'profiles' => is_array($value),
            'redirects' => is_array($value),
            default => throw new \LogicException('unknown schema type ' . $type),
        };
        if (!$ok) {
            $expected = [
                'string' => 'a string', '?string' => 'a string or null', 'int' => 'an integer', 'bool' => 'true or false',
                'list<string>' => 'a list of strings', 'map<string>' => 'an array of string keys => strings',
                'any-map' => 'an array', 'profiles' => 'an array', 'redirects' => 'a map or a list',
            ][$type];
            throw new ConfigException("{$path}: expected {$expected}, got " . self::show($value), $file);
        }

        return $type === 'redirects' ? self::redirects($value, $path, $file) : $value;
    }

    /**
     * @param array<mixed> $value
     * @return list<array{from:string, to:string, reason:?string}>
     */
    private static function redirects(array $value, string $path, ?string $file): array
    {
        $out = [];
        if (!array_is_list($value)) {
            foreach ($value as $from => $to) {
                if (!is_string($to)) {
                    throw new ConfigException("{$path}.{$from}: expected the target path as a string", $file);
                }
                $out[] = ['from' => (string) $from, 'to' => $to, 'reason' => null];
            }

            return $out;
        }
        foreach ($value as $i => $entry) {
            if (!is_array($entry)) {
                throw new ConfigException("{$path}.{$i}: expected {from, to, reason}", $file);
            }

            $out[] = self::validate($entry, ['from' => 'string!', 'to' => 'string!', 'reason' => ['?string', null]], "{$path}.{$i}.", $file);
        }

        return $out;
    }

    /**
     * Key check for a profile: unknown keys are errors even before the profile
     * is selected, types are checked after merging.
     *
     * @param array<mixed> $value
     * @param array<string, mixed> $struct
     */
    private static function checkKeys(array $value, array $struct, string $prefix, ?string $file): void
    {
        foreach ($value as $key => $child) {
            if (!array_key_exists((string) $key, $struct)) {
                throw new ConfigException("unknown key: {$prefix}.{$key}" . self::suggest((string) $key, $struct), $file);
            }
            $spec = $struct[$key];
            if (is_array($spec) && !is_string($spec[0] ?? null) && is_array($child)) {
                self::checkKeys($child, $spec, $prefix . '.' . $key, $file);
            }
        }
    }

    /**
     * @param array<string, mixed> $raw
     * @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    private static function override(array $raw, array $schema, string $keyPath, string $value, ?string $file): array
    {
        $segments = explode('.', $keyPath);
        $spec = $schema;
        foreach ($segments as $segment) {
            if (!is_array($spec) || is_string($spec[0] ?? null) || !array_key_exists($segment, $spec)) {
                throw new ConfigException("--set {$keyPath}: unknown key", $file);
            }
            $spec = $spec[$segment];
        }
        $type = is_array($spec) ? $spec[0] : rtrim($spec, '!');
        if (!in_array($type, ['string', '?string', 'enum', '?enum'], true)) {
            throw new ConfigException("--set {$keyPath}: only string keys can be set from the command line", $file);
        }

        $cursor = &$raw;
        foreach ($segments as $segment) {
            if (!isset($cursor[$segment]) || !is_array($cursor[$segment])) {
                $cursor[$segment] = $cursor[$segment] ?? [];
            }
            $cursor = &$cursor[$segment];
        }
        $cursor = $value;
        unset($cursor);

        return $raw;
    }

    /**
     * @param array<string, mixed>|list<mixed> $config
     * @param list<string> $path
     */
    private static function checkPlanned(mixed $config, array $path, mixed $allowed, string $prefix, ?string $file): void
    {
        $segment = array_shift($path);
        if ($segment === '[]') {
            foreach ((array) $config as $i => $item) {
                self::checkPlanned($item, $path, $allowed, $prefix . $i . '.', $file);
            }

            return;
        }
        if (!is_array($config) || !array_key_exists($segment, $config)) {
            return;
        }
        if ($path !== []) {
            self::checkPlanned($config[$segment], $path, $allowed, $prefix . $segment . '.', $file);

            return;
        }
        if ($config[$segment] !== $allowed) {
            throw new ConfigException(
                "planned: {$prefix}{$segment} is not implemented yet; remove it or set it to " . self::show($allowed),
                $file,
            );
        }
    }

    // ------------------------------------------------------------ normalising

    /**
     * @param array<string, mixed> $c validated public config with defaults
     * @return array<string, mixed>
     */
    private static function build(array $c, string $baseDir, ?string $configFile, ?string $profile, bool $defaultCopy = false): array
    {
        $language = $c['language'];
        try {
            I18n::use($language, $c['translations']);
        } catch (ConfigException $e) {
            throw new ConfigException($e->getMessage(), $configFile, null, $e);
        }

        $homeUrl = self::urlPath($c['base_path'], 'base_path', $configFile);
        $baseUrl = $c['docs_path'] === null ? $homeUrl : self::urlPath($c['docs_path'], 'docs_path', $configFile);
        $assetBase = self::assetBase($c['asset_base'], $homeUrl);

        if ($c['docs_root_suffix'] !== null && preg_match('#^(/[A-Za-z0-9_.~-]+)+$#', $c['docs_root_suffix']) !== 1) {
            throw new ConfigException('docs_root_suffix: expected a path like "/overview", got ' . self::show($c['docs_root_suffix']), $configFile);
        }
        if ($c['logo_size'] <= 0 || ($c['home']['hero']['icon_size'] ?? 1) <= 0) {
            throw new ConfigException('logo_size and home.hero.icon_size must be positive', $configFile);
        }
        if (str_contains($c['server']['csp'], '"')) {
            throw new ConfigException('server.csp: must not contain double quotes', $configFile);
        }
        foreach ($c['content']['extensions'] as $extension) {
            if (preg_match('/^[a-z0-9]+$/', $extension) !== 1) {
                throw new ConfigException('content.extensions: expected extensions without dot, got ' . self::show($extension), $configFile);
            }
        }

        $map = [
            '{site}' => $c['title'],
            '{home}' => rtrim($homeUrl, '/'),
            '{docs}' => rtrim($baseUrl, '/'),
            '{assets}' => rtrim($assetBase, '/'),
        ];
        $url = static function (?string $value) use ($map): ?string {
            if ($value === null) {
                return null;
            }
            $resolved = strtr($value, $map);

            return $resolved === '' ? '/' : $resolved;
        };
        $text = static fn(string $value): string => strtr($value, $map);
        $path = static fn(?string $value): ?string => $value === null ? null : self::absolute($value, $baseDir);

        $home = null;
        if ($c['home'] !== null) {
            $hero = $c['home']['hero'];
            $cards = $c['home']['cards'];
            $home = [
                'title' => $text($c['home']['title']),
                'hero' => $hero === null ? null : [
                    'kicker' => $text($hero['kicker']),
                    'headline' => $text($hero['headline']),
                    'lead' => $text($hero['lead']),
                    'image' => $url($hero['image']),
                    'imageDark' => $url($hero['image_dark']),
                    'icon' => $url($hero['icon']),
                    'iconSize' => $hero['icon_size'],
                    'buttons' => array_map(static fn(array $b): array => [
                        'label' => $text($b['label']),
                        'href' => $url($b['href']),
                        'variant' => $b['variant'],
                        'icon' => $b['icon'],
                    ], $hero['buttons']),
                ],
                'cards' => $cards === null ? null : [
                    'title' => $text($cards['title']),
                    'linkLabel' => $cards['link_label'] === null ? I18n::t('Open(home card)') : $text($cards['link_label']),
                    'fromTree' => $cards['from_tree'],
                    'items' => array_map(static fn(array $i): array => [
                        'title' => $text($i['title']),
                        'description' => $text($i['description']),
                        'href' => $url($i['href']),
                        'icon' => $i['icon'],
                    ], $cards['items']),
                ],
            ];
        }

        $copy = [];
        if ($defaultCopy) {
            $copy[] = ['from' => $baseDir . '/' . self::ASSETS_DIR, 'to' => (string) $url('{home}/' . self::ASSETS_DIR), 'optional' => true];
        }
        foreach ($c['copy'] as $from => $to) {
            $copy[] = ['from' => rtrim((string) $path((string) $from), '/'), 'to' => (string) $url($to), 'optional' => false];
        }

        $siteUrl = $c['site']['url'] === null ? null : rtrim($c['site']['url'], '/');
        if ($siteUrl !== null && preg_match('#^https?://[^/?\#\s]+$#i', $siteUrl) !== 1) {
            throw new ConfigException(
                'site.url: expected the origin the site is served from, like "https://docs.example.org", without a path (base_path adds it), got '
                . self::show($c['site']['url']),
                $configFile,
            );
        }
        $agents = $c['agents'];
        $on = static fn(string $key): bool => $agents['enabled'] && $agents[$key];

        $indexPath = trim($c['search']['index_path'], '/');
        if ($indexPath === '' || str_contains($indexPath, '..')) {
            throw new ConfigException('search.index_path: expected a relative file path', $configFile);
        }

        return [
            'configFile' => $configFile ?? '',
            'configDir' => $baseDir,
            'profile' => $profile,

            'title' => $c['title'],
            'titleTemplate' => str_replace('{site}', $c['title'], $c['title_template']),
            'homeTitle' => $home['title'] ?? $c['title'],
            'lang' => $language,
            'translations' => $c['translations'],

            'homeUrl' => $homeUrl,
            'baseUrl' => $baseUrl,
            'assetBase' => $assetBase,
            'docsRootSuffix' => $c['docs_root_suffix'],

            'contentDir' => rtrim((string) $path($c['content_dir']), '/'),
            'outDir' => rtrim((string) $path($c['output_dir']), '/'),
            'content' => [
                'extensions' => $c['content']['extensions'],
                'linkPrefix' => $c['content']['link_prefix'] === null ? null : rtrim($c['content']['link_prefix'], '/'),
                'assetPrefix' => $c['content']['asset_prefix'] === null ? null : rtrim($c['content']['asset_prefix'], '/'),
                'assetTarget' => (string) $url($c['content']['asset_target']),
                'assetRoot' => $c['content']['asset_root'] === null ? null : rtrim((string) $path($c['content']['asset_root']), '/'),
                'frontmatterAliases' => $c['content']['frontmatter_aliases'],
            ],
            'copy' => $copy,
            'keep' => array_values(array_map(static fn(string $p): string => trim($p, '/'), $c['output']['keep'])),

            'nav' => [
                'title' => $c['title'],
                'logo' => $url($c['logo']),
                'logoSize' => $c['logo_size'],
                'url' => (string) $url($c['home_link']),
            ],
            'links' => array_map(static fn(array $l): array => [
                'title' => $text($l['title']),
                'href' => (string) $url($l['href']),
                'active' => $l['active'],
                'external' => $l['external'],
            ], $c['nav']),

            'home' => $home,

            'theme' => [
                'light' => $c['theme']['light'],
                'dark' => $c['theme']['dark'],
                'paletteCss' => $path($c['theme']['palette_css']),
                'fontClass' => $c['theme']['font_class'],
                'hotkey' => $c['theme']['hotkey'],
                'defaultScheme' => $c['theme']['default_scheme'],
            ],
            'head' => [
                'icons' => array_map(static fn(array $i): array => [
                    'rel' => $i['rel'],
                    'type' => $i['type'],
                    'sizes' => $i['sizes'],
                    'href' => (string) $url($i['href']),
                ], $c['head']['icons']),
                'manifest' => $url($c['head']['manifest']),
                'themeColor' => $c['head']['theme_color'],
            ],

            'search' => [
                'enabled' => $c['search']['enabled'],
                'indexPath' => $indexPath,
                'indexUrl' => rtrim($baseUrl, '/') . '/' . $indexPath,
                'tokenizer' => $c['search']['tokenizer'] ?? (str_starts_with($language, 'de') ? 'german' : 'english'),
                'hotkey' => $c['search']['hotkey'],
            ],

            'redirects' => $c['redirects'],
            'redirectsFile' => $path($c['redirects_file']),
            'server' => $c['server'],

            'site' => [
                'url' => $siteUrl,
                'description' => $c['site']['description'] === null ? null : $text($c['site']['description']),
            ],
            'agents' => [
                'markdown' => $on('markdown'),
                'llmsTxt' => $on('llms_txt'),
                'llmsFullTxt' => $on('llms_full_txt'),
                'skill' => $on('skill'),
                'agentCard' => $on('agent_card'),
                'robotsTxt' => $on('robots_txt'),
                'sitemap' => $on('sitemap'),
                'structuredData' => $on('structured_data'),
                'headers' => $on('headers'),
                // The buttons copy and open the Markdown twin, so they need it.
                'pageActions' => $on('page_actions') && $on('markdown'),
                'instructions' => $agents['instructions'] === null ? null : $text($agents['instructions']),
                'exclude' => $agents['exclude'],
            ],
        ];
    }

    /** "/docs/" and "/docs" give "/docs"; "/" and "" give "/". */
    private static function urlPath(string $value, string $key, ?string $file): string
    {
        $trimmed = rtrim($value, '/');
        if ($trimmed === '') {
            return '/';
        }
        if (preg_match('#^(/[A-Za-z0-9_.~%-]+)+$#', $trimmed) !== 1) {
            throw new ConfigException("{$key}: expected an absolute URL path like \"/docs/\", got " . self::show($value), $file);
        }

        return $trimmed;
    }

    /** Relative to base_path unless it starts with a slash or a scheme. */
    private static function assetBase(string $value, string $homeUrl): string
    {
        if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $value) === 1) {
            return rtrim($value, '/');
        }
        if (!str_starts_with($value, '/')) {
            $value = rtrim($homeUrl, '/') . '/' . $value;
        }
        $trimmed = rtrim($value, '/');

        return $trimmed === '' ? '/' : $trimmed;
    }

    /** Absolute paths stay; relative ones resolve against $baseDir. */
    public static function absolute(string $path, string $baseDir): string
    {
        if ($path === '' || str_starts_with($path, '/') || preg_match('#^[A-Za-z]:[\\\\/]#', $path) === 1) {
            return $path;
        }

        return rtrim($baseDir, '/') . '/' . $path;
    }

    // ------------------------------------------------------------ helpers

    /** @param array<mixed> $values */
    private static function allStrings(array $values): bool
    {
        foreach ($values as $value) {
            if (!is_string($value)) {
                return false;
            }
        }

        return true;
    }

    /** @param array<string, mixed> $struct */
    private static function suggest(string $key, array $struct): string
    {
        $best = null;
        $bestDistance = 3;
        foreach (array_keys($struct) as $candidate) {
            $distance = levenshtein($key, (string) $candidate);
            if ($distance < $bestDistance) {
                $best = $candidate;
                $bestDistance = $distance;
            }
        }

        return $best === null ? '' : " (did you mean \"{$best}\"?)";
    }

    private static function label(string $prefix): string
    {
        return $prefix === '' ? 'config: ' : rtrim($prefix, '.') . ': ';
    }

    private static function show(mixed $value): string
    {
        return match (true) {
            $value === null => 'null',
            is_bool($value) => $value ? 'true' : 'false',
            is_string($value) => '"' . $value . '"',
            is_array($value) => $value === [] ? '[]' : 'an array',
            default => (string) json_encode($value),
        };
    }
}
