#!/bin/sh
# Install a Pholio release.
#
#   curl -fsSL https://pholio.lukaloehr.com/install.sh | sh
#   curl -fsSL .../install.sh | sh -s -- --version v0.1.0
#   curl -fsSL .../install.sh | sh -s -- --dir vendor/pholio
#
# By default Pholio is installed for the current user: the release goes into
# $PHOLIO_HOME/<version> (default ~/.local/share/pholio) and `pholio` is linked into
# ~/.local/bin. Running the script again installs or switches to the requested
# version; older versions stay unless --prune is given.
#
# Options:
#   --version <v>   latest (default), vX.Y.Z or X.Y.Z
#   --system        link into /usr/local/bin, install into /usr/local/share/pholio (may need sudo)
#   --prune         remove every other installed version
#   --dir <path>    per-project install into <path> (must not exist or be empty), no link
#
# Downloads the release archive and SHA256SUMS from GitHub, verifies the checksum,
# checks PHP (8.2+, mbstring, ctype) and PCRE2 (10.43+), and extracts the archive.
set -eu

REPO="luka-loehr/pholio"
version="latest"
dir=""
system=0
prune=0

say() { printf 'pholio-install: %s\n' "$*"; }
die() { printf 'pholio-install: error: %s\n' "$*" >&2; exit 1; }

while [ $# -gt 0 ]; do
    case "$1" in
        --dir) [ $# -ge 2 ] || die "--dir needs a value"; dir="$2"; shift 2 ;;
        --dir=*) dir="${1#--dir=}"; shift ;;
        --version) [ $# -ge 2 ] || die "--version needs a value"; version="$2"; shift 2 ;;
        --version=*) version="${1#--version=}"; shift ;;
        --system) system=1; shift ;;
        --prune) prune=1; shift ;;
        -h|--help) sed -n '2,23p' "$0" 2>/dev/null | sed 's/^# \{0,1\}//'; exit 0 ;;
        *) die "unknown argument: $1 (see --help)" ;;
    esac
done

if [ -n "$dir" ]; then
    [ "$system" = 0 ] && [ "$prune" = 0 ] || die "--dir cannot be combined with --system or --prune"
elif [ "$system" = 1 ]; then
    home="${PHOLIO_HOME:-/usr/local/share/pholio}"
    bindir="/usr/local/bin"
else
    [ -n "${HOME:-}" ] || die "HOME is not set; pass --dir <path> or set PHOLIO_HOME"
    home="${PHOLIO_HOME:-$HOME/.local/share/pholio}"
    bindir="$HOME/.local/bin"
fi

# ---- tools -------------------------------------------------------------------

if command -v curl >/dev/null 2>&1; then
    fetch() { curl -fsSL --retry 3 -o "$2" "$1"; }
    fetch_stdout() { curl -fsSL --retry 3 "$1"; }
elif command -v wget >/dev/null 2>&1; then
    fetch() { wget -q -O "$2" "$1"; }
    fetch_stdout() { wget -q -O - "$1"; }
else
    die "curl or wget is required"
fi
command -v tar >/dev/null 2>&1 || die "tar is required"
if command -v sha256sum >/dev/null 2>&1; then
    sha256() { sha256sum "$1" | cut -d' ' -f1; }
elif command -v shasum >/dev/null 2>&1; then
    sha256() { shasum -a 256 "$1" | cut -d' ' -f1; }
else
    die "sha256sum or shasum is required to verify the download"
fi

# ---- PHP ---------------------------------------------------------------------

command -v php >/dev/null 2>&1 || die "PHP is not on PATH. Pholio needs PHP 8.2 or newer (command line): https://www.php.net/downloads"
php_version="$(php -r 'echo PHP_VERSION;')" || die "could not run php"
php -r 'exit(PHP_VERSION_ID >= 80200 ? 0 : 1);' \
    || die "PHP $php_version is too old. Pholio needs PHP 8.2 or newer"
for ext in mbstring ctype; do
    php -r "exit(extension_loaded('$ext') ? 0 : 1);" \
        || die "the PHP extension $ext is not loaded (install it, e.g. php-$ext, and enable it in php.ini)"
done
pcre_version="$(php -r 'echo explode(" ", PCRE_VERSION)[0];')"
pcre_ok=1
php -r 'exit(version_compare(explode(" ", PCRE_VERSION)[0], "10.43", ">=") ? 0 : 1);' || pcre_ok=0
say "PHP $php_version, PCRE2 $pcre_version"

# ---- version -----------------------------------------------------------------

if [ "$version" = latest ]; then
    version="$(fetch_stdout "https://api.github.com/repos/$REPO/releases/latest" \
        | sed -n 's/.*"tag_name"[[:space:]]*:[[:space:]]*"\([^"]*\)".*/\1/p' | head -n 1)" \
        || true
    [ -n "$version" ] || die "could not determine the latest release of $REPO (pass --version vX.Y.Z)"
fi
version="${version#v}"
case "$version" in
    [0-9]*.[0-9]*.[0-9]*) ;;
    *) die "not a version: $version (expected latest, vX.Y.Z or X.Y.Z)" ;;
esac

# ---- target ------------------------------------------------------------------

if [ -n "$dir" ]; then
    target="$dir"
    if [ -e "$target" ]; then
        [ -d "$target" ] || die "$target exists and is not a directory"
        [ -z "$(ls -A "$target")" ] || die "$target is not empty; remove it or pass another --dir"
    fi
else
    target="$home/$version"
    mkdir -p "$home" "$bindir" 2>/dev/null \
        || die "cannot create $home or $bindir$( [ "$system" = 1 ] && echo ' (run with sudo)')"
    [ -w "$home" ] && [ -w "$bindir" ] \
        || die "$home or $bindir is not writable$( [ "$system" = 1 ] && echo ' (run with sudo)')"
fi

tmp="$(mktemp -d 2>/dev/null || mktemp -d -t pholio)"
trap 'rm -rf "$tmp"' EXIT INT TERM

name="pholio-$version"

if [ -z "$dir" ] && [ -f "$target/bin/pholio" ] && [ "$(tr -d '[:space:]' < "$target/VERSION" 2>/dev/null)" = "$version" ]; then
    say "pholio $version is already installed in $target"
else
    # ---- download and verify -------------------------------------------------

    base="https://github.com/$REPO/releases/download/v$version"
    say "downloading $name.tar.gz from $base"
    fetch "$base/$name.tar.gz" "$tmp/$name.tar.gz" || die "download failed: $base/$name.tar.gz (does release v$version exist?)"
    fetch "$base/SHA256SUMS" "$tmp/SHA256SUMS" || die "download failed: $base/SHA256SUMS"

    expected="$(awk -v f="$name.tar.gz" '$2 == f || $2 == "*" f { print $1 }' "$tmp/SHA256SUMS")"
    [ -n "$expected" ] || die "SHA256SUMS has no entry for $name.tar.gz"
    actual="$(sha256 "$tmp/$name.tar.gz")"
    [ "$expected" = "$actual" ] || die "checksum mismatch for $name.tar.gz: expected $expected, got $actual"
    say "checksum verified ($actual)"

    # ---- extract -------------------------------------------------------------

    tar -xzf "$tmp/$name.tar.gz" -C "$tmp" || die "could not extract $name.tar.gz"
    [ -f "$tmp/$name/bin/pholio" ] || die "archive does not contain $name/bin/pholio"
    if [ -n "$dir" ]; then
        mkdir -p "$target" || die "could not create $target"
        (cd "$tmp/$name" && tar -cf - .) | (cd "$target" && tar -xf -) || die "could not copy files into $target"
    else
        # Replace a broken or partial earlier install of the same version in one step.
        rm -rf "$target.partial"
        mv "$tmp/$name" "$target.partial" 2>/dev/null \
            || { cp -R "$tmp/$name" "$target.partial" || die "could not copy files into $home"; }
        rm -rf "$target"
        mv "$target.partial" "$target" || die "could not move the release into $target"
    fi
fi
chmod +x "$target/bin/pholio" 2>/dev/null || true

# ---- link --------------------------------------------------------------------

if [ -n "$dir" ]; then
    cmd="php $dir/bin/pholio"
    installed="$($cmd --version)" || die "the installed $dir/bin/pholio does not run"
    say "installed $installed into $dir"
else
    link="$bindir/pholio"
    if [ -e "$link" ] && [ ! -L "$link" ]; then
        die "$link exists and is not a symlink; remove it or use --dir"
    fi
    if ! { ln -sf "$target/bin/pholio" "$link.new" && mv -f "$link.new" "$link"; }; then
        die "could not link $link"
    fi
    installed="$("$link" --version)" || die "$link does not run"
    say "installed $installed into $target"
    say "linked $link -> $target/bin/pholio"

    if [ "$prune" = 1 ]; then
        for old in "$home"/*; do
            [ -d "$old" ] || continue
            [ "$old" = "$target" ] && continue
            case "$(basename "$old")" in
                [0-9]*.[0-9]*.[0-9]*) rm -rf "$old" && say "removed $old" ;;
            esac
        done
    fi

    cmd="pholio"
    case ":${PATH:-}:" in
        *":$bindir:"*) ;;
        *)
            cmd="$link"
            say "$bindir is not on your PATH. Add it, then open a new terminal:"
            # shellcheck disable=SC2016 # $PATH is meant literally in the printed line
            printf '\n  echo '"'"'export PATH="%s:$PATH"'"'"' >> ~/.profile\n\n' "$bindir"
            ;;
    esac
fi

if [ "$pcre_ok" = 0 ]; then
    say "warning: PCRE2 $pcre_version is older than 10.43: highlighting of a few languages is simplified."
fi

if $cmd --help 2>/dev/null | grep -q 'pholio init'; then
    cat <<NEXT

Next steps:

  $cmd init my-docs  # creates pholio.config.php, content/ and assets/
  cd my-docs
  $cmd dev           # preview at http://127.0.0.1:8080, rebuilds when you save
  $cmd build         # writes the static site into public/

Documentation: https://github.com/$REPO#readme
NEXT
else
    cat <<NEXT

Next steps (pholio $version has no init command yet; newer releases do):

  cp $target/pholio.config.example.php docs.config.php
  mkdir -p content && printf -- '---\ntitle: Welcome\n---\n\nHello.\n' > content/index.md
  $cmd dev --config docs.config.php

Documentation: https://github.com/$REPO#readme
NEXT
fi
