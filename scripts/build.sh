#!/bin/bash
set -e

# Builds the add-on into build/:
#
#   build/client/   frametrail-conversational-ui.js and .css, for extensions/conversational-ui/
#   build/server/   the drop-in folder for _server/extensions/conversational-ui/
#   build/frametrail-conversational-ui-<version>.zip
#                   both, at their places in the FrameTrail code tree, for
#                   composing a FrameTrail release and an add-on release
#
#   bash scripts/build.sh            # version "dev"
#   bash scripts/build.sh v0.1.0     # a release

# ──────────────────────────────────────────────
#  Configuration
# ──────────────────────────────────────────────

ROOT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
CLIENT_DIR="$ROOT_DIR/client"
SERVER_DIR="$ROOT_DIR/server"
SHARED_DIR="$ROOT_DIR/shared"
BUILD_DIR="$ROOT_DIR/build"

NAME="conversational-ui"
BUNDLE="frametrail-conversational-ui"
VERSION="${1:-dev}"
VERSION_TOKEN="__CONVERSATIONAL_UI_VERSION__"

# The label goes into file names and through sed.
if ! [[ "$VERSION" =~ ^[A-Za-z0-9][A-Za-z0-9._-]*$ ]]; then
    echo "ERROR: version \"$VERSION\" may only contain letters, digits, '.', '_' and '-'." >&2
    exit 1
fi

BANNER="/*!\n * FrameTrail-Conversational-UI ${VERSION}\n * https://github.com/OpenHypervideo/FrameTrail-Conversational-UI\n * MIT\n */"

# ──────────────────────────────────────────────
#  Client files, in load order (relative to client/)
# ──────────────────────────────────────────────
#  namespace.js first: every other file fills the
#  global it creates. module.js last: it registers
#  the extension with what the others defined.
#  tests/run-js.mjs reads these lists.

JS_FILES=(
    "namespace.js"

    # Labels
    "locale/en.js"
    "locale/de.js"
    "locale/fr.js"

    # Operations
    "ops/util.js"
    "ops/items.js"
    "ops/model-store.js"
    "ops/live-store.js"
    "ops/interpreter.js"

    # Lint rules
    "lint/lint.js"

    # Extension entry
    "module.js"
)

#  Shared data (relative to shared/), written into
#  the bundle right after namespace.js as properties
#  of the global: "<property>:<file>".

SHARED_DATA=(
    "operations:operations.json"
    "changesetSchema:changeset.schema.json"
    "lintRules:lint.json"
)

CSS_FILES=(
    "ui/style.css"
)

# ──────────────────────────────────────────────
#  Clean & prepare
# ──────────────────────────────────────────────

rm -rf "$BUILD_DIR"
mkdir -p "$BUILD_DIR/client" "$BUILD_DIR/server"

echo "Building FrameTrail-Conversational-UI $VERSION ..."

# ──────────────────────────────────────────────
#  Client
# ──────────────────────────────────────────────

concat() {
    local out="$1" separator="$2"
    shift 2
    printf '%b\n\n' "$BANNER" > "$out"
    for f in "$@"; do
        if [ ! -f "$CLIENT_DIR/$f" ]; then
            echo "ERROR: missing client file: $f" >&2
            exit 1
        fi
        echo "/* === $f === */" >> "$out"
        cat "$CLIENT_DIR/$f" >> "$out"
        echo "$separator" >> "$out"
        if [ "$separator" = ";" ] && [ "$f" = "namespace.js" ]; then
            embed_shared "$out"
        fi
    done
}

# The shared data as JavaScript: JSON is an expression.
embed_shared() {
    local out="$1" entry property file
    for entry in "${SHARED_DATA[@]}"; do
        property="${entry%%:*}"
        file="${entry#*:}"
        if [ ! -f "$SHARED_DIR/$file" ]; then
            echo "ERROR: missing shared file: $file" >&2
            exit 1
        fi
        echo "/* === shared/$file === */" >> "$out"
        printf 'window.FrameTrailConversationalUI.%s = ' "$property" >> "$out"
        cat "$SHARED_DIR/$file" >> "$out"
        echo ";" >> "$out"
    done
}

echo "Concatenating JS (${#JS_FILES[@]} files, ${#SHARED_DATA[@]} shared)..."
concat "$BUILD_DIR/client/$BUNDLE.js" ";" "${JS_FILES[@]}"

echo "Concatenating CSS (${#CSS_FILES[@]} files)..."
concat "$BUILD_DIR/client/$BUNDLE.css" "" "${CSS_FILES[@]}"

cp "$ROOT_DIR/LICENSE" "$BUILD_DIR/client/"

# ──────────────────────────────────────────────
#  Server
# ──────────────────────────────────────────────

echo "Copying the server part..."
cp -R "$SERVER_DIR/." "$BUILD_DIR/server/"
find "$BUILD_DIR/server" -name ".DS_Store" -delete
cp "$ROOT_DIR/LICENSE" "$BUILD_DIR/server/"

# ──────────────────────────────────────────────
#  Version
# ──────────────────────────────────────────────

echo "Injecting version ${VERSION}..."
while IFS= read -r -d '' f; do
    sed -i.bak "s|${VERSION_TOKEN}|${VERSION}|g" "$f"
    rm -f "$f.bak"
done < <(find "$BUILD_DIR/client" "$BUILD_DIR/server" -type f \( -name "*.js" -o -name "*.php" \) -print0)

# ──────────────────────────────────────────────
#  Zip in the FrameTrail tree's layout
# ──────────────────────────────────────────────

ZIP="$BUILD_DIR/$BUNDLE-$VERSION.zip"
STAGE="$BUILD_DIR/.package"

echo "Packaging $(basename "$ZIP")..."
mkdir -p "$STAGE/extensions" "$STAGE/_server/extensions"
cp -R "$BUILD_DIR/client" "$STAGE/extensions/$NAME"
cp -R "$BUILD_DIR/server" "$STAGE/_server/extensions/$NAME"
(cd "$STAGE" && zip -q -r -X "$ZIP" extensions _server)
rm -rf "$STAGE"

# ──────────────────────────────────────────────
#  Summary
# ──────────────────────────────────────────────

echo ""
echo "Build complete!"
echo ""
echo "  client/$BUNDLE.js   $(wc -c < "$BUILD_DIR/client/$BUNDLE.js" | tr -d ' ') bytes"
echo "  client/$BUNDLE.css  $(wc -c < "$BUILD_DIR/client/$BUNDLE.css" | tr -d ' ') bytes"
echo "  server/             $(find "$BUILD_DIR/server" -type f | wc -l | tr -d ' ') files"
echo "  $(basename "$ZIP")  $(wc -c < "$ZIP" | tr -d ' ') bytes"
