#!/usr/bin/env bash
# Prüft die Plugin-Version (lokal und in CI, Job "version").
#
#   bin/check-version.sh              Header "Version:" in wc-plz-filter.php == WC_PLZ_Filter::VERSION
#   bin/check-version.sh origin/main  zusätzlich: Version muss echt größer sein als auf origin/main
#
# Für den Vergleich vorher holen: git fetch origin main
set -euo pipefail

cd "$(dirname "$0")/.."

MAIN_FILE='wc-plz-filter.php'
CLASS_FILE='includes/class-wc-plz-filter.php'
compare_ref="${1:-}"

# Gleiche Regel wie WordPress' get_file_data(): erste 8 KB, Zeile "* Version: x.y.z".
read -r -d '' PHP_HEADER <<'PHP' || true
$d = substr( stream_get_contents( STDIN ), 0, 8192 );
if ( preg_match( '/^(?:[ \t]*<\?php)?[ \t\/*#@]*Version:(.*)$/mi', $d, $m ) ) {
    echo trim( preg_replace( '/\s*(?:\*\/|\?>).*/', '', $m[1] ) );
}
PHP

read -r -d '' PHP_CONST <<'PHP' || true
if ( preg_match( '/\bconst\s+VERSION\s*=\s*[\'"]([^\'"]+)[\'"]/', stream_get_contents( STDIN ), $m ) ) {
    echo $m[1];
}
PHP

header_version="$(php -r "$PHP_HEADER" < "$MAIN_FILE")"
const_version="$(php -r "$PHP_CONST" < "$CLASS_FILE")"

if [[ -z "$header_version" ]]; then
    echo "::error file=$MAIN_FILE::Kein 'Version:'-Header gefunden." >&2
    exit 1
fi
if [[ -z "$const_version" ]]; then
    echo "::error file=$CLASS_FILE::Keine 'const VERSION' gefunden." >&2
    exit 1
fi
if [[ "$header_version" != "$const_version" ]]; then
    echo "::error::Version uneinheitlich: $MAIN_FILE Header = $header_version, $CLASS_FILE VERSION = $const_version" >&2
    exit 1
fi
echo "OK: Version $header_version (Header == WC_PLZ_Filter::VERSION)"

if [[ -z "$compare_ref" ]]; then
    exit 0
fi

base_version="$(git show "$compare_ref:$MAIN_FILE" | php -r "$PHP_HEADER")"
if [[ -z "$base_version" ]]; then
    echo "::error::Konnte die Version auf $compare_ref nicht lesen." >&2
    exit 1
fi

if php -r 'exit( version_compare( $argv[1], $argv[2], ">" ) ? 0 : 1 );' -- "$header_version" "$base_version"; then
    echo "OK: $header_version > $base_version ($compare_ref)"
else
    echo "::error file=$MAIN_FILE::Version muss erhöht werden: $header_version ist nicht größer als $base_version auf $compare_ref" >&2
    exit 1
fi
