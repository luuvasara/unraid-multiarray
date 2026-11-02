# multiarray common library (sourced). Instance numbers: 1 = the stock array, 2..ARRAYS = extra arrays.
PLUG=/usr/local/emhttp/plugins/multiarray
FLASH=/boot/config/plugins/multiarray
RUNROOT=/var/lib/multiarray
LOG=/var/log/multiarray.log
SERVICE=enable ARRAYS=3 BASEPORT=8081 DRIVER=renamed KOURL=
[ -r $PLUG/default.cfg ] && . $PLUG/default.cfg
# inside an instance namespace /boot/config is re-pointed, so fall back to the copy staged by 'multiarray start'
CFG=$FLASH/multiarray.cfg; [ -r $CFG ] || CFG=$RUNROOT/multiarray.cfg
[ -r $CFG ] && . <(tr -d '\r' < $CFG)
[ "$ARRAYS" -ge 1 ] 2>/dev/null || ARRAYS=3
[ "$ARRAYS" -le 9 ] || ARRAYS=9
. $PLUG/drivers/$DRIVER.sh

log() { echo "$(date '+%F %T') multiarray[$$]: $*" >> $LOG; }
# superblock path: must resolve identically in the host namespace (kernel recovery thread) AND inside the instance
# namespace (module init runs in the caller), so it lives on a bind mount of the flash dir created in the HOST namespace
sb_path() { echo $RUNROOT/cfg$1/super.dat; }
port_of() { echo $((BASEPORT + $1 - 2)); }
# pids living in instance N's mount namespace (ns.id = readlink of the namespace)
inst_pids() {
  local id p; id=$(cat $RUNROOT/a$1/ns.id 2>/dev/null) || return 0
  [ -n "$id" ] || return 0
  for p in /proc/[0-9]*; do [ "$(readlink $p/ns/mnt 2>/dev/null)" = "$id" ] && echo ${p#/proc/}; done
}
em_pid() { local p; for p in $(inst_pids $1); do [ "$(cat /proc/$p/comm 2>/dev/null)" = emhttpd ] && { echo $p; return; }; done; }
inst_running() { [ -n "$(em_pid $1)" ]; }
# read key from instance N's var.ini
inst_var() { local p; p=$(em_pid $1) || return 1; [ -n "$p" ] || return 1
  nsenter -t $p -m -- grep -oP "^$2=\"\K[^\"]*" /var/local/emhttp/var.ini 2>/dev/null; }
