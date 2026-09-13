<?php

declare(strict_types=1);

namespace Pholio;

require_once __DIR__ . '/Exceptions.php';
require_once __DIR__ . '/Fs.php';

/**
 * `pholio init`: scaffolds a project that follows the convention documented in
 * Config, from the files in src/init-template:
 *
 *   pholio.config.php, content/meta.json, content/index.md,
 *   content/writing-pages.md, assets/images/pipeline.svg
 *
 * `{{name}}` and `{{language}}` in the templates are replaced; in the config
 * file they are written as PHP string literals.
 */
final class Init
{
    public const TEMPLATE = __DIR__ . '/init-template';

    /**
     * Write the project into $dir, creating it when needed.
     *
     * Nothing is written when any target file already exists, unless $force.
     *
     * @return list<string> written files, relative to $dir
     * @throws ConfigException when files exist and $force is false
     */
    public static function create(string $dir, string $name, string $language, bool $force = false): array
    {
        $dir = rtrim($dir, '/');
        if ($dir === '') {
            $dir = '/';
        }
        if (file_exists($dir) && !is_dir($dir)) {
            throw new ConfigException('not a directory: ' . $dir);
        }
        if (trim($name) === '') {
            throw new ConfigException('--name must not be empty');
        }

        $files = Fs::treeFiles(self::TEMPLATE);
        $existing = array_values(array_filter($files, static fn(string $f): bool => file_exists($dir . '/' . $f)));
        if ($existing !== [] && !$force) {
            throw new ConfigException(sprintf(
                '%s already contains %s; nothing was written. Use --force to overwrite these files',
                $dir,
                implode(', ', $existing),
            ));
        }

        foreach ($files as $relative) {
            $content = (string) file_get_contents(self::TEMPLATE . '/' . $relative);
            if ($relative === 'pholio.config.php') {
                $content = strtr($content, [
                    "'{{name}}'" => var_export($name, true),
                    "'{{language}}'" => var_export($language, true),
                ]);
            } elseif (str_ends_with($relative, '.md') || str_ends_with($relative, '.json')) {
                $content = strtr($content, [
                    '{{name}}' => str_ends_with($relative, '.json') ? substr((string) json_encode($name, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 1, -1) : $name,
                    '{{language}}' => $language,
                ]);
            }
            Fs::mkdir(dirname($dir . '/' . $relative));
            if (@file_put_contents($dir . '/' . $relative, $content) === false) {
                throw new IoException('file not writable: ' . $dir . '/' . $relative);
            }
        }

        return $files;
    }
}
