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
U=$HERE/src/etc/udev/rules.d/99-multiarray.rules
grep 'multiarray hotplug' $U | grep -qv 'DEVTYPE' && ok "udev rule refreshes on partitions too" || bad "udev rule matches whole disks only"
# F16: a new partition must be in the views before emhttpd's lsblk (udevadm settle waits for RUN, so synchronous, no '&')
grep 'DEVTYPE}=="partition"' $U | grep -q 'multiarray devview $env{ACTION} %k %M:%m"$' && grep -q 'ln -sfn "$(readlink /sys/dev/block/$mm)" $R/stage/devblock/$mm' $P/scripts/multiarray && ok "partitions enter the filtered views from udev at once (devview), not only after the delayed refresh" || bad "partition devview rule"
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
          <tr><td>_(Parity is valid)_.</td><td><input type="submit" name="cmdCheck" value="_(Check)_"></td><td>**_(Check)_** _(will start **Parity-Check**)_.&nbsp;<a href="/Main/Settings/Scheduler"<?if ($tabbed):?> onclick="$.cookie('one','tab1')"<?endif;?>>(_(Schedule)_)</a>
S
MA_TEST_DX=$T/dx MA_TEST_PL=$T/pl MA_TEST_ROOT=$T/gp php $P/scripts/gen-pages 2 >/dev/null 2>$T/gp.err
! grep -q 'Settings/Scheduler' $T/pl/Main2Operation.page && grep -q "multiarray mover-schedule 2 '0 3 \* \* \*'" $T/pl/Main2Operation.page && ! grep -q 'mover Schedule' $T/gp.err && ok "Main2's mover Schedule link explains array 2's own schedule instead of opening array 1's" || bad "Main2 mover Schedule link: $(grep -c Settings/Scheduler $T/pl/Main2Operation.page) left"
grep -q "multiarray check-schedule 2 '0 2 1 \* \*'" $T/pl/Main2Operation.page && ! grep -q 'parity check Schedule' $T/gp.err && ok "Main2's parity-check Schedule link explains array 2's own schedule instead of opening array 1's" || bad "Main2 parity Schedule link"
# mover schedule collection: dispatcher knows mover/mover-schedule/mover-cron; update.php refreshes the cron after changeMover
grep -q 'multiarray mover-cron' $HERE/src/usr/local/emhttp/plugins/multiarray/include/update.php && grep -q '^  mover)   mover_one' $P/scripts/multiarray && ok "extra arrays' mover schedules are collected into the host cron" || bad "mover schedule wiring"
# parity-check schedule (stock Scheduler format, incl. a "first week" day test) -> one guarded line per extra array in multiarray.cron
fn() { sed -n "/^$1() {/,/^}/p" $P/scripts/multiarray; }
mkdir -p $T/fl/array4/plugins/dynamix $T/fl/array3/plugins/dynamix
printf '# Generated parity check schedule:\n0 0 * * 1 [[ $(date +%%e) -le 7 ]] && /usr/local/sbin/mdcmd check CORRECT &> /dev/null || :\n' > $T/fl/array4/plugins/dynamix/parity-check.cron
printf '# Generated mover schedule:\n40 3 * * * /usr/local/sbin/mover start > /dev/null 2> >(logger -t move)\n' > $T/fl/array3/plugins/dynamix/mover.cron
bash -c "log() { :; }; FLASH=$T/fl PLUG=/P SERVICE=enable ARRAYS=4; $(fn mover_cron); mover_cron" >/dev/null 2>&1
grep -qxF '0 0 * * 1 [[ $(date +%e) -le 7 ]] && /P/scripts/multiarray parity-check 4 CORRECT |& logger -t parity-check' $T/fl/multiarray.cron && grep -qxF '40 3 * * * /P/scripts/multiarray mover 3 |& logger -t move' $T/fl/multiarray.cron && ok "extra arrays' parity-check schedules are collected (day test and CORRECT kept) next to the mover's" || bad "parity-check cron: $(cat $T/fl/multiarray.cron 2>&1)"
bash -c "log() { :; }; FLASH=$T/fl PLUG=/P SERVICE=enable ARRAYS=4; $(fn mover_cron); $(fn check_schedule); check_schedule 4 'every month'" >/dev/null 2>$T/err; [ $? = 1 ] && grep -q "e.g. '0 2 1 \* \*'" $T/err && ok "a bad parity-check schedule is refused with an example" || bad "check-schedule validation"
bash -c "log() { :; }; FLASH=$T/fl PLUG=/P SERVICE=enable ARRAYS=4; $(fn mover_cron); $(fn check_schedule); check_schedule 4 off" >/dev/null 2>&1; ! grep -q 'parity-check 4' $T/fl/multiarray.cron && [ ! -e $T/fl/array4/plugins/dynamix/parity-check.cron ] && ok "check-schedule off removes the line" || bad "check-schedule off"
# F17: a pool whose export fails stays on the instance's list, with the reason and the command; an exported one is forgotten
mkdir -p $T/zb $T/run/a6; printf '#!/bin/bash\ncase "$1 ${@: -1}" in "list "*) printf "disk1\\ncache\\n" ;; "export disk1") echo "cannot export '"'"'disk1'"'"': pool is busy" >&2; exit 1 ;; esac; exit 0\n' > $T/zb/zpool; chmod +x $T/zb/zpool
printf 'disk1\ncache\n' > $T/run/a6/zpools
PATH=$T/zb:$PATH bash -c "log() { :; }; drv_disk_prefix() { echo mdf; }; RUNROOT=$T/run; $(fn export_own); export_own 6" 2>$T/err; rc=$?
[ $rc = 1 ] && [ "$(cat $T/run/a6/zpools)" = disk1 ] && grep -q "pool is busy" $T/err && grep -q "zpool export disk1" $T/err && ok "a pool that did not export is kept and reported with the reason and the command; the exported one is forgotten" || bad "export_own: rc=$rc list=$(cat $T/run/a6/zpools 2>&1) err=$(cat $T/err)"
# one notification per pool and incident: retries of a held pool only log; an export (or a start) ends the incident
printf '#!/bin/bash\necho "$*" >> %s/ncalls\n' $T > $T/zb/notify; chmod +x $T/zb/notify; rm -f $T/ncalls; rm -rf $T/run/a6/notified
printf '#!/bin/bash\ncase "$1 ${@: -1}" in "list "*) printf "disk1\\n" ;; "export disk1") [ -e %s/held ] && { echo "cannot unmount '"'"'/mnt/disk1'"'"': pool or dataset is busy" >&2; exit 1; } ;; esac; exit 0\n' $T > $T/zb/zpool; touch $T/held
eo() { printf 'disk1\n' > $T/run/a6/zpools; PATH=$T/zb:$PATH MA_TEST_NOTIFY=$T/zb/notify bash -c "log() { :; }; drv_disk_prefix() { echo mdf; }; RUNROOT=$T/run LOG=/var/log/multiarray.log; $(fn export_own); export_own 6" 2>$T/err; }
eo; eo; eo; c1=$(wc -l < $T/ncalls); grep -q "e.g.: fuser -vm '/mnt/disk1'. When" $T/ncalls && grep -q "zpool export disk1, then multiarray stop 6" $T/ncalls && fx=1 || fx=0
rm -f $T/held; eo; ls $T/run/a6/notified >/dev/null 2>&1 && gone=0 || gone=1; touch $T/held; eo; c2=$(wc -l < $T/ncalls)
[ "$c1" = 1 ] && [ $fx = 1 ] && [ $gone = 1 ] && [ "$c2" = 2 ] && grep -q "zpool export disk1" $T/err && ok "a held pool notifies once over 3 failed stops (with fuser/zpool export/multiarray stop), again after it exported and failed anew" || bad "notify once: first=$c1 fuser=$fx marker-gone=$gone after=$c2 calls=$(cat $T/ncalls)"
fn start_one | grep -q 'rm -rf $RUNROOT/a$N/notified' && ok "a start ends the incident (the next failed export notifies again)" || bad "start_one keeps the notified markers"
# F17: a pool on the array's own md devices is exported even when the list was lost (09m forgot it after a failed export)
printf '#!/bin/bash\necho "zpool $*" >> %s/zcalls\ncase $1 in list) printf "disk1\\nhpool\\n" ;; status) printf "  pool: hpool\\n\\thpool ONLINE\\n\\t  /dev/sdb1 ONLINE\\n  pool: disk1\\n\\tdisk1 ONLINE\\n\\t  /dev/mdf1p1  ONLINE 0 0 0\\n" ;; esac; exit 0\n' $T > $T/zb/zpool; rm -f $T/run/a6/zpools $T/zcalls
PATH=$T/zb:$PATH bash -c "log() { :; }; drv_disk_prefix() { echo mdf; }; RUNROOT=$T/run; $(fn export_own); export_own 6" 2>$T/err
grep -qx 'zpool export -f disk1' $T/zcalls && ! grep -q 'export -f hpool' $T/zcalls && ok "an unlisted pool on the array's own md devices is exported, another pool is not" || bad "export_own by device: $(tr '\n' ';' < $T/zcalls)"
# F17: the stop path ends the namespace's processes even when emhttpd is gone; start refuses while leftovers run
fn stop_one | grep -q '^  elif \[ -n "$(inst_pids $N)" \]; then' && fn stop_one | grep -q '^  for p in $(inst_pids $N); do kill $p' && fn start_one | grep -q 'ended without stopping the array' && ok "stop ends leftovers of a crashed emhttpd; start refuses while they run" || bad "stop/start leftovers"
# status after a stop that kept an unexported zfs pool (driver still loaded, no instance left): say so, with the command
mkdir -p $T/run/a7; printf 'disk1\n' > $T/run/a7/zpools; rm -f $T/run/a7/ns.id
printf '#!/bin/bash\n[ "$1" = list ] && printf "disk1\\nhpool\\n"; exit 0\n' > $T/zb/zpool; printf 'disk1\ngone\n' > $T/run/a7/zpools   # gone: exported by hand since
got=$(PATH=$T/zb:$PATH bash -c "inst_running() { return 1; }; inst_pids() { :; }; drv_loaded() { return 0; }; RUNROOT=$T/run LOG=/var/log/multiarray.log; $(fn stop_incomplete); stop_incomplete 7" 2>&1)
[ "$got" = "stop incomplete: zfs pool disk1 not exported, driver still loaded; /var/log/multiarray.log has the zpool error with the busy mount point (only the first failed stop raises a notification); see what uses it with fuser -vm '<mount point>', close it, then run: multiarray stop 7" ] && ok "status names a stop that kept an unexported zfs pool, with the command to finish it" || bad "stop_incomplete: '$got'"
got=$(bash -c "inst_running() { return 1; }; inst_pids() { :; }; drv_loaded() { return 1; }; RUNROOT=$T/run; $(fn stop_incomplete); stop_incomplete 7" 2>&1)
[ -z "$got" ] && ok "a cleanly stopped array (driver unloaded) is not reported as incomplete" || bad "stop_incomplete on a clean stop: '$got'"
fn start_one | grep -q 'case $inc in \*"zfs pool"\*) echo "array $N: $inc"' && ok "start refuses with the same words while the pool is still imported" || bad "start_one does not name the incomplete stop"
grep -q '"incomplete\\":\\"$inc\\"' $P/scripts/multiarray && grep -q 'inc=$(stop_incomplete $N); if \[ -n "$inc" \]' $P/scripts/multiarray && ok "status and status-json carry the incomplete stop" || bad "status/status-json without the incomplete stop"
fn stop_one | grep -B1 '^  export_own $N ||' | grep -q 'rm -f $RUNROOT/a$N/ns.id' && ok "stop forgets the ended namespace before a pool export can fail (the GUI no longer shows the array as up)" || bad "stop_one keeps ns.id while the export fails"
grep -q 'i.incomplete' $P/include/main.php && grep -q "(run||inc)?'':'none'" $P/include/main.php && grep -q "\$maSt\[\$n\]\['incomplete'\]" $P/MultiArray.page && ok "MainN bar and Settings show the incomplete stop; MainN offers Stop instance to finish it" || bad "GUI does not show the incomplete stop"
[ $f -eq 0 ] && echo "guards tests: all $n passed" || { echo "guards tests: $f failed"; exit 1; }
