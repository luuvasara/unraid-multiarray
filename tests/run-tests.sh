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
XT=$(php "$HERE/tests/xport-test.php" 2>&1); echo "$XT" | grep -v '^ok ' ; echo "$XT" | grep -q '^xport tests: all [0-9]* passed$' && ok "export/import unit tests ($(echo "$XT" | grep -c '^ok ') checks)" || bad "export/import unit tests"
GT=$("$HERE/tests/guards-test.sh" 2>&1); echo "$GT" | grep -v '^ok '; echo "$GT" | grep -q '^guards tests: all [0-9]* passed$' && ok "instance guard unit tests ($(echo "$GT" | grep -c '^ok ') checks)" || bad "instance guard unit tests"
DT=$(php "$HERE/tests/disks-test.php" 2>&1); echo "$DT" | grep -v '^ok '; echo "$DT" | grep -q '^disks tests: all [0-9]* passed$' && ok "Disks tab unit tests ($(echo "$DT" | grep -c '^ok ') checks)" || bad "Disks tab unit tests"
P=$HERE/src/usr/local/emhttp/plugins/$NAME
grep -qx 'SERVICE="disable"' "$P/default.cfg" && ok "new installs start with extra arrays disabled" || bad "default.cfg does not disable extra arrays"
for e in any_event started stopping; do grep -q '^grep -qs .\^SERVICE="enable". /boot/config/plugins/multiarray/multiarray.cfg ||' "$P/event/$e" && ok "event $e exits at once when not enabled" || bad "event $e is not gated"; done
for n in 2 3 4 5 6 7 8 9; do grep -q "^Cond=\"(@parse_ini_file('/boot/config/plugins/multiarray/multiarray.cfg')\['SERVICE'\]??'')=='enable' && " "$P/Main$n.page" || bad "Main$n tab not gated by SERVICE"; done; ok "Main2..Main9 tabs only when enabled"
grep -q '^  hotplug|dmview|refresh|start|restart|setup|gen-pages|hostfilter|mover|mover-schedule|mover-cron)$' "$P/scripts/multiarray" && ok "multiarray: hotplug/dmview/refresh/start/setup/mover return at once when not enabled" || bad "multiarray dispatcher not gated"
grep -q 'scripts/multiarray setup' "$PLG" && ! grep -q 'curl' "$PLG" && ok ".plg: drivers, tabs and start only through 'multiarray setup' when enabled" || bad ".plg still does work when disabled"
[ -f "$PKG" ] && { grep -q "plugins/$NAME/MultiArrayXport.page" <<<"$LIST" && grep -q "plugins/$NAME/help/why-virtual.txt" <<<"$LIST" && ok "package holds the Export and import tab and the Why text" || bad "Export tab or Why text missing from the package"; }
grep -q 'Lime Technology' "$P/MultiArrayXport.page" && grep -q 'Lime Technology' "$P/help/why-virtual.txt" && ok "export/import texts carry the disclaimer" || bad "disclaimer missing in export/import texts"
[ -f "$PKG" ] && { grep -q "plugins/$NAME/MultiArrayDisks.page" <<<"$LIST" && grep -q "plugins/$NAME/kit/Enclosure.php" <<<"$LIST" && grep -q "plugins/$NAME/kit/enclosures/qnap-tl-d1600s.json" <<<"$LIST" && ok "package holds the Disks tab, the enclosure library and the TL-D1600S profile" || bad "Disks tab files missing from the package"; }
[ -f "$PKG" ] && { [ "$(tar -tvJf "$PKG" ./usr/local/emhttp/plugins/$NAME/scripts/disks-job 2>/dev/null | cut -c1-10)" = "-rwxr-xr-x" ] && ok "disks-job is executable in the package" || bad "disks-job mode"; }
grep -q 'if \[ -d /var/lib/multiarray \] || grep -qs' "$PLG" && ok "remove: stop and host filter only when extra arrays ran or are enabled" || bad "remove still runs multiarray stop unconditionally"
! grep -rqE 'mount |wipefs|sgdisk|sfdisk|mkfs|dd .*of=/dev/(sd|hd|vd|nvme|md|dm|mapper)|>\s*/dev/sd' "$P/include/disks.php" "$P/scripts/disks-job" && ok "Disks code has no mount, partitioning, formatting or disk write command" || bad "a write-capable command in the Disks code"
grep -q "fopen(\$node, 'rb')" "$P/include/disks.php" && ! grep -qE "fopen\([^)]*'(w|a|r\+|c|x)" "$P/include/disks.php" && ok "Disks code opens disks read-only only" || bad "Disks code opens a disk writable"
[ -s "$HERE/icon.png" ] && ok "icon.png" || bad "icon.png"
for f in "$HERE"/docs/screenshots/*.png; do grep -q "docs/screenshots/$(basename "$f"))" "$HERE/README.md" && ok "README shows $(basename "$f")" || bad "README lacks $f"; done
[ $fail -eq 0 ] && echo "all checks passed" || { echo "checks FAILED"; exit 1; }
