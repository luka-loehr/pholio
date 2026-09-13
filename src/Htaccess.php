<?php

declare(strict_types=1);

namespace Pholio;

require_once __DIR__ . '/Exceptions.php';
require_once __DIR__ . '/Fs.php';
require_once __DIR__ . '/AgentHeaders.php';

/**
 * The generated Apache `.htaccess`: hardening (options, MIME types, security
 * headers including the Content-Security-Policy, caching, methods, blocked
 * files), one 301 per redirect, the agent headers and content negotiation
 * (AgentHeaders) and the internal mapping of slashless page URLs to
 * `x/index.html`.
 */
final class Htaccess
{
    /**
     * Write `.htaccess` into $outDir unless `server.htaccess` is off.
     *
     * @return int number of redirects
     */
    public static function write(array $config, string $outDir): int
    {
        $redirects = self::redirects($config);
        if ($config['server']['htaccess']) {
            Fs::write($outDir, '.htaccess', self::render(
                $config['homeUrl'],
                $redirects,
                $config['server']['csp'],
                AgentHeaders::headers($config),
                AgentHeaders::negotiates($config),
            ));
        }

        return count($redirects);
    }

    /**
     * The redirects from the config followed by those from `redirects_file`.
     *
     * @return list<array{from:string, to:string, reason:?string}>
     */
    public static function redirects(array $config): array
    {
        $redirects = $config['redirects'];
        $file = $config['redirectsFile'];
        if ($file === null) {
            return $redirects;
        }
        if (!is_file($file)) {
            throw new ConfigException('redirects_file not found: ' . $file, $config['configFile'] ?: null);
        }
        try {
            $list = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new ConfigException('invalid JSON: ' . $e->getMessage(), $file, null, $e);
        }
        if (!is_array($list) || !array_is_list($list)) {
            throw new ConfigException('expected a JSON list of {from, to, reason}', $file);
        }
        foreach ($list as $i => $entry) {
            if (!is_array($entry) || !is_string($entry['from'] ?? null) || !is_string($entry['to'] ?? null)) {
                throw new ConfigException("entry {$i}: expected {from, to, reason}", $file);
            }
            $redirects[] = ['from' => $entry['from'], 'to' => $entry['to'], 'reason' => isset($entry['reason']) ? (string) $entry['reason'] : null];
        }

        return $redirects;
    }

    /**
     * @param list<array{from:string, to:string}> $redirects
     * @param list<array{0:string, 1:string}> $headers agent headers for every response (AgentHeaders::headers)
     * @param bool $negotiate serve a page's Markdown twin to agents (AgentHeaders::negotiates)
     */
    public static function render(string $homeUrl, array $redirects, string $csp, array $headers = [], bool $negotiate = false): string
    {
        $base = rtrim($homeUrl, '/') . '/';

        $agentHeaders = '';
        foreach ($headers as [$name, $value]) {
            // Single quotes: the Link value holds double quotes.
            $agentHeaders .= "\n    Header always " . ($name === 'Vary' ? 'merge' : 'set') . ' ' . $name . " '" . $value . "'";
        }

        $negotiation = '';
        if ($negotiate) {
            $agents = 'RewriteCond %{HTTP_USER_AGENT} (?:' . AgentHeaders::userAgentPattern() . ') [NC]';
            // The T flag is lost in the internal redirect of a per-directory rewrite,
            // so text/plain is set by mod_headers from the environment variable.
            $negotiation = <<<RULES

    # Agents: a page's Markdown twin (x.md next to x/index.html) for "Accept: text/markdown"
    # and AI assistants' user agents, as text/plain for "Accept: text/plain".
    RewriteCond %{HTTP_ACCEPT} text/markdown [NC,OR]
    {$agents}
    RewriteCond %{REQUEST_FILENAME}/index.md -f
    RewriteRule ^$ index.md [L]
    RewriteCond %{HTTP_ACCEPT} text/markdown [NC,OR]
    {$agents}
    RewriteCond %{REQUEST_FILENAME} ^(.+?)/*$
    RewriteCond %1.md -f
    RewriteRule ^(.+?)/?$ $1.md [L]
    RewriteCond %{HTTP_ACCEPT} text/plain [NC]
    RewriteCond %{REQUEST_FILENAME}/index.md -f
    RewriteRule ^$ index.md [E=PHOLIO_PLAIN:1,L]
    RewriteCond %{HTTP_ACCEPT} text/plain [NC]
    RewriteCond %{REQUEST_FILENAME} ^(.+?)/*$
    RewriteCond %1.md -f
    RewriteRule ^(.+?)/?$ $1.md [E=PHOLIO_PLAIN:1,L]

RULES;
            $agentHeaders .= "\n    Header set Content-Type \"text/plain; charset=utf-8\" env=REDIRECT_PHOLIO_PLAIN";
        }

        $rules = [];
        $seen = [];
        foreach ($redirects as $i => $entry) {
            $from = $entry['from'];
            $to = $entry['to'];
            if (!str_starts_with($from, $base)) {
                throw new ConfigException("redirect {$i}: from is not below {$base}: {$from}");
            }
            if (preg_match('#^/[A-Za-z0-9/_.\-]*$#', $to) !== 1) {
                throw new ConfigException("redirect {$i}: unexpected target: {$to}");
            }
            if (isset($seen[$from])) {
                throw new ConfigException("redirect {$i}: duplicate from: {$from}");
            }
            $seen[$from] = true;

            $relative = substr($from, strlen($base));
            if ($relative === '') {
                throw new ConfigException("redirect {$i}: the start page itself cannot be redirected");
            }
            if ($relative === 'index.html') {
                // Only the client's request: DirectoryIndex maps the directory to
                // index.html internally, without this condition the rule would loop.
                $rules[] = '    RewriteCond %{THE_REQUEST} \s' . preg_quote($from) . '[\s?]';
                $rules[] = '    RewriteRule ^index\.html$ ' . $to . ' [R=301,L]';
                continue;
            }
            // An old directory URL matches with and without the trailing slash.
            $pattern = str_ends_with($relative, '/')
                ? '^' . preg_quote(substr($relative, 0, -1)) . '/?$'
                : '^' . preg_quote($relative) . '$';
            $rules[] = '    RewriteRule ' . $pattern . ' ' . $to . ' [R=301,L]';
        }

        $redirectBlock = $rules === [] ? '    # No redirects configured.' : implode("\n", $rules);

        return <<<HTACCESS
# Generated by Pholio. Do not edit.

Options -Indexes -ExecCGI -Includes

DirectoryIndex index.html

<IfModule mod_dir.c>
    # Pages have URLs without a trailing slash; x is mapped to x/index.html
    # internally instead of a 301 to x/.
    DirectorySlash Off
</IfModule>

<IfModule mod_mime.c>
    AddType text/html .html .htm
    AddType text/css .css
    AddType application/javascript .js
    AddType application/json .json
    AddType font/woff2 .woff2
    AddType image/png .png
    AddType image/jpeg .jpg .jpeg
    AddType image/webp .webp
    AddType image/x-icon .ico
    AddType image/svg+xml .svg
    AddType application/pdf .pdf
    AddType audio/mp4 .m4a
    AddType audio/mpeg .mp3
    AddType text/plain .txt
    AddType text/markdown .md
    AddType application/xml .xml
    AddType application/manifest+json .webmanifest
    AddCharset utf-8 .md .txt
</IfModule>

<IfModule mod_headers.c>
    Header always set X-Content-Type-Options "nosniff"
    Header always set X-Frame-Options "SAMEORIGIN"
    Header always set Referrer-Policy "strict-origin-when-cross-origin"
    Header always set Permissions-Policy "accelerometer=(), bluetooth=(), camera=(), geolocation=(), gyroscope=(), magnetometer=(), microphone=(), payment=(), serial=(), usb=()"
    Header always set Content-Security-Policy "{$csp}"{$agentHeaders}

    <FilesMatch "(?i)\.(?:html?|css|js|json|webmanifest|txt|md|xml|svg|png|jpe?g|gif|webp|ico|pdf|m4a|mp3|mp4)$">
        Header always set Cache-Control "no-cache, max-age=0, must-revalidate"
        Header always set Pragma "no-cache"
        Header always set Expires "0"
    </FilesMatch>
</IfModule>

FileETag MTime Size

<IfModule mod_authz_core.c>
    <LimitExcept GET HEAD OPTIONS>
        Require all denied
    </LimitExcept>
</IfModule>
<IfModule !mod_authz_core.c>
    <LimitExcept GET HEAD OPTIONS>
        Order deny,allow
        Deny from all
    </LimitExcept>
</IfModule>

<IfModule mod_authz_core.c>
    <FilesMatch "(?i)(^\.|~$|^(?:Thumbs\.db|Desktop\.ini)$|\.(?:bak|old|orig|save|swp|swo|tmp|temp|log|sql|sqlite|db|ini|env|php[0-9]?|phtml|phar|cgi|pl|py|sh|bash|zsh|exe|dll|so|dylib|jar|war|class)$)">
        Require all denied
    </FilesMatch>
</IfModule>
<IfModule !mod_authz_core.c>
    <FilesMatch "(?i)(^\.|~$|^(?:Thumbs\.db|Desktop\.ini)$|\.(?:bak|old|orig|save|swp|swo|tmp|temp|log|sql|sqlite|db|ini|env|php[0-9]?|phtml|phar|cgi|pl|py|sh|bash|zsh|exe|dll|so|dylib|jar|war|class)$)">
        Order deny,allow
        Deny from all
    </FilesMatch>
</IfModule>

<IfModule mod_rewrite.c>
    RewriteEngine On
    RewriteBase {$base}

    # Old URLs: one permanent redirect per configured entry.
{$redirectBlock}
{$negotiation}
    # The start page without a trailing slash.
    RewriteRule ^$ index.html [L]

    # Pages without a trailing slash: internally to index.html, no redirect.
    RewriteCond %{REQUEST_FILENAME} !-f
    RewriteCond %{REQUEST_FILENAME}/index.html -f
    RewriteRule ^(.+?)/?$ $1/index.html [L]
</IfModule>
HTACCESS;
    }
}
