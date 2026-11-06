#!/bin/bash
# install-notes.sh <version> fresh|boot|update [installed before] - the message of the .plg's post-install block.
# boot prints one line: the plugin manager reinstalls every plugin at each boot, and that goes to the syslog.
# fresh prints FIRST_RUN (env, optional). update prints what changed in a box of 80 columns, plain ASCII.
# changes.txt is the .plg's <CHANGES>; the About tab reads the same file.
ver=${1:-} mode=${2:-} old=${3:-}
NAME='@@TITLE@@'
HERE=$(cd "$(dirname "$0")" && pwd)
F=${KIT_CHANGES:-$HERE/changes.txt}
changes_box() {
  [ -f "$F" ] || return 0
  [[ $old =~ ^[0-9]{4}\.[0-9]{2}\.[0-9]{2}[a-z]?$ ]] || old=''
  sed -e 's/\xc2\xb7/-/g' -e 's/`//g' "$F" | LC_ALL=C tr -cd '\11\12\40-\176' | LC_ALL=C awk -v ver="$ver" -v old="$old" -v name="$NAME" '
    function line(t) { printf "| %-76s |\n", t }
    function rule() { print "+==============================================================================+" }
    function wrap(t, first, rest,    pre, cur, n, i, w, a) {   # one item over several lines, words kept whole if they fit
      pre = first; cur = ""; n = split(t, a, " ")
      for (i = 1; i <= n; i++) {
        w = a[i]
        while (length(pre) + length(w) > 76) {
          if (cur != "") { line(pre cur); pre = rest; cur = ""; continue }
          line(pre substr(w, 1, 76 - length(pre))); w = substr(w, 77 - length(pre)); pre = rest
        }
        if (cur != "" && length(pre) + length(cur) + 1 + length(w) > 76) { line(pre cur); pre = rest; cur = w }
        else cur = (cur == "" ? w : cur " " w)
      }
      if (cur != "") line(pre cur)
    }
    /^###/ {
      v = substr($0, 4); seen++
      # a release counts when it is newer than what was installed; with no such version, only the newest one
      take = (seen == 1) || (old != "" && (v "") > (old ""))
      if (take && shown >= 3) { more++; take = 0 }
      if (take) { shown++; item[++k] = "#" v }
      next
    }
    take && /^- / { item[++k] = substr($0, 3) }
    END {
      if (!k) exit
      print ""; rule(); line("What changed in " name " " ver); rule()
      for (i = 1; i <= k; i++) {
        if (item[i] ~ /^#/) { if (shown > 1) { if (i > 1) line(""); line(substr(item[i], 2) ":") } continue }
        wrap(item[i], "- ", "  ")
      }
      if (more) { line(""); line("... and " more " earlier release(s): see the About tab.") }
      rule(); print ""
    }'
}
case $mode in
  boot) echo "$NAME $ver installed."; exit 0 ;;
  fresh) echo ""; echo "$NAME $ver installed."; [ -n "${FIRST_RUN:-}" ] && echo "$FIRST_RUN"; changes_box ;;
  update) echo ""
    if [ "$old" = "$ver" ]; then echo "$NAME $ver reinstalled."; else echo "$NAME updated from $old to $ver."; fi
    changes_box ;;
  *) echo "$NAME $ver installed." ;;
esac
exit 0
