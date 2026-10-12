#!/usr/bin/env bash
# Builds dist/product-sheet-ai-for-woocommerce-<version>.zip with shipping files only.
set -euo pipefail

cd "$(dirname "$0")/.."
SLUG="product-sheet-ai-for-woocommerce"
VERSION="$(sed -n 's/^ \* Version:[[:space:]]*//p' "$SLUG.php" | head -n 1 | tr -d '[:space:]')"
[ -n "$VERSION" ] || { echo "Cannot read version from $SLUG.php" >&2; exit 1; }

STAGE="$(mktemp -d)"
trap 'rm -rf "$STAGE"' EXIT
mkdir -p "$STAGE/$SLUG" dist
DEST="$STAGE/$SLUG"

rsync -a --exclude-from=.distignore ./ "$DEST/"

# Runtime libraries only (includes/Sources/PdfLoader.php loads these paths).
copy_lib() {
	local src="$1"
	shift
	local item
	for item in "$@"; do
		[ -e "vendor/$src/$item" ] || { echo "Missing vendor/$src/$item" >&2; exit 1; }
		mkdir -p "$DEST/vendor/$src/$(dirname "$item")"
		cp -R "vendor/$src/$item" "$DEST/vendor/$src/$item"
	done
}
copy_lib smalot/pdfparser src LICENSE.txt composer.json
copy_lib symfony/polyfill-mbstring bootstrap.php bootstrap72.php bootstrap80.php Mbstring.php Resources LICENSE composer.json
find "$DEST/vendor" \( -name '.DS_Store' -o -name '*.md' \) -delete

# Every file the loader expects must be in the stage.
for expected in \
	vendor/symfony/polyfill-mbstring/bootstrap.php \
	vendor/symfony/polyfill-mbstring/bootstrap72.php \
	vendor/symfony/polyfill-mbstring/bootstrap80.php \
	vendor/symfony/polyfill-mbstring/Mbstring.php \
	vendor/smalot/pdfparser/src/Smalot/PdfParser/Parser.php \
	vendor/smalot/pdfparser/LICENSE.txt \
	vendor/symfony/polyfill-mbstring/LICENSE; do
	[ -f "$DEST/$expected" ] || { echo "Missing in package: $expected" >&2; exit 1; }
done

fail=0
while IFS= read -r -d '' f; do
	php -l "$f" > /dev/null || { echo "Syntax error: $f" >&2; fail=1; }
done < <(find "$DEST" -name '*.php' -print0)
[ "$fail" -eq 0 ] || exit 1
echo "php -l OK"

ZIP="$PWD/dist/$SLUG-$VERSION.zip"
rm -f "$ZIP"
( cd "$STAGE" && zip -qr "$ZIP" "$SLUG" )

echo "Built $ZIP ($(du -h "$ZIP" | cut -f1))"
unzip -l "$ZIP"
