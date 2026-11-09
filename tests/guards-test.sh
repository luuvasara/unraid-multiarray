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
# instance GUI on loopback only: apply prepare()'s rc.nginx edit to a stub with the stock shape
printf '#!/bin/bash\nlisten(){\n  echo "listen $*;"\n}\n\n# create redirect server blocks\nPORT=8081 PORTSSL=9083\necho "$(listen lo)"; echo "$(listen $PORT default_server)"\n' > $T/rc.nginx
cmd=$(grep -o "sed -i '/^# create redirect server blocks\$/i # multiarray: loopback only[^']*' " $P/scripts/multiarray)
eval "${cmd% } $T/rc.nginx"; out=$(bash $T/rc.nginx | tr -s ' ' | tr '\n' '|')
[ "$out" = " listen 127.0.0.1:8081; # lo| listen 127.0.0.1:9083; # lo| listen 127.0.0.1:8081 default_server;|" ] && ok "instance nginx listens on 127.0.0.1 only" || bad "instance nginx listen: '$out'"
grep -q "multiarray guard" $P/include/toggle.php && grep -q "multiarray guard" $P/include/update.php && ok "toggle.php and update.php run the guard" || bad "an endpoint without the guard"
# MainN: no Browse icon may open the host's /mnt/diskN or /mnt/<pool> (stock lines of Unraid 7.3.4 nchan/device_list)
mkdir -p $T/dx/nchan $T/pl $T/gp; cp $P/default.cfg $T/pl/
printf 'Menu="Main:1"\n---\nx\n' > $T/dx/ArrayDevices.page; cp $T/dx/ArrayDevices.page $T/dx/ArrayOperation.page; echo x > $T/dx/nchan/parity_list
cat > $T/dx/nchan/device_list <<'S'
    $view = "<a class='view' href=\"/Main/Browse?dir=".htmlspecialchars($dir)."\"><i class=\"icon-u-tab\" title=\""._('Browse')." $dir\"></i></a>";
    $view = "<a class='view' href=\"/Main/Browse?dir=".htmlspecialchars($dir)."\"><i class=\"icon-u-tab\" title=\""._('Browse')." $dir\"></i></a>";
        $bootBrowseUrl = $bootDir !== '' ? "/Main/Browse?dir=".htmlspecialchars($bootDir) : $bootDeviceUrl;
        $poolBrowseUrl = $poolDir !== '' ? "/Main/Browse?dir=".htmlspecialchars($poolDir) : $poolDeviceUrl;
S
MA_TEST_DX=$T/dx MA_TEST_PL=$T/pl MA_TEST_ROOT=$T/gp php $P/scripts/gen-pages 2 >/dev/null 2>$T/gp.err
! grep -q '/Main/Browse' $T/pl/nchan/device_list2 && ! grep -q 'Browse' $T/gp.err && grep -q "<a class='view'></a>" $T/pl/nchan/device_list2 && ok "MainN has no Browse link to a host path" || bad "MainN Browse links: $(grep -c /Main/Browse $T/pl/nchan/device_list2) left; $(grep Browse $T/gp.err)"
# host filter mounted over the stock device_list: array N's copy must come out without it, the host copy with it
printf '%s\n' 'require_once "$docroot/webGui/include/publish.php";' '$devs   = (array)@parse_ini_file("$varroot/devs.ini",true);' > $T/dx/nchan/device_list
MA_TEST_DX=$T/dx MA_TEST_PL=$T/pl MA_TEST_ROOT=$T/gp php $P/scripts/gen-pages --host 2>/dev/null; cp $T/gp/host/device_list $T/dx/nchan/device_list
MA_TEST_DX=$T/dx MA_TEST_PL=$T/pl MA_TEST_ROOT=$T/gp php $P/scripts/gen-pages 2 >/dev/null 2>&1
grep -q ma_filter_devs $T/dx/nchan/device_list && ! grep -q 'ma_filter_devs\|multiarray/include/lib.php' $T/pl/nchan/device_list2 && ok "array N's device list has no host disk filter, even while it is mounted" || bad "device_list2 carries the host filter"
# MainN: the mover "(Schedule)" link must not open array 1's Scheduler (stock 7.3.4 ArrayOperation.page line)
cat > $T/dx/ArrayOperation.page <<'S'
Menu="Main:5"
---
    $('#mover-text').html("<b>_(Move)_</b> _(will immediately invoke the Mover)_.&nbsp;<a href=\"/Main/Settings/Scheduler\"<?if($tabbed):?> onclick=\"$.cookie('one','tab2')\"<?endif;?>>(_(Schedule)_)</a>");
S
MA_TEST_DX=$T/dx MA_TEST_PL=$T/pl MA_TEST_ROOT=$T/gp php $P/scripts/gen-pages 2 >/dev/null 2>$T/gp.err
! grep -q 'Settings/Scheduler' $T/pl/Main2Operation.page && grep -q "multiarray mover-schedule 2 '0 3 \* \* \*'" $T/pl/Main2Operation.page && ! grep -q 'mover Schedule' $T/gp.err && ok "Main2's mover Schedule link explains array 2's own schedule instead of opening array 1's" || bad "Main2 mover Schedule link: $(grep -c Settings/Scheduler $T/pl/Main2Operation.page) left"
# mover schedule collection: dispatcher knows mover/mover-schedule/mover-cron; update.php refreshes the cron after changeMover
grep -q 'multiarray mover-cron' $HERE/src/usr/local/emhttp/plugins/multiarray/include/update.php && grep -q '^  mover)   mover_one' $P/scripts/multiarray && ok "extra arrays' mover schedules are collected into the host cron" || bad "mover schedule wiring"
[ $f -eq 0 ] && echo "guards tests: all $n passed" || { echo "guards tests: $f failed"; exit 1; }
