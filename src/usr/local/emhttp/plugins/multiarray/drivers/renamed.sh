# Driver backend "renamed": one renamed fork of the stock md driver per extra array
# (md_unraidN.ko, /proc/mdcmdN, /proc/mdstatN, block major registered as "mdN", disks mdX%dp1).
# Interface used by multiarray (a future "multi" backend implements the same functions):
#   drv_check N        - 0 if a usable driver for this kernel exists, message on stderr otherwise
#   drv_load N SBPATH  - load the driver for instance N, superblock file SBPATH (absolute)
#   drv_unload N       - unload it; a started array is stopped in the driver first, and the module stays
#                        loaded (return 1) when that stop is refused (a disk still open)
#   drv_loaded N       - 0 if loaded
#   drv_state N        - print the driver's own mdState (empty when not loaded)
#   drv_ctl N          - print "<mdcmd path> <mdstat path>" proc entries of this instance
#   drv_major N        - print the block major used for md<slot>p1 nodes of this instance
# the module is staged into RAM (RUNROOT/ko) because /boot/config is re-pointed inside instance namespaces
drv_ko() { echo $RUNROOT/ko/md_unraid$1.ko; }
drv_check() {
  local src=$FLASH/ko/$(uname -r)/md_unraid$1.ko
  [ -f "$src" ] || { echo "no md_unraid$1.ko for kernel $(uname -r) (expected $src)" >&2; return 1; }
  [ "$(modinfo -F vermagic "$src" | awk '{print $1}')" = "$(uname -r)" ] || { echo "vermagic mismatch for $src" >&2; return 1; }
  mkdir -p $RUNROOT/ko && cp -f "$src" "$(drv_ko $1)"
}
drv_loaded() { grep -q "^md_unraid$1 " /proc/modules; }
drv_load() { drv_loaded $1 && return 0; insmod "$(drv_ko $1)" super="$2"; }
drv_state() { drv_loaded $1 && sed -n 's/^mdState=//p' /proc/mdstat$1 2>/dev/null; }
# rmmod alone is not enough: an md disk holds a module reference only while it is open, so a started array whose file
# systems are already unmounted (its instance was killed mid-start) lets rmmod through. Stop it in the driver first.
drv_unload() {
  local i
  drv_loaded $1 || return 0
  if [ "$(drv_state $1)" = STARTED ]; then
    log "a$1: driver still has the array started, stopping it in the driver"
    for i in 1 2 3 4 5 6 7 8 9 10; do   # a file system unmounted by a namespace teardown can take a moment to let go
      echo stop >/proc/mdcmd$1 2>/dev/null
      [ "$(drv_state $1)" = STARTED ] || break
      sleep 1
    done
    [ "$(drv_state $1)" = STARTED ] && { log "a$1: the driver refused to stop (a disk is still in use); module left loaded"; return 1; }
  fi
  rmmod md_unraid$1
}
drv_ctl() { echo "/proc/mdcmd$1 /proc/mdstat$1"; }
drv_major() { awk -v n="md$1" '$2==n{print $1}' /proc/devices; }
