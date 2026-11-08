# Changelog

Copied by the build from the <CHANGES> block of multiarray.plg; edit the .plg, not this file.

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
