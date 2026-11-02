#!/bin/bash
# build/build.sh VERSION  -> packages/multiarray-VERSION-x86_64-1.txz + multiarray.plg
# Bump VERSION on every change (Unraid only upgrades to a different version string).
set -e
V=${1:?version, e.g. 2026.11.02}
D=$(dirname "$(readlink -f "$0")")/..
SRC=${SOURCE_URL:-https://raw.githubusercontent.com/luuvasara/unraid-multiarray/main}
ST=$(mktemp -d); trap 'rm -rf "$ST"' EXIT
cp -a "$D/src/." "$ST/"
chmod 755 "$ST"; find "$ST" -type d -exec chmod 755 {} +
find "$ST" -type f \( -name "*.page" -o -name "*.php" -o -name "*.cfg" -o -name "*.rules" \) -exec chmod 644 {} +
chmod 755 "$ST"/usr/local/emhttp/plugins/multiarray/{scripts,event}/* "$ST"/usr/local/emhttp/plugins/multiarray/seed/noop
mkdir -p "$D/packages"; rm -f "$D"/packages/multiarray-*.txz
(cd "$ST" && tar --owner=0 --group=0 --numeric-owner -cJf "$D/packages/multiarray-$V-x86_64-1.txz" .)
MD5=$(md5sum "$D/packages/multiarray-$V-x86_64-1.txz" | cut -d' ' -f1)
sed -e "s|@VERSION@|$V|g; s|@SOURCE@|$SRC|g; s|@MD5@|$MD5|g" "$D/build/multiarray.plg.in" > "$D/multiarray.plg"
echo "multiarray $V md5 $MD5"
