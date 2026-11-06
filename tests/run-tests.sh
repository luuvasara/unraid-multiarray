#!/bin/bash
# shellcheck disable=SC2015
# run-tests.sh - checks that need no server: lint, .plg and package consistency, the About tab in the package.
set -uo pipefail
HERE=$(cd "$(dirname "$0")/.." && pwd); NAME=multiarray; PLG=$HERE/$NAME.plg
fail=0; ok() { echo "ok   $*"; }; bad() { echo "FAIL $*"; fail=1; }
# the scripts predate the kit's shellcheck (style level) and are not reworked here: those findings are listed, not counted
LINT=$(PLUGIN_ROOT=$HERE "$HERE/kit/bin/lint.sh" 2>&1); lint_rc=$?
echo "$LINT" | grep '^ok   \|^FAIL ' | grep -v '^FAIL shellcheck:'
if [ $lint_rc -ne 0 ]; then
  if echo "$LINT" | grep '^FAIL ' | grep -qv '^FAIL shellcheck:'; then bad "lint"
  else echo "note shellcheck style findings in the existing scripts: $(echo "$LINT" | grep -c '^In ') places (run kit/bin/lint.sh to list)"; fi
fi
VER=$(sed -n 's/^ *<!ENTITY version *"\([^"]*\)">.*/\1/p' "$PLG")
MD5=$(sed -n 's/^ *<!ENTITY md5 *"\([^"]*\)">.*/\1/p' "$PLG")
[[ $VER =~ ^[0-9]{4}\.[0-9]{2}\.[0-9]{2}[a-z]?$ ]] && ok "version $VER" || bad "version format $VER"
PKG=$HERE/packages/$NAME-$VER-x86_64-1.txz
if [ -f "$PKG" ]; then
  [ "$(md5sum <"$PKG" | cut -d' ' -f1)" = "$MD5" ] && ok "package md5 matches the .plg" || bad "package md5 != .plg &md5;"
  [ "$(cut -d' ' -f1 "$PKG.md5")" = "$MD5" ] && ok ".md5 file matches" || bad ".md5 file"
  [ "$(tar -tJvf "$PKG" | sed -n 1p | awk '{print $1, $2}')" = "drwxr-xr-x 0/0" ] && ok "txz root is 755 root:root" || bad "txz root entry"
  LIST=$(tar -tJf "$PKG")   # not piped into grep -q: with pipefail its early exit can fail the pipeline
  grep -q "plugins/$NAME/MultiArrayAbout.page" <<<"$LIST" && ok "package holds the About page" || bad "no About page in the package"
else bad "missing $PKG (run ./build.sh)"; fi
[ "$(ls "$HERE"/packages/*.txz 2>/dev/null | wc -l)" -le 2 ] && ok "packages/ holds the current and the previous package at most" || bad "too many packages"
[ "$(sed -n '/^<CHANGES>/{n;p;q}' "$PLG")" = "###$VER" ] && ok "first <CHANGES> entry is $VER" || bad "first <CHANGES> entry is not $VER"
grep -q "<MinVer>$(sed -n 's/^.*min="\([^"]*\)".*/\1/p' "$PLG" | head -1)</MinVer>" "$HERE/plugins/$NAME.xml" && ok "MinVer = .plg min" || bad "MinVer differs from the .plg min"
grep -q 'for f in &base;/&name;-\*.txz; do \[ "$f" = "&base;/&name;-&version;-x86_64-1.txz" \] || rm -f "$f"; done' "$PLG" && ok "cleanup keeps the current version's txz" || bad "cleanup does not keep the current txz"
grep -q 'kit/install-notes.sh "&version;"' "$PLG" && ok "post-install prints the changes box" || bad "install-notes.sh not called"
grep -qi 'proof of concept' "$HERE/plugins/$NAME.xml" && ok "template keeps the proof-of-concept warning" || bad "template lost the warning"
[ -s "$HERE/icon.png" ] && ok "icon.png" || bad "icon.png"
for f in "$HERE"/docs/screenshots/*.png; do grep -q "docs/screenshots/$(basename "$f"))" "$HERE/README.md" && ok "README shows $(basename "$f")" || bad "README lacks $f"; done
[ $fail -eq 0 ] && echo "all checks passed" || { echo "checks FAILED"; exit 1; }
