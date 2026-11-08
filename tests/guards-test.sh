#!/bin/bash
# guards-test.sh - unit checks of the instance guards that need no server: zfs-scope (pools scoped to one instance)
# and the pool names an array asks for. Real zpool/zfs are stubs that record their calls.
set -u
HERE=$(cd "$(dirname "$0")/.." && pwd); P=$HERE/src/usr/local/emhttp/plugins/multiarray
T=$(mktemp -d); trap 'rm -rf "$T"' EXIT
n=0; f=0; ok() { n=$((n+1)); echo "ok $*"; }; bad() { f=$((f+1)); echo "FAIL $*"; }
export MA_TEST_PLUG=$P MA_TEST_ROOT=$T/run MA_TEST_LOG=$T/log
mkdir -p $T/run/a4 $T/run/real $T/bin
# stubs: zpool list prints the imported pools from $T/imported; everything is logged to $T/calls
cat > $T/run/real/zpool <<'S'
#!/bin/bash
echo "zpool $*" >> $MA_TEST_ROOT/../calls
case $1 in list) cat $MA_TEST_ROOT/../imported ;; import) [ $# -gt 1 ] && echo "${@: -1}" >> $MA_TEST_ROOT/../imported ;; esac; exit 0
S
cat > $T/run/real/zfs <<'S'
#!/bin/bash
echo "zfs $*" >> $MA_TEST_ROOT/../calls
[ "$1" = list ] && case ${@: -1} in disk1) printf 'disk1\ton\t/mnt/disk1\ndisk1/media\ton\t/mnt/disk1/media\ndisk1/off\tnoauto\t/mnt/disk1/off\n' ;; esac; exit 0
S
chmod +x $T/run/real/*
{ echo '#!/bin/bash'; echo N=4; echo "PLUG=$P"; tail -n +2 $P/scripts/zfs-scope; } > $T/bin/zfs-scope; chmod +x $T/bin/zfs-scope
ln -s zfs-scope $T/bin/zpool; ln -s zfs-scope $T/bin/zfs
run() { : > $T/calls; "$T/bin/$@" 2>$T/err; }
called() { grep -qx -- "$1" $T/calls; }
printf 'hpool\ndisk1x\n' > $T/imported; : > $T/run/a4/zpools
run zpool import -f -m -N -d /dev/mdd1p1 hpool && bad "import of a name already imported passes" || { ! called "zpool import -f -m -N -d /dev/mdd1p1 hpool" && grep -q "already imported" $T/err && ok "import of a pool name that is already imported is refused, real zpool not run"; }
run zpool import -f -m -N -d /dev/mdd1p1 disk1 && called "zpool import -f -m -N -d /dev/mdd1p1 disk1" && grep -qx disk1 $T/run/a4/zpools && ok "import of a free name runs and records the pool as the instance's" || bad "import of a free name"
run zpool import -d /dev/mdd1p1 && called "zpool import -d /dev/mdd1p1" && ok "import without a name (a search) passes" || bad "import search"
run zpool create -f -o ashift=12 -m /mnt/hpool hpool /dev/mdd2p1 && bad "create of an imported name passes" || ok "create of an imported name is refused"
run zpool export -f hpool && bad "export of another pool passes" || { ! called "zpool export -f hpool" && ok "export of a pool that is not the instance's is refused"; }
run zpool destroy hpool && bad "destroy of another pool passes" || ok "destroy of a pool that is not the instance's is refused"
run zpool status -P hpool && called "zpool status -P hpool" && ok "read-only zpool commands pass" || bad "zpool status"
run zpool set autotrim=on hpool && bad "zpool set on another pool passes" || ok "zpool set on another pool is refused"
run zpool set autotrim=on disk1 && called "zpool set autotrim=on disk1" && ok "zpool set on an own pool passes" || bad "zpool set own"
run zfs mount -a; called "zfs mount disk1" && called "zfs mount disk1/media" && ! called "zfs mount disk1/off" && ! grep -q hpool $T/calls && ok "zfs mount -a mounts only the instance's datasets with canmount=on" || bad "zfs mount -a scope: $(tr '\n' ';' < $T/calls)"
run zfs create hpool/share && bad "zfs create in another pool passes" || ok "zfs create in another pool is refused"
run zfs create disk1/share && called "zfs create disk1/share" && ok "zfs create in an own pool passes" || bad "zfs create own"
run zfs set -u mountpoint=/mnt/hpool hpool && bad "zfs set on another pool passes" || ok "zfs set on another pool is refused"
run zfs set -u mountpoint=/mnt/disk1 disk1 && ok "zfs set on an own pool passes" || bad "zfs set own"
run zfs destroy 'hpool/x' && bad "zfs destroy in another pool passes" || ok "zfs destroy in another pool is refused"
run zfs get -H -o value compression hpool && ok "zfs get passes" || bad "zfs get"
run zpool export -f disk1 && ! grep -qx disk1 $T/run/a4/zpools && ok "export of an own pool runs and forgets it" || bad "export own"
# pool names an array asks for
printf '["parity"]\ntype="Parity"\nfsType=""\n["disk1"]\nfsType="zfs"\ntype="Data"\n["disk2"]\ntype="Data"\nfsType="luks:zfs"\n["disk3"]\ntype="Data"\nfsType="xfs"\n["cache"]\ntype="Cache"\nfsType="zfs"\n["cache2"]\ntype="Cache"\nfsType="zfs"\n["fast"]\ntype="Cache"\nfsType="btrfs"\n' > $T/disks.ini
got=$(bash -c ". $P/scripts/lib.sh 2>/dev/null; zfs_pool_names $T/disks.ini" | tr '\n' ' ')
[ "$got" = "cache disk1 disk2 " ] && ok "zfs pool names of an array: slot names for array disks, the pool name for pool members" || bad "zfs_pool_names gave '$got'"
# udev rule covers partitions; md_is_stopped unchanged
! grep -q 'DEVTYPE' $HERE/src/etc/udev/rules.d/99-multiarray.rules && ok "udev rule refreshes on partitions too" || bad "udev rule matches whole disks only"
[ $f -eq 0 ] && echo "guards tests: all $n passed" || { echo "guards tests: $f failed"; exit 1; }
