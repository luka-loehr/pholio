<?php

declare(strict_types=1);

namespace Pholio;

use Closure;
use Pholio\Card\CardException;
use Pholio\Card\Layout;
use Pholio\Card\Svg;
use Pholio\Card\Text;
use Pholio\Card\TrueType;

require_once __DIR__ . '/../Exceptions.php';
require_once __DIR__ . '/../Fs.php';
require_once __DIR__ . '/Tree.php';
require_once __DIR__ . '/AgentSite.php';
foreach (['CardException', 'TrueType', 'Raster', 'Canvas', 'Png', 'Color', 'Svg', 'Text', 'Layout'] as $part) {
    require_once __DIR__ . '/Card/' . $part . '.php';
}

/**
 * What a link to the site shows when it is shared: the Open Graph and X
 * (Twitter) tags of every page, and the share card images they point to
 * (docs/configuration.md, "Share cards").
 *
 * With `site.url` set, every page gets og:type, og:site_name, og:title,
 * og:description, og:url and the twitter:* tags. With the share cards on (the
 * default with `site.url`), the build also draws one PNG per page, 1200 × 630
 * (Card\Layout), and the tags name it as og:image with twitter:card
 * `summary_large_image`. Paths are relative to the start page's directory:
 *
 *   og.png              the site card: the start page, or the page published at the start page's URL
 *   og/<page>.png       one card per other page, at its published path ("guide/install" gives og/guide/install.png)
 *
 * A file the site provides itself (in a `copy` directory or `output.keep`) is
 * not drawn, so a site can replace any card by hand.
 *
 * Every text is checked against the fonts before it is drawn: a character the
 * font has no glyph for stops the build with the page file, instead of drawing
 * an empty box.
 */
final class ShareCards
{
    public const DIRECTORY = 'og';
    public const SITE_CARD = 'og.png';

    /** @var array<string, mixed>|null fonts and logo, loaded with the first card */
    private ?array $style = null;

    /** @var array<string, string> page URL => absolute page file */
    private array $files = [];

    /**
     * @param array<string,mixed> $config normalised
     * @param Closure(string):Document $parse absolute page file => document
     * @param Closure(string, bool):string $outputPath page URL, whether it is the start page => HTML file
     *        relative to the start page's directory (Builder::outputPath)
     */
    public function __construct(
        private readonly array $config,
        private readonly Tree $tree,
        private readonly Closure $parse,
        private readonly Closure $outputPath,
    ) {
        foreach ($tree->pages() as $page) {
            $this->files[$page['url']] = $config['contentDir'] . '/' . ltrim($page['file'], '/');
        }
    }

    public function enabled(): bool
    {
        return $this->config['shareCard'] !== null;
    }

    /**
     * Card file of a page, relative to the start page's directory.
     */
    public function imagePath(string $url, bool $isHome = false): string
    {
        $html = ($this->outputPath)($url, $isHome);

        return $html === 'index.html' ? self::SITE_CARD : self::DIRECTORY . '/' . substr($html, 0, -strlen('/index.html')) . '.png';
    }

    /** Absolute URL of a page's card, or null when there are no cards. */
    public function imageUrl(string $url, bool $isHome = false): ?string
    {
        return $this->enabled() ? $this->published($this->imagePath($url, $isHome)) : null;
    }

    /**
     * The Open Graph and X tags of a content page, for nd_document()'s `social`.
     *
     * @return list<array{0:string, 1:string, 2:string}> attribute, name, content
     */
    public function head(string $url): array
    {
        $page = $this->page($url);
        // The docs root published at the start page's URL stands for the site: its link shows the
        // site's name, as the site card does, not a page title such as "Home".
        $title = ($this->outputPath)($url, false) === 'index.html' ? $this->config['title'] : $page['title'];

        return $this->tags($title, $page['description'], $url, false);
    }

    /** @return list<array{0:string, 1:string, 2:string}> */
    public function homeHead(): array
    {
        return $this->tags($this->config['homeTitle'], AgentSite::describe($this->config, $this->tree), $this->config['homeUrl'], true);
    }

    /**
     * Draw the cards of the pages written by this build: the start page when
     * $home, and the content pages at $urls.
     *
     * @param list<string> $urls content page URLs
     * @param Closure(string):bool $authored path relative to $target => the site provides that file itself
     * @return int cards written
     * @throws ConfigException for an unusable font or logo
     * @throws ContentException for a character the fonts lack, with the page file
     */
    public function write(string $target, array $urls, bool $home, Closure $authored): int
    {
        if (!$this->enabled()) {
            return 0;
        }
        $written = 0;
        $draw = function (string $relative, Closure $render) use ($target, $authored, &$written): void {
            if ($authored($relative)) {
                return;
            }
            Fs::writeBytes($target, $relative, $render()->png());
            $written++;
        };

        if ($home) {
            $draw(self::SITE_CARD, fn(): Card\Canvas => $this->siteCard($this->config['homeUrl'], true, $this->config['configFile'] ?: null));
        }
        foreach ($urls as $url) {
            $relative = $this->imagePath($url);
            $file = $this->file($url);
            if ($relative === self::SITE_CARD) {
                $draw($relative, fn(): Card\Canvas => $this->siteCard($url, false, $file));
                continue;
            }
            $page = $this->page($url);
            $draw($relative, fn(): Card\Canvas => $this->pageCard($page['title'], $page['description'], $this->location($url), $file));
        }

        return $written;
    }

    // ------------------------------------------------------------------ cards

    private function pageCard(string $title, ?string $description, string $location, string $file): Card\Canvas
    {
        $style = $this->style();
        $this->checkGlyphs($style['heading'], $title, 'the title', $file);
        if ($description !== null) {
            $this->checkGlyphs($style['text'], $description, 'the description', $file);
        }
        $this->checkGlyphs($style['text'], $location, 'the URL', $file);

        return Layout::page($style, $title, $description, $location);
    }

    private function siteCard(string $url, bool $isHome, ?string $file): Card\Canvas
    {
        $style = $this->style();
        $description = AgentSite::describe($this->config, $this->tree);
        if ($description !== null) {
            $this->checkGlyphs($style['text'], $description, 'the site description', $file);
        }
        $location = $this->location($url, $isHome);
        $this->checkGlyphs($style['text'], $location, 'the URL', $file);

        return Layout::site($style, $description, $location);
    }

    /**
     * Fonts, logo and colours, loaded once; the brand is checked against the heading font.
     *
     * @return array{brand:string, logo:?Svg, heading:Text, text:Text, colors:array<string, array{0:int,1:int,2:int}>}
     * @throws ConfigException
     */
    private function style(): array
    {
        if ($this->style !== null) {
            return $this->style;
        }
        $card = $this->config['shareCard'];
        $configFile = $this->config['configFile'] ?: null;
        $fail = static fn(string $key, CardException $e): ConfigException
            => new ConfigException($key . ': ' . $e->source . ': ' . $e->getMessage(), $configFile, null, $e);

        $fonts = [];
        foreach (['heading', 'text'] as $role) {
            try {
                $fonts[$role] = new Text(TrueType::load($card['fonts'][$role]));
            } catch (CardException $e) {
                throw $fail('share_card.fonts.' . $role, $e);
            }
            if ($fonts[$role]->missing(Text::ELLIPSIS) !== []) {
                throw new ConfigException(
                    'share_card.fonts.' . $role . ': ' . $card['fonts'][$role] . ' has no glyph for … (U+2026), which shortens text that is too long',
                    $configFile,
                );
            }
        }
        $missing = $fonts['heading']->missing($card['brand']);
        if ($missing !== []) {
            throw new ConfigException(
                'share_card.brand: ' . $card['fonts']['heading'] . ' has no glyph for ' . self::describeCodePoints($missing)
                . '; set share_card.fonts.heading to a font that has them',
                $configFile,
            );
        }

        $logo = null;
        if ($card['logo'] !== null) {
            try {
                $logo = Svg::load($card['logo']);
            } catch (CardException $e) {
                throw $fail($card['logoKey'], $e);
            }
        }

        return $this->style = [
            'brand' => $card['brand'],
            'logo' => $logo,
            'heading' => $fonts['heading'],
            'text' => $fonts['text'],
            'colors' => $card['colors'],
        ];
    }

    /** @throws ContentException naming the characters, the font and what to do */
    private function checkGlyphs(Text $font, string $text, string $what, ?string $file): void
    {
        $missing = $font->missing($text);
        if ($missing === []) {
            return;
        }
        throw new ContentException(
            'share card: ' . $what . ' has ' . self::describeCodePoints($missing) . ', which ' . basename($font->font->file())
            . ' has no glyph for; set share_card.fonts to fonts that cover them, or share_card.enabled to false',
            $file,
        );
    }

    /** @param list<int> $codePoints */
    private static function describeCodePoints(array $codePoints): string
    {
        return implode(', ', array_map(static fn(int $c): string => '"' . mb_chr($c) . '" (U+' . sprintf('%04X', $c) . ')', $codePoints));
    }

    // ------------------------------------------------------------------ tags

    /** @return list<array{0:string, 1:string, 2:string}> */
    private function tags(string $title, ?string $description, string $url, bool $isHome): array
    {
        if ($this->config['site']['url'] === null) {
            return [];
        }
        $title = self::oneLine($title);
        $description = $description === null || trim($description) === '' ? null : self::oneLine($description);
        $website = $isHome || ($this->outputPath)($url, false) === 'index.html';
        $image = $this->imageUrl($url, $isHome);

        $tags = [
            ['property', 'og:type', $website ? 'website' : 'article'],
            ['property', 'og:site_name', $this->config['title']],
            ['property', 'og:title', $title],
        ];
        if ($description !== null) {
            $tags[] = ['property', 'og:description', $description];
        }
        $tags[] = ['property', 'og:url', $this->published($this->htmlPath($url, $isHome))];
        if ($image !== null) {
            $alt = $this->config['shareCard']['brand'] . ': ' . ($website ? ($description ?? $title) : $title);
            array_push(
                $tags,
                ['property', 'og:image', $image],
                ['property', 'og:image:type', 'image/png'],
                ['property', 'og:image:width', (string) Layout::WIDTH],
                ['property', 'og:image:height', (string) Layout::HEIGHT],
                ['property', 'og:image:alt', $alt],
            );
        }
        $tags[] = ['name', 'twitter:card', $image !== null ? 'summary_large_image' : 'summary'];
        $tags[] = ['name', 'twitter:title', $title];
        if ($description !== null) {
            $tags[] = ['name', 'twitter:description', $description];
        }
        if ($image !== null) {
            $tags[] = ['name', 'twitter:image', $image];
            $tags[] = ['name', 'twitter:image:alt', $alt];
        }

        return $tags;
    }

    // ------------------------------------------------------------------ pages

    /** @return array{title:string, description:?string} */
    private function page(string $url): array
    {
        $frontmatter = ($this->parse)($this->file($url))->frontmatter;
        $title = trim((string) ($frontmatter['title'] ?? $frontmatter['heading'] ?? ''));
        $description = isset($frontmatter['description']) && trim((string) $frontmatter['description']) !== ''
            ? self::oneLine((string) $frontmatter['description']) : null;

        return ['title' => $title === '' ? $this->config['title'] : self::oneLine($title), 'description' => $description];
    }

    private function file(string $url): string
    {
        return $this->files[$url] ?? throw new \LogicException('no page at ' . $url);
    }

    /** Host and path of a page as the card shows it: "docs.example.org/guide/install". */
    private function location(string $url, bool $isHome = false): string
    {
        $origin = (string) $this->config['site']['url'];
        $host = (string) preg_replace('#^https?://#i', '', $origin);
        $path = $this->htmlPath($url, $isHome);

        return $path === '/' ? $host : $host . rawurldecode($path);
    }

    /** The URL path a page is published at, after `docs_root_suffix`. */
    private function htmlPath(string $url, bool $isHome): string
    {
        $html = ($this->outputPath)($url, $isHome);

        return $html === 'index.html' ? $this->config['homeUrl'] : $this->homePath(substr($html, 0, -strlen('/index.html')));
    }

    private function homePath(string $relative): string
    {
        return rtrim($this->config['homeUrl'], '/') . '/' . $relative;
    }

    private function published(string $path): string
    {
        $path = str_starts_with($path, '/') ? $path : $this->homePath($path);

        return $this->config['site']['url'] . $path;
    }

    private static function oneLine(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }
}
