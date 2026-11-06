#!/bin/bash
# build.sh [version] - build packages/<name>-<version>-x86_64-1.txz on a workstation (never on a server).
# Run from a plugin repository as kit/bin/build.sh, or through the repository's own build.sh.
# Reads plugin.conf. The version defaults to the .plg's &version; and must match it. The .plg's &md5; entity is
# updated from the built package and CHANGELOG.md is rebuilt from the .plg's <CHANGES>.
# Directory modes are forced (the build umask is not trusted) and the archive root is verified: a Slackware package
# extracts ./ over /, so a group-writable root entry would break the target system.
set -euo pipefail
KIT=$(cd "$(dirname "$0")/.." && pwd)
HERE=${PLUGIN_ROOT:-$(cd "$KIT/.." && pwd)}
# shellcheck disable=SC1091  # plugin.conf lives in the plugin repository
. "$HERE/plugin.conf"
: "${NAME:?plugin.conf: NAME}" "${TITLE:?plugin.conf: TITLE}" "${SUMMARY:?plugin.conf: SUMMARY}" "${PARENT:?plugin.conf: PARENT}"
: "${ABOUT_ORDER:?plugin.conf: ABOUT_ORDER}" "${REPO:?plugin.conf: REPO}"
EXEC=${EXEC:-}; SUPPORT=${SUPPORT:-$REPO/issues}; MENU_EXTRA=${MENU_EXTRA:-}
AUTHOR=${AUTHOR:-$TITLE project}
MORE=${MORE:-https://github.com/search?q=owner%3Aluuvasara+unraid&type=repositories}
MORE_LABEL=${MORE_LABEL:-our Unraid plugins on GitHub}
PLG=$HERE/$NAME.plg
PLGVER=$(sed -n 's/^ *<!ENTITY version *"\([^"]*\)">.*/\1/p' "$PLG")
VER=${1:-$PLGVER}
[ "$VER" = "$PLGVER" ] || { echo "FATAL: version $VER != $NAME.plg &version; $PLGVER (bump the .plg first)"; exit 1; }
[[ $VER =~ ^[0-9]{4}\.[0-9]{2}\.[0-9]{2}[a-z]?$ ]] || { echo "FATAL: version $VER is not YYYY.MM.DD[a-z]"; exit 1; }
OUT=$HERE/packages; mkdir -p "$OUT"
PKG=$OUT/$NAME-$VER-x86_64-1.txz
P=usr/local/emhttp/plugins/$NAME
ST=$(mktemp -d "$HERE/.build.XXXXXX"); trap 'rm -rf "$ST"' EXIT
chmod 755 "$ST"
# only what git tracks under src/: a merge or rename can leave empty or untracked directories in the work tree
UNTRACKED=$(git -C "$HERE" ls-files --others --exclude-standard -- src)
[ -z "$UNTRACKED" ] || { echo "FATAL: untracked files under src/ (git add or remove them):"; echo "$UNTRACKED"; exit 1; }
(cd "$HERE/src" && git ls-files -z --cached -- . | xargs -0 cp -a --parents -t "$ST/")
find "$ST" -type f \( -name '*.pyc' -o -name '__pycache__' \) -delete
# the shared runtime, by copy: nothing on a server depends on another plugin or on this kit
mkdir -p "$ST/$P/kit"
cp "$KIT/runtime/about.js" "$KIT/runtime/about.css" "$KIT/runtime/changes.php" "$KIT/runtime/install-notes.sh" "$ST/$P/kit/"
"$KIT/bin/changes-from-plg.sh" "$PLG" >"$ST/$P/kit/changes.txt"
# the licence travels with the package; a plugin without its own copy has the kit's, the same text
LIC=$HERE/LICENSE; [ -f "$LIC" ] || LIC=$KIT/LICENSE
cp "$LIC" "$ST/$P/LICENSE"
TOPVER=$(sed -n '1s/^###//p' "$ST/$P/kit/changes.txt")
[ "$TOPVER" = "$VER" ] || { echo "FATAL: <CHANGES> starts with $TOPVER, not $VER (add this version's changes first)"; exit 1; }
SUBST=(-e "s|@@NAME@@|$NAME|g" -e "s|@@TITLE@@|$TITLE|g" -e "s|@@SUMMARY@@|$SUMMARY|g" -e "s|@@PARENT@@|$PARENT|g"
       -e "s|@@MENU@@|$PARENT:$ABOUT_ORDER${MENU_EXTRA:+ $MENU_EXTRA}|g" -e "s|@@REPO@@|$REPO|g" -e "s|@@SUPPORT@@|$SUPPORT|g" -e "s|@@AUTHOR@@|$AUTHOR|g" -e "s|@@MORE@@|${MORE//&/\\&}|g" -e "s|@@MORE_LABEL@@|$MORE_LABEL|g")
sed "${SUBST[@]}" "$KIT/runtime/about.page.in" >"$ST/$P/${PARENT}About.page"
sed -i "s|@@TITLE@@|$TITLE|g" "$ST/$P/kit/install-notes.sh"
find "$ST" -type d -exec chmod 755 {} +
find "$ST" -type f -exec chmod 644 {} +
chmod 755 "$ST/$P/kit/install-notes.sh"
for f in $EXEC; do
  [ -f "$ST/$f" ] || { echo "FATAL: plugin.conf EXEC names a missing file: $f"; exit 1; }
  chmod 755 "$ST/$f"
  case $(sed -n 1p "$ST/$f") in *bash*|*/sh*) bash -n "$ST/$f" ;; *php*) php -l "$ST/$f" >/dev/null ;; esac
done
while IFS= read -r -d '' f; do
  LINT=$(php -d short_open_tag=1 -l "$f" 2>&1) || { echo "FATAL: php -l failed: $LINT"; exit 1; }
done < <(find "$ST/$P" \( -name '*.page' -o -name '*.php' \) -print0)
# packages/ holds the current build and the one before it: right after a release, raw.githubusercontent.com can serve
# the previous .plg for some minutes, and that .plg must still find its package. Older ones are removed.
KEEP=$(for f in "$OUT/$NAME"-*-x86_64-1.txz; do
         [ -e "$f" ] && [ "$f" != "$PKG" ] || continue
         v=${f#"$OUT/$NAME"-}; printf '%s\t%s\n' "${v%-x86_64-1.txz}" "$f"
       done | sort -V -k1,1 | tail -n 1 | cut -f2)
for f in "$OUT/$NAME"-*-x86_64-1.txz; do
  [ -e "$f" ] || continue
  [ "$f" = "$KEEP" ] || rm -f "$f" "$f.md5"
done
tar -C "$ST" --owner=root --group=root --numeric-owner --sort=name --mtime="$(tr . - <<<"${VER:0:10}") 00:00:00 UTC" -cJf "$PKG" .
ROOT=$(tar -tJvf "$PKG" | sed -n 1p)   # sed reads all input: head -1 + pipefail = SIGPIPE
echo "archive root entry: $ROOT"
case "$(awk '{print $1}' <<<"$ROOT")" in drwxr-xr-x) ;; *) echo "FATAL: archive root is not drwxr-xr-x"; rm -f "$PKG"; exit 1 ;; esac
tar -tJvf "$PKG" | awk '$1 ~ /^d/ && $1 != "drwxr-xr-x" {bad=1; print "FATAL dir mode: " $0} END{exit bad}'
tar -tJvf "$PKG" | awk '$2 != "0/0" {bad=1; print "FATAL owner: " $0} END{exit bad}'
(cd "$OUT" && md5sum "$(basename "$PKG")" >"$(basename "$PKG").md5")
MD5=$(cut -d' ' -f1 "$PKG.md5")
sed -i "s|^\( *<!ENTITY md5 *\)\"[^\"]*\">|\1\"$MD5\">|" "$PLG"
grep -q "<!ENTITY md5 *\"$MD5\">" "$PLG" || { echo "FATAL: could not write md5 into $PLG"; exit 1; }
php -r '$d=new DOMDocument(); exit(@$d->load($argv[1], LIBXML_NOENT) ? 0 : 1);' "$PLG" || { echo "FATAL: $PLG is not well-formed XML"; exit 1; }
{ echo "# Changelog"; echo
  echo "Copied by the build from the <CHANGES> block of $NAME.plg; edit the .plg, not this file."; echo
  "$KIT/bin/changes-from-plg.sh" "$PLG" | sed -e 's/^###/## /'
} >"$HERE/CHANGELOG.md"
echo "built $PKG"; cat "$PKG.md5"
