#!/bin/sh
# Install a Pholio release into a directory of your project.
#
#   curl -fsSL https://raw.githubusercontent.com/luka-loehr/pholio/main/scripts/install.sh | sh
#   curl -fsSL .../install.sh | sh -s -- --dir vendor/pholio --version v0.1.0
#
# Options:
#   --dir <path>        Target directory, must not exist or be empty. Default: vendor/pholio
#   --version <v>       latest (default), vX.Y.Z or X.Y.Z
#
# Downloads the release archive and SHA256SUMS from GitHub, verifies the checksum,
# checks PHP (8.2+, mbstring, ctype) and PCRE2 (10.43+), and extracts the archive.
set -eu

REPO="luka-loehr/pholio"
dir="vendor/pholio"
version="latest"

say() { printf 'pholio-install: %s\n' "$*"; }
die() { printf 'pholio-install: error: %s\n' "$*" >&2; exit 1; }

while [ $# -gt 0 ]; do
    case "$1" in
        --dir) [ $# -ge 2 ] || die "--dir needs a value"; dir="$2"; shift 2 ;;
        --dir=*) dir="${1#--dir=}"; shift ;;
        --version) [ $# -ge 2 ] || die "--version needs a value"; version="$2"; shift 2 ;;
        --version=*) version="${1#--version=}"; shift ;;
        -h|--help) sed -n '2,13p' "$0" 2>/dev/null | sed 's/^# \{0,1\}//'; exit 0 ;;
        *) die "unknown argument: $1 (see --help)" ;;
    esac
done
[ -n "$dir" ] || die "--dir must not be empty"

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

if [ -e "$dir" ]; then
    [ -d "$dir" ] || die "$dir exists and is not a directory"
    [ -z "$(ls -A "$dir")" ] || die "$dir is not empty; remove it or pass another --dir"
fi

# ---- download and verify -----------------------------------------------------

tmp="$(mktemp -d 2>/dev/null || mktemp -d -t pholio)"
trap 'rm -rf "$tmp"' EXIT INT TERM

name="pholio-$version"
base="https://github.com/$REPO/releases/download/v$version"
say "downloading $name.tar.gz from $base"
fetch "$base/$name.tar.gz" "$tmp/$name.tar.gz" || die "download failed: $base/$name.tar.gz (does release v$version exist?)"
fetch "$base/SHA256SUMS" "$tmp/SHA256SUMS" || die "download failed: $base/SHA256SUMS"

expected="$(awk -v f="$name.tar.gz" '$2 == f || $2 == "*" f { print $1 }' "$tmp/SHA256SUMS")"
[ -n "$expected" ] || die "SHA256SUMS has no entry for $name.tar.gz"
actual="$(sha256 "$tmp/$name.tar.gz")"
[ "$expected" = "$actual" ] || die "checksum mismatch for $name.tar.gz: expected $expected, got $actual"
say "checksum verified ($actual)"

# ---- extract -----------------------------------------------------------------

tar -xzf "$tmp/$name.tar.gz" -C "$tmp" || die "could not extract $name.tar.gz"
[ -f "$tmp/$name/bin/pholio" ] || die "archive does not contain $name/bin/pholio"
mkdir -p "$dir" || die "could not create $dir"
# Copy the contents, dotfiles included, into the (empty) target.
(cd "$tmp/$name" && tar -cf - .) | (cd "$dir" && tar -xf -) || die "could not copy files into $dir"

installed="$(php "$dir/bin/pholio" --version)" || die "the installed $dir/bin/pholio does not run"
say "installed $installed into $dir"

if [ "$pcre_ok" = 0 ]; then
    say "warning: PCRE2 $pcre_version is older than 10.43. Pholio works, but a few syntax"
    say "warning: highlighting patterns are switched off and highlighting will not match the reference."
fi

cat <<NEXT

Next steps:

  # Try the demo site
  php $dir/bin/pholio build --config $dir/examples/demo/pholio.config.php

  # Start your own documentation
  cp $dir/pholio.config.example.php docs.config.php
  mkdir -p content
  printf -- '---\ntitle: Welcome\ndescription: The first page.\n---\n\nHello.\n' > content/index.md
  php $dir/bin/pholio build --config docs.config.php
  php $dir/bin/pholio dev --config docs.config.php

Documentation: https://github.com/$REPO#readme
NEXT
