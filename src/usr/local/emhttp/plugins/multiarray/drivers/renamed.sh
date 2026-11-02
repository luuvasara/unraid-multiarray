# Driver backend "renamed": one renamed fork of the stock md driver per extra array
# (md_unraidN.ko, /proc/mdcmdN, /proc/mdstatN, block major registered as "mdN", disks mdX%dp1).
# Interface used by multiarray (a future "multi" backend implements the same functions):
#   drv_check N        - 0 if a usable driver for this kernel exists, message on stderr otherwise
#   drv_load N SBPATH  - load the driver for instance N, superblock file SBPATH (absolute)
#   drv_unload N       - unload it
#   drv_loaded N       - 0 if loaded
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
drv_unload() { drv_loaded $1 && rmmod md_unraid$1; return 0; }
drv_ctl() { echo "/proc/mdcmd$1 /proc/mdstat$1"; }
drv_major() { awk -v n="md$1" '$2==n{print $1}' /proc/devices; }
