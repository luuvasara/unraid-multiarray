# Placeholder for the future single-module backend (md_multi). Not implemented: refuse cleanly.
drv_check() { echo "driver backend 'multi' is not implemented yet" >&2; return 1; }
drv_loaded() { return 1; } drv_load() { return 1; } drv_unload() { return 0; } drv_ctl() { :; } drv_major() { :; } drv_state() { :; } drv_disk_prefix() { :; }
