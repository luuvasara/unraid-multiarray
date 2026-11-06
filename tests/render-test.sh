#!/bin/bash
# shellcheck disable=SC2015
# The About tab of the built package with Unraid's real stylesheets in headless Chrome, all four themes. The settings
# page needs a running Unraid (disk lists, instance state) and is not rendered here. OUT=<dir> keeps the screenshots.
set -uo pipefail
HERE=$(cd "$(dirname "$0")/.." && pwd); NAME=multiarray
OUT=${OUT:-$HERE/tests/.work/shots}; mkdir -p "$OUT"
# shellcheck disable=SC1091
. "$HERE/kit/render/render-lib.sh"
PLUGIN_ROOT=$HERE "$HERE/kit/render/fetch-webgui.sh"
W=$HERE/tests/.work/render; rm -rf "$W"; mkdir -p "$W"
fail=0; ok() { echo "ok   $*"; }; bad() { echo "FAIL $*"; fail=1; }
VER=$(sed -n 's/^ *<!ENTITY version *"\([^"]*\)">.*/\1/p' "$HERE/$NAME.plg")
for theme in black white gray azure; do
  web=$W/web-$theme; stage_web "$web" || exit 1
  port=$(free_port); start_harness "$web" "$port" "$theme" "About=MultiArrayAbout"
  shot "http://127.0.0.1:$port/?tab=1" "$OUT/$NAME-about-$theme.png"
  [ -s "$OUT/$NAME-about-$theme.png" ] && ok "$theme: About rendered" || bad "no screenshot $theme"
  dom "http://127.0.0.1:$port/?tab=1" "$W/dom-$theme.html"
  grep -q 'kit-about' "$W/dom-$theme.html" && grep -q "$VER" "$W/dom-$theme.html" && ok "$theme: About shows the version" || bad "$theme: About version"
  grep -q 'Multi Arrays is by the Multi Arrays project' "$W/dom-$theme.html" && grep -q 'General Public License' "$W/dom-$theme.html" && ok "$theme: About names project, licence and source" || bad "$theme: About attribution"
  grep -q 'New About tab' "$W/dom-$theme.html" && ok "$theme: About lists the latest changes" || bad "$theme: About changes"
  grep -q 'Proof of concept' "$W/dom-$theme.html" && ok "$theme: About keeps the proof-of-concept line" || bad "$theme: About summary"
  if grep -E "PHP (Warning|Fatal|Parse)" "$web.log" | head -5; then bad "$theme: PHP errors"; fi
done
render_cleanup
[ $fail = 0 ] && echo "ALL PASSED" || echo FAILED
exit $fail
