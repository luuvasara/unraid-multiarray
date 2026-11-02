#!/bin/bash
# build/build-ko.sh KERNEL_TREE MD_SOURCE_DIR N [N...]  -> ko/<kernelrelease>/md_unraidN.ko
#   KERNEL_TREE   : prepared Unraid kernel tree (same version + config as the target server)
#   MD_SOURCE_DIR : Unraid's md driver sources (md_unraid.c, md_unraid.h, unraid.c)
#   N             : extra array number, 2..9
# The driver copy for array N is Unraid's md driver with driver/md_unraid2.patch applied and every
# instance name numbered N: module md_unraidN, /proc/mdcmdN + /proc/mdstatN, its own block major,
# disk devices md<letter><slot>p1 (b for 2, c for 3, ...).
set -e
KDIR=$(readlink -f "${1:?kernel tree}"); MDS=$(readlink -f "${2:?md source dir}"); shift 2
D=$(dirname "$(readlink -f "$0")")/..
KVER=$(make -s -C "$KDIR" kernelrelease)
OUTD=$D/ko/$KVER; mkdir -p "$OUTD"
W0=$(mktemp -d); trap 'rm -rf "$W0"' EXIT
mkdir -p "$W0/base/drivers/md"; cp "$MDS"/{md_unraid.c,md_unraid.h,unraid.c} "$W0/base/drivers/md/"
(cd "$W0/base" && patch -p1 -s < "$D/driver/md_unraid2.patch")
for N in "$@"; do
  L=$(printf "\\x$(printf %x $((96 + N)))")
  W=$W0/n$N; mkdir -p "$W"
  for f in md_unraid.c md_unraid.h unraid.c; do
    sed -e "s/md2/md$N/g; s/unraid2/unraid$N/g; s/mdcmd2/mdcmd$N/g; s/mdstat2/mdstat$N/g; s/super2\.dat/super$N.dat/g; s/mdb%d/md${L}%d/g" "$W0/base/drivers/md/$f" > "$W/$f"
  done
  printf 'obj-m += md_unraid%s.o\nmd_unraid%s-y := md_unraid.o unraid.o\n' "$N" "$N" > "$W/Kbuild"
  make -s -C "$KDIR" M="$W" KBUILD_MODPOST_WARN=1 modules
  cp "$W/md_unraid$N.ko" "$OUTD/"; echo "$OUTD/md_unraid$N.ko $(modinfo -F vermagic "$W/md_unraid$N.ko")"
done
