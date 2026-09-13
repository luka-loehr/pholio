![Pholio banner](docs/assets/banner.png)

# Pholio – Beautiful documentation, powered by Markdown

[![Version](https://img.shields.io/badge/version-0.1.0-blue.svg?style=flat)](VERSION)
[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg?style=flat)](LICENSE)
[![PHP](https://img.shields.io/badge/PHP-%E2%89%A5%208.2-777bb4.svg?style=flat)](https://www.php.net/releases/8.2/en.php)
[![CI](https://github.com/luka-loehr/pholio/actions/workflows/ci.yml/badge.svg)](https://github.com/luka-loehr/pholio/actions/workflows/ci.yml)

**Pholio** turns a folder of Markdown files into beautiful documentation: one polished theme, instant search and highlighted code, built by plain PHP into static files.

---

## Features

- **Markdown first**, plus a few component tags: callouts, cards, tabs, steps, accordions, file trees, type tables
- **One polished theme** with light and dark mode, sidebar, table of contents and keyboard shortcuts
- **Eleven color presets**, one line to switch, with your own color tokens on top
- **Instant search** built at compile time, no backend
- **Agent-ready**: Markdown for every page, `llms.txt`, `skill.md` and content negotiation, generated from your content
- **Syntax highlighting** at build time, with titles, line numbers, diffs and code tabs
- **Zero dependencies**: PHP 8.2 is all you need
- **Static output** that runs on any host, with a ready `.htaccess` for Apache
- **English and German** interface
- **Fails loud**: unknown components, icons or languages stop the build with file and line

---

## Quick start

```bash
curl -fsSL https://pholio.lukaloehr.com/install.sh | sh
pholio init my-docs
cd my-docs
pholio dev      # http://127.0.0.1:8080
pholio build    # static site in public/
```

Requires PHP 8.2+ with `mbstring` and `ctype` (PCRE2 10.43+ recommended for full syntax highlighting). The installer links `pholio` into `~/.local/bin` and prints the line to add if that folder is not on your `PATH`. More in [Getting started](docs/getting-started.md).

---

## Documentation

- [Getting started](docs/getting-started.md)
- [Content format](docs/content-format.md)
- [Components](docs/components.md)
- [Configuration](docs/configuration.md)
- [Themes](docs/themes.md)
- [Agents](docs/agents.md)
- [Architecture](docs/architecture.md)
- [Roadmap](docs/roadmap.md)
- [Demo site](examples/demo/README.md)

---

## License

MIT – [View License](LICENSE)  
Third-party fonts, icons, grammars and design sources keep their own licenses: [THIRD_PARTY_NOTICES.md](THIRD_PARTY_NOTICES.md)

---

## Support

- [Report bugs](https://github.com/luka-loehr/pholio/issues)  
- [luka@lukaloehr.com](mailto:luka@lukaloehr.com)  

---

Developed by [Luka Löhr](https://github.com/luka-loehr)
