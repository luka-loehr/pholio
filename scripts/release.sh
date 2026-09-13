#!/usr/bin/env bash
# Release helper.
#
#   scripts/release.sh <version> [--push]     bump VERSION, date CHANGELOG.md, run the checks,
#                                             commit both files, tag v<version>
#   scripts/release.sh --package <ref> <dir>  write pholio-<version>.tar.gz, .zip and SHA256SUMS
#                                             for a git ref into <dir> (used by .github/workflows/release.yml)
#   scripts/release.sh --notes <version>      print the CHANGELOG.md section of <version>
#
# Without --push nothing leaves the machine; the push command is printed at the end.
set -euo pipefail

cd "$(dirname "$0")/.."

# What a user needs to run Pholio. tests/, verify/, docs/ and tools/ stay in the repository.
PACKAGE_PATHS=(
    bin src theme vendor-data licenses examples/demo
    LICENSE THIRD_PARTY_NOTICES.md VERSION pholio.config.example.php README.md CHANGELOG.md
)
VERSION_RE='^[0-9]+\.[0-9]+\.[0-9]+(-(alpha|beta|rc)(\.?[0-9]+)?)?$'

die() {
    echo "release: $*" >&2
    exit 1
}

sha256() {
    if command -v sha256sum > /dev/null; then sha256sum "$@"; else shasum -a 256 "$@"; fi
}

notes() {
    local version="$1"
    awk -v heading="## [${version}]" '
        index($0, "## [") == 1 { if (found) exit; found = (index($0, heading) == 1); next }
        index($0, "[") == 1 && index($0, "]: ") > 0 { next }
        found { print }
    ' CHANGELOG.md | sed -e '/./,$!d' | sed -e ':a' -e '/^\n*$/{$d;N;ba' -e '}'
}

package() {
    local ref="$1" out="$2" version name paths=()
    git rev-parse --verify --quiet "${ref}^{commit}" > /dev/null || die "unknown git ref: $ref"
    version="$(git show "${ref}:VERSION" | tr -d '[:space:]')"
    [[ "$version" =~ $VERSION_RE ]] || die "VERSION at $ref is not a version: $version"
    name="pholio-${version}"
    for path in "${PACKAGE_PATHS[@]}"; do
        # CHANGELOG.md did not exist before 0.1.0 was tagged; every other path must.
        if git cat-file -e "${ref}:${path}" 2> /dev/null; then
            paths+=("$path")
        elif [ "$path" != CHANGELOG.md ]; then
            die "$path missing at $ref"
        fi
    done
    mkdir -p "$out"
    git archive --format=tar.gz --prefix="${name}/" -o "${out}/${name}.tar.gz" "$ref" -- "${paths[@]}"
    git archive --format=zip --prefix="${name}/" -o "${out}/${name}.zip" "$ref" -- "${paths[@]}"
    (cd "$out" && sha256 "${name}.tar.gz" "${name}.zip" > SHA256SUMS)
    echo "release: packaged ${ref} into ${out}:"
    (cd "$out" && ls -l "${name}.tar.gz" "${name}.zip" SHA256SUMS)
}

release() {
    local version="$1" push="$2" tag="v$1" today
    [[ "$version" =~ $VERSION_RE ]] || die "not a version: $version (expected X.Y.Z or X.Y.Z-rc.N)"
    [ "$(git rev-parse --abbrev-ref HEAD)" = main ] || die "not on main"
    [ -z "$(git status --porcelain)" ] || die "working tree is not clean"
    ! git rev-parse --verify --quiet "refs/tags/${tag}" > /dev/null || die "tag $tag already exists"
    [ "$(tr -d '[:space:]' < VERSION)" != "$version" ] || die "VERSION is already $version"
    grep -q '^## \[Unreleased\]' CHANGELOG.md || die "CHANGELOG.md has no ## [Unreleased] section"
    [ -n "$(notes Unreleased)" ] || die "the Unreleased section of CHANGELOG.md is empty"

    local previous
    previous="$(tr -d '[:space:]' < VERSION)"
    today="$(date -u +%Y-%m-%d)"

    trap 'git checkout -- VERSION CHANGELOG.md' ERR
    printf '%s\n' "$version" > VERSION
    awk -v version="$version" -v previous="$previous" -v today="$today" \
        -v repo="https://github.com/luka-loehr/pholio" '
        $0 == "## [Unreleased]" { print; print ""; print "## [" version "] - " today; next }
        index($0, "[Unreleased]: ") == 1 {
            print "[Unreleased]: " repo "/compare/v" version "...HEAD"
            print "[" version "]: " repo "/compare/v" previous "...v" version
            next
        }
        { print }
    ' CHANGELOG.md > CHANGELOG.md.tmp
    mv CHANGELOG.md.tmp CHANGELOG.md

    ./scripts/check.sh
    trap - ERR

    git add VERSION && git commit -m "chore(release): VERSION – ${version}" -- VERSION
    git add CHANGELOG.md && git commit -m "docs(release): CHANGELOG.md – ${version}" -- CHANGELOG.md
    git tag -a "$tag" -m "Pholio ${version}"
    echo "release: committed and tagged ${tag}"

    if [ "$push" = 1 ]; then
        git push --atomic origin main "$tag"
    else
        echo "release: nothing pushed. To publish:"
        echo "  git push --atomic origin main ${tag}"
    fi
}

case "${1:-}" in
    --package)
        [ $# -eq 3 ] || die "usage: scripts/release.sh --package <ref> <dir>"
        package "$2" "$3"
        ;;
    --notes)
        [ $# -eq 2 ] || die "usage: scripts/release.sh --notes <version>"
        body="$(notes "$2")"
        [ -n "$body" ] || die "CHANGELOG.md has no section for $2"
        printf '%s\n' "$body"
        ;;
    '' | -h | --help)
        sed -n '2,11p' "$0" | sed 's/^# \{0,1\}//'
        ;;
    *)
        push=0
        version=""
        for arg in "$@"; do
            case "$arg" in
                --push) push=1 ;;
                -*) die "unknown option: $arg" ;;
                *) [ -z "$version" ] || die "one version only"; version="$arg" ;;
            esac
        done
        [ -n "$version" ] || die "usage: scripts/release.sh <version> [--push]"
        release "${version#v}" "$push"
        ;;
esac
