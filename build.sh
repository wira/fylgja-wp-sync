#!/usr/bin/env bash
#
# Build a distributable WordPress plugin zip for fylgja-wp-sync.
#
# Packages only the plugin's runtime files (PHP + assets + README/LICENSE) under
# a top-level "fylgja-wp-sync/" directory, so the archive extracts straight into
# wp-content/plugins/fylgja-wp-sync/. .gitignore is honored by enumerating files
# with `git ls-files`: anything ignored is untracked and can never slip in.
#
# The zip is written to build/ (gitignored), keeping artifacts out of the repo root.
#
# Usage: ./build.sh

set -euo pipefail

SLUG="fylgja-wp-sync"
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$ROOT"

MAIN_FILE="$SLUG.php"

# We rely on git to honor .gitignore, so require a work tree.
if ! git rev-parse --is-inside-work-tree >/dev/null 2>&1; then
    echo "Error: not inside a git work tree; cannot honor .gitignore." >&2
    exit 1
fi

# Read the version straight from the plugin header (single source of truth).
VERSION="$(sed -nE 's/^[[:space:]]*\*[[:space:]]*Version:[[:space:]]*(.+)$/\1/Ip' "$MAIN_FILE" \
    | head -n1 | tr -d '[:space:]')"
if [[ -z "$VERSION" ]]; then
    echo "Error: could not read Version from $MAIN_FILE header." >&2
    exit 1
fi

ZIP="$SLUG-$VERSION.zip"
BUILD_DIR="$ROOT/build"
ZIP_PATH="$BUILD_DIR/$ZIP"

# Runtime files that make up the published plugin.
INCLUDE_PATHS=(
    "$MAIN_FILE"
    "uninstall.php"
    "includes"
    "assets"
    "README.md"
    "LICENSE"
)

# Resolve to git-tracked files only (this is how .gitignore is honored).
mapfile -t FILES < <(git ls-files -- "${INCLUDE_PATHS[@]}")
if [[ ${#FILES[@]} -eq 0 ]]; then
    echo "Error: no tracked files matched the include list." >&2
    exit 1
fi

# Stage under the slug directory, zip from there, clean up on exit.
STAGE="$(mktemp -d)"
trap 'rm -rf "$STAGE"' EXIT

for f in "${FILES[@]}"; do
    dest="$STAGE/$SLUG/$f"
    mkdir -p "$(dirname "$dest")"
    cp "$f" "$dest"
done

mkdir -p "$BUILD_DIR"
rm -f "$ZIP_PATH"
( cd "$STAGE" && zip -rq "$ZIP_PATH" "$SLUG" )

echo "Created build/$ZIP (${#FILES[@]} files)"
( cd "$STAGE" && find "$SLUG" -type f | sort | sed 's/^/  /' )
