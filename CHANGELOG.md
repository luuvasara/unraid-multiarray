# Changelog

Copied by the build from the <CHANGES> block of multiarray.plg; edit the .plg, not this file.

## 2026.11.09p
- Safety: an extra array can no longer reach array 1's driver. Stopping array 1 reloads its driver, which used to leave a still-running extra array talking to array 1. Extra arrays now start only while array 1 is started, are always gone before array 1 stops, and an extra array that loses its own driver is ended at once instead of sending anything on.
- Safety: zfs pools stay with their array. Each array mounts, changes and exports only its own pools (before, starting any array mounted every other array's and array 1's zfs pools over its own disks). An array whose zfs pool name is already in use on the server (pool names are server-wide; an array zfs disk is a pool named after its slot, disk1, disk2, ...) is not started or formatted, and the reason is shown.
- Stopping array 1, rebooting or shutting down no longer waits forever for an extra array that cannot stop. Extra arrays are stopped together within the shutdown time-out; one that does not stop in time is ended (it is then unclean) and a notification says so.
- Healthy disks of an extra array no longer show "Unmountable" (with Format offered) after its driver was reloaded or after an encrypted btrfs disk was opened, and a disk partitioned after the array's start (a new parity disk) is no longer missing at the next start.
- Extra arrays no longer keep array 1's disks, user shares and pools busy (array 1 can export its zfs pools while extra arrays run).
- Each extra array has its own encryption key file; none uses array 1's /root/keyfile. An encrypted extra array asks for its own passphrase.
- Add Pool, share settings and the mover now work in extra arrays (their flash folder gets pools/ and shares/), and extra arrays log to the syslog.
- Each extra array's own web GUI now listens only on the server itself (127.0.0.1), so every request goes through the host GUI's Main tabs, where the safety checks run. For debugging, reach one through an SSH tunnel (Settings, Multi Arrays shows the ports).
- The Browse icons on an extra array's Main tab are gone: they opened array 1's folders of the same name (/mnt/disk1, ...), not the extra array's.
- Fix: an extra array's Main tab no longer hides the disks pinned to that array from its own assign lists (it used array 1's filter).
- An extra array's mover schedule now runs: the host's cron runs each extra array's mover inside that array, on that array's own schedule (before, only Move on its Main tab moved anything). Set it with multiarray mover-schedule N '0 3 * * *' (example: every day at 03:00); the Schedule link on its Main tab says so.
- Export records more of the array, so an import can recreate it: the zfs pool name of each zfs disk, the array's pools (devices, file system, profile, settings) and its share settings. A disk whose setting says zfs but holds no file system is exported as having none, with a warning; zfs is always written as zfs. Older export files still import.
- Import checks zfs pool names in the plan: a name already used on this server (zfs pool names are server-wide; an array zfs disk is named after its slot, disk1, ...) is refused before anything is assigned, with what to do. The plan lists the array's pools with the steps to add them on the Main tab (they are not created automatically, so nothing is formatted by mistake) and pins their disks to the array; share settings are written into the extra array's config where it has none.
- Fix: formatting a new pool disk in an extra array failed (the pool stayed "Unmountable", the syslog showed mkfs with an empty device "/dev/"), and a second Format could end that array's emhttpd. A partition created by Format now reaches the extra array's own disk list at once.
- Stopping an extra array whose emhttpd had ended by itself (crashed) now also ends what was left of it (its web server and user shares), so its disks and zfs pools are released (also a pool that 2026.11.09m lost track of after a failed export: pools are now also found by the array's own disks). A zfs pool that still cannot be exported is named in a message and a notification, with the reason and the commands to finish (example: zpool export disk1, then multiarray stop 4); the next stop tries it again. Until then, starting that array is refused with the same advice, and multiarray status shows it.
- An extra array's parity-check schedule now runs (before, only Check on its Main tab started one). Set it with multiarray check-schedule N '0 2 1 * *' (example: on the 1st of every month at 02:00, errors reported, parity not changed; add correct to write corrections), or off. The Schedule link next to Check on its Main tab says so. A cumulative (pause/resume) schedule runs as one check.
- After updating, stop and start each extra array once so it runs with these changes.
## 2026.11.08g
- Fix: stopping an extra array while it was still starting could leave its disks behind in the kernel. Until a reboot, that array's disk1 could then not be mounted, and stopping it again or listing disks (lsblk) caused kernel errors. The driver now stops a started array when it is unloaded and gives up a disk it could not add, and the plugin waits for a start in progress before it stops an array.
- Updates replace the cached driver files on the flash when the package brings a new build of them.
- Stopping an array whose state is a stopped but degraded one (for example a disabled disk) no longer waits five minutes and reports that it did not stop cleanly.
## 2026.11.08f
- New Disks tab (Settings, Multi Arrays, Disks): every disk grouped by where it belongs (each array in slot order, pools, unassigned, not usable), with size, model, serial, temperature and SMART health as Unraid last read them (a sleeping disk is not woken), controller, port and enclosure bay. Details shows every name a disk goes by; the search box marks every disk a name, a bay or a log line can mean.
- Locate: reads one disk for 20 seconds so its activity light flashes. Read only, one disk at a time, cancellable; it asks first when the disk is asleep.
- QNAP TL-D1600S enclosures are drawn as their front panel with the bays filled in. Confirm the cabling with Locate, or correct it on the page.
- SSDs in an array get a warning on the Disks tab, in found arrays and in Prepare import: SSDs are strongly not recommended as array disks (no TRIM in an array, and parity may suffer).
- Found arrays: a read-only check of the free disks reports evidence (partition table and start, file system signature, whether a disk is blank, optionally parity against the XOR of the data disks) and suggests which disks may form an array. It never mounts, writes or assigns; you decide. Prepare import turns your choice into a plan for the Export and import tab, which follows the read-only parity check and explains the result.
- Removing the plugin is silent when extra arrays were never enabled.
## 2026.11.08d
- Export and import of a whole array (Settings, Multi Arrays, Export and import; or multiarray export N in a terminal). Export writes a file with every slot's disk as Unraid identifies it (id, serial number, size), the partition layout, file system and encryption UUIDs and the array's settings; never keys, passphrases or files. It works without enabling extra arrays and only reads. It refuses an array that is not clean and an array on a VM's virtual disks, and says why.
- Import puts the disks of an exported array into the same slots, as an extra array or as the main array of a fresh server. Each slot needs exactly one free disk with the same serial number and size; the plan is shown and nothing changes before you confirm. The assignments go through Unraid's own commands. Then the array can be started with parity already valid and checked read-only.
- A new install does nothing until extra arrays are enabled in the settings: no drivers, no Main tabs, no background work. Disabling stops the extra arrays (they must be stopped first) and removes their tabs. Existing settings are kept on update.
## 2026.11.08b
- A disclaimer on the settings page, in the install output, the About tab and the README: Multi Arrays is an independent project, not made or supported by Lime Technology. Neither its author nor Lime Technology takes any responsibility for any results caused by this plugin or its implementation. It is a proof of concept, meant only to show how simple support for multiple arrays could be. If that doesn't sit well with you, wait for the official support for multiple arrays in Unraid.
## 2026.11.06a
- New About tab under Settings, Multi Arrays: the installed version, the latest changes and links. Updates print the latest changes in a box at the end of the install output.
- Screenshots in the README show one panel each.

## 2026.11.02b
- First public release: extra arrays (default 3 in total), native Main2/Main3 tabs, disk pinning, hotplug updates.
