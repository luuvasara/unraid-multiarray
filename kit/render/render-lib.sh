#!/bin/bash
# render-lib.sh - sourced by a plugin's tests/render-test.sh. Needs: HERE (plugin repo), NAME, tests/.work.
# stage_web <webroot>: the plugin as the package would lay it out, with the kit runtime and generated About page.
# free_port; start_harness <webroot> <port> <theme> <tabs> [6.12]; shot <url> <png> ; dom <url> <file>. Standalone headless Chrome.
CHROME=${CHROME:-google-chrome}
PIDS=()
# shellcheck disable=SC2329
render_cleanup() { for p in "${PIDS[@]}"; do kill "$p" 2>/dev/null; done; }
trap render_cleanup EXIT
stage_web() {   # stage_web <webroot> [6.12]: unpack the built package's plugin dir
  local web=$1 gui=${2:-} pkg
  # the .plg's version, not a sort: sort -V puts 2026.11.05 after 2026.11.05a
  pkg=$HERE/packages/$NAME-$(sed -n 's/^ *<!ENTITY version *"\([^"]*\)">.*/\1/p' "$HERE/$NAME.plg")-x86_64-1.txz
  [ -f "$pkg" ] || { echo "FAIL no package: build first"; return 1; }
  rm -rf "$web"; mkdir -p "$web/plugins" "$web/pkg" "$web/webGui/javascript"
  tar -xJf "$pkg" -C "$web/pkg"
  cp -a "$web/pkg/usr/local/emhttp/plugins/$NAME" "$web/plugins/"
  local root=$HERE/tests/.work/webgui; [ "$gui" = 6.12 ] && root=$HERE/tests/.work/webgui-6.12
  cp -a "$root/webGui/styles" "$web/webGui/"
  cp "$root/webGui/javascript/jquery.js" "$web/webGui/javascript/jquery.js"
}
free_port() {   # a port nothing listens on: another harness on this machine must never answer for this one
  local p=$((20000 + RANDOM % 20000))
  while (exec 3<>"/dev/tcp/127.0.0.1/$p") 2>/dev/null; do p=$((p + 1)); done
  echo "$p"
}
start_harness() {  # start_harness <webroot> <port> <theme> <tabs> [6.12]
  local web=$1 port=$2 theme=$3 tabs=$4 gui=${5:-}
  env THEME="$theme" WEBROOT="$web" PLUGIN="$NAME" TABS="$tabs" WEBGUI="$gui" php -d short_open_tag=1 -S "127.0.0.1:$port" -t "$web" "$HERE/kit/render/router.php" >"$web.log" 2>&1 &
  PIDS+=($!)
  for _ in $(seq 1 50); do curl -s -o /dev/null "http://127.0.0.1:$port/x" && break; sleep 0.1; done
}
shot() { "$CHROME" --headless=new --no-sandbox --disable-gpu --hide-scrollbars --window-size=1300,900 --virtual-time-budget=3000 --screenshot="$2" "$1" >/dev/null 2>&1; }
dom() { "$CHROME" --headless=new --no-sandbox --disable-gpu --virtual-time-budget=3000 --dump-dom "$1" 2>/dev/null >"$2"; }
