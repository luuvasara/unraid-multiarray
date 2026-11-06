#!/bin/bash
# lint.sh - plugin repository lint: shellcheck (image koalaman/shellcheck:stable, no network, repo read-only) on the
# plugin's shell scripts and the .plg's inline blocks; php -l (short_open_tag on, as on Unraid) on pages and PHP;
# node --check on page scripts; .plg well-formed; page headers parse.
set -uo pipefail
KIT=$(cd "$(dirname "$0")/.." && pwd)
HERE=${PLUGIN_ROOT:-$(cd "$KIT/.." && pwd)}
# shellcheck disable=SC1091
. "$HERE/plugin.conf"
P=$HERE/src/usr/local/emhttp/plugins/$NAME
W=$HERE/tests/.work/lint; rm -rf "$W"; mkdir -p "$W"
fail=0; ok() { echo "ok   $*"; }; bad() { echo "FAIL $*"; fail=1; }
n=0
while IFS= read -r -d '' blk; do
  n=$((n + 1)); printf '#!/bin/bash\n%s\n' "$blk" >"$W/plg-inline-$n.sh"
done < <(php -r '$d=new DOMDocument(); if(!$d->load($argv[1], LIBXML_NOENT)) exit(1); foreach ($d->getElementsByTagName("INLINE") as $i) echo $i->textContent, "\0";' "$HERE/$NAME.plg")
[ "$n" -ge 1 ] && ok ".plg parses with entities resolved, $n inline blocks" || bad ".plg inline blocks: $n"
mapfile -t SH < <(cd "$HERE" && git ls-files -- src tests build.sh | while read -r f; do
  [ -f "$f" ] && case $(sed -n 1p "$f") in '#!'*bash*|'#!'*/sh*) echo "$f" ;; esac; done)
if docker image inspect koalaman/shellcheck:stable >/dev/null 2>&1; then
  if out=$(cd "$HERE" && docker run --rm --network none -v "$HERE:/mnt:ro" -w /mnt koalaman/shellcheck:stable -x -S style "${SH[@]}" tests/.work/lint/plg-inline-*.sh 2>&1); then
    ok "shellcheck clean (style level): ${#SH[@]} scripts + $n .plg blocks"
  else bad "shellcheck:"; echo "$out"; fi
else echo "skip shellcheck (image koalaman/shellcheck:stable not present locally)"; fi
while IFS= read -r -d '' f; do
  case $f in *.page) php -r '[$h] = explode("\n---\n", file_get_contents($argv[1]), 2); exit(parse_ini_string($h) === false ? 1 : 0);' "$f" && ok "page header: ${f##*/}" || bad "page header $f" ;; esac
  out=$(php -d short_open_tag=1 -l "$f" 2>&1) && ok "php -l ${f#"$HERE"/}" || bad "php -l $f: $out"
done < <(find "$HERE/src" "$HERE/tests" -path '*/.work' -prune -o \( -name '*.php' -o -name '*.page' \) -print0)
while IFS= read -r f; do
  case $(sed -n 1p "$f") in '#!'*php*) out=$(php -l "$f" 2>&1) && ok "php -l ${f#"$HERE"/}" || bad "php -l $f: $out" ;; esac
done < <(find "$HERE/src" -type f ! -name '*.js' ! -name '*.css' ! -name '*.json' ! -name '*.png' ! -name '*.page' ! -name '*.php')
while IFS= read -r -d '' f; do node --check "$f" && ok "node --check ${f#"$HERE"/}" || bad "node --check $f"; done < <(find "$HERE/src" -name '*.js' -print0)
exit $fail
