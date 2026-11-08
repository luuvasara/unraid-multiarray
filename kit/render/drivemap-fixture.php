<?php
/* drivemap-fixture.php <dir>: write a made-up server that the drive map can read with DRIVEMAP_ROOT=<dir>: a tree like
 * /sys, /dev/disk and /var/local/emhttp, and the outputs of lsblk, btrfs and zpool. The drives are invented; the shapes
 * are those real servers have: SATA on an AHCI controller, SAS behind an HBA with an encrypted array disk, two NVMe
 * in a btrfs pool called "nvme", an unassigned NVMe whose controller name equals a slot name, a zfs mirror, USB flash.
 * Used by the kit's tests and by a plugin's render test for the Drives tab. */
$W = $argv[1] ?? ($DRIVEMAP_FIXTURE ?? '');
if ($W === '') { fwrite(STDERR, "usage: drivemap-fixture.php DIR\n"); exit(2); }
exec('rm -rf ' . escapeshellarg($W)); mkdir("$W/cmd", 0755, true);
if (!function_exists('dmfx_put')) {
function dmfx_put(string $f, string $v): void { @mkdir(dirname($f), 0755, true); file_put_contents($f, $v); }
function dmfx_dev(string $W, string $kname, string $real, array $attr, array $parts = [], array $links = []): void {
  @mkdir("$W$real", 0755, true); @mkdir("$W/sys/block", 0755, true);
  symlink('..' . substr($real, 4), "$W/sys/block/$kname");
  foreach ($attr as $k => $v) dmfx_put("$W/sys/block/$kname/$k", "$v\n");
  foreach ($parts as $p => $holders) { @mkdir("$W/sys/block/$kname/$p", 0755, true); foreach ($holders as $h) @mkdir("$W/sys/block/$kname/$p/holders/$h", 0755, true); }
  foreach ($links as $l) { [$d, $n, $t] = $l; @mkdir("$W/dev/disk/$d", 0755, true); symlink("../../$t", "$W/dev/disk/$d/$n"); }
}
}
$pci = '/sys/devices/pci0000:00';
dmfx_dev($W, 'sda', "$pci/0000:00:17.0/ata1/host0/target0:0:0/0:0:0:0/block/sda", ['size' => 31251759104, 'queue/rotational' => 1, 'device/model' => 'WDC WD160EDGZ'], ['sda1' => []],
    [['by-id', 'ata-WDC_WD160EDGZ_2CJAAAAA', 'sda'], ['by-id', 'ata-WDC_WD160EDGZ_2CJAAAAA-part1', 'sda1'], ['by-path', 'pci-0000:00:17.0-ata-1', 'sda']]);
dmfx_dev($W, 'sdb', "$pci/0000:00:17.0/ata2/host1/target1:0:0/1:0:0:0/block/sdb", ['size' => 15628053168, 'queue/rotational' => 1, 'device/model' => 'ST8000VN004'], ['sdb1' => []],
    [['by-id', 'ata-ST8000VN004_ZA1BBBBB', 'sdb'], ['by-id', 'wwn-0x5000c500aaaaaaa1', 'sdb']]);
dmfx_dev($W, 'sdc', "$pci/0000:00:1c.0/0000:03:00.0/host7/port-7:2/end_device-7:2/target7:0:2/7:0:2:0/block/sdc", ['size' => 15628053168, 'queue/rotational' => 1, 'device/model' => 'ST8000NM017B'], ['sdc1' => ['dm-0']],
    [['by-id', 'scsi-35000c500bbbbbbb2', 'sdc']]);
dmfx_dev($W, 'sdd', "$pci/0000:00:14.0/usb1/1-4/1-4:1.0/host8/target8:0:0/8:0:0:0/block/sdd", ['size' => 62521344, 'queue/rotational' => 0, 'device/model' => 'Cruzer Fit'], ['sdd1' => []],
    [['by-id', 'usb-SanDisk_Cruzer_Fit_4C530001-0:0', 'sdd'], ['by-label', 'UNRAID', 'sdd1']]);
dmfx_dev($W, 'sde', "$pci/0000:00:17.0/ata4/host3/target3:0:0/3:0:0:0/block/sde", ['size' => 1953525168, 'queue/rotational' => 0, 'device/model' => 'Samsung SSD 870'], ['sde1' => []]);
dmfx_dev($W, 'sdf', "$pci/0000:00:17.0/ata5/host4/target4:0:0/4:0:0:0/block/sdf", ['size' => 1953525168, 'queue/rotational' => 0, 'device/model' => 'Samsung SSD 870'], ['sdf1' => []]);
foreach ([0 => '0000:01:00.0', 1 => '0000:02:00.0', 2 => '0000:05:00.0'] as $i => $addr)
  dmfx_dev($W, "nvme{$i}n1", "$pci/0000:00:1d.$i/$addr/nvme/nvme$i/nvme{$i}n1", ['size' => 1953525168, 'queue/rotational' => 0, 'device/model' => 'WD Black SN850X', 'device/serial' => "NV00$i"], ["nvme{$i}n1p1" => []]);
// the pool is called "nvme": its slots are nvme (on controller nvme1) and nvme2 (on nvme0), while controller nvme2 is unassigned
dmfx_dev($W, 'loop0', '/sys/devices/virtual/block/loop0', ['size' => 100]);
dmfx_dev($W, 'md1p1', '/sys/devices/virtual/block/md1p1', ['size' => 100]);
dmfx_dev($W, 'mdb1p1', '/sys/devices/virtual/block/mdb1p1', ['size' => 100]);
dmfx_dev($W, 'dm-0', '/sys/devices/virtual/block/dm-0', ['dm/name' => 'md3p1']);
@mkdir("$W/sys/block/dm-0/slaves/md3p1", 0755, true);
dmfx_put("$W/var/local/emhttp/disks.ini", <<<'INI'
["parity"]
name="parity"
device="sda"
id="WDC_WD160EDGZ_2CJAAAAA"
type="Parity"
["disk1"]
name="disk1"
device="sdb"
id="ST8000VN004_ZA1BBBBB"
type="Data"
deviceSb="md1p1"
luksState="0"
fsType="xfs"
["disk3"]
name="disk3"
device="sdc"
id="ST8000NM017B_ZA1CCCCC"
type="Data"
deviceSb="md3p1"
luksState="1"
fsType="xfs"
["nvme"]
name="nvme"
device="nvme1n1"
id="WD_Black_SN850X_NV001"
type="Cache"
deviceSb="nvme1n1p1"
fsType="btrfs"
["nvme2"]
name="nvme2"
device="nvme0n1"
id="WD_Black_SN850X_NV000"
type="Cache"
deviceSb="nvme0n1p1"
["tank"]
name="tank"
device="sde"
id="Samsung_SSD_870_S5AAAAAA"
type="Cache"
fsType="zfs"
["tank2"]
name="tank2"
device="sdf"
id="Samsung_SSD_870_S5BBBBBB"
type="Cache"
["flash"]
name="flash"
device="sdd"
id="Cruzer_Fit"
type="Flash"
INI);
dmfx_put("$W/var/local/emhttp/devs.ini", "[\"dev1\"]\nname=\"dev1\"\ndevice=\"nvme2n1\"\nid=\"WD_Black_SN850X_NV002\"\n[\"dev2\"]\nname=\"dev2\"\ndevice=\"sdz\"\nid=\"gone\"\n");
dmfx_put("$W/cmd/lsblk.json", json_encode(['blockdevices' => [
  ['name' => 'sda', 'kname' => 'sda', 'serial' => '2CJAAAAA', 'wwn' => '0x5000cca0aaaaaaa0', 'tran' => 'sata', 'hctl' => '0:0:0:0', 'children' => [['kname' => 'sda1']]],
  ['name' => 'sdb', 'kname' => 'sdb', 'serial' => 'ZA1BBBBB', 'wwn' => '0x5000c500aaaaaaa1', 'tran' => 'sata', 'children' => [['kname' => 'sdb1', 'fstype' => 'xfs', 'uuid' => '11111111-2222-4333-8444-555555555555']]],
  ['name' => 'sdc', 'kname' => 'sdc', 'serial' => 'ZA1CCCCC', 'wwn' => '0x5000c500bbbbbbb2', 'tran' => 'sas', 'children' => [['kname' => 'sdc1', 'fstype' => 'crypto_LUKS', 'uuid' => 'aaaaaaaa-0000-4000-8000-000000000003']]],
  ['name' => 'sdd', 'kname' => 'sdd', 'serial' => '4C530001', 'tran' => 'usb', 'children' => [['kname' => 'sdd1', 'fstype' => 'vfat', 'label' => 'UNRAID', 'uuid' => '2732-64F5']]],
  ['name' => 'sde', 'kname' => 'sde', 'serial' => 'S5AAAAAA', 'tran' => 'sata', 'children' => [['kname' => 'sde1', 'fstype' => 'zfs_member']]],
  ['name' => 'sdf', 'kname' => 'sdf', 'serial' => 'S5BBBBBB', 'tran' => 'sata', 'children' => [['kname' => 'sdf1', 'fstype' => 'zfs_member']]],
  ['name' => 'nvme0n1', 'kname' => 'nvme0n1', 'serial' => 'NV000', 'tran' => 'nvme', 'children' => [['kname' => 'nvme0n1p1', 'fstype' => 'btrfs', 'uuid' => 'bbbbbbbb-0000-4000-8000-00000000000b', 'uuid_sub' => 'cccccccc-0000-4000-8000-000000000002']]],
  ['name' => 'nvme1n1', 'kname' => 'nvme1n1', 'serial' => 'NV001', 'tran' => 'nvme', 'children' => [['kname' => 'nvme1n1p1', 'fstype' => 'btrfs', 'uuid' => 'bbbbbbbb-0000-4000-8000-00000000000b', 'uuid_sub' => 'cccccccc-0000-4000-8000-000000000001']]],
]]));
dmfx_put("$W/cmd/btrfs-show", "Label: none  uuid: bbbbbbbb-0000-4000-8000-00000000000b\n\tTotal devices 2 FS bytes used 1\n\tdevid    1 size 1 used 1 path /dev/nvme1n1p1\n\tdevid    2 size 1 used 1 path /dev/nvme0n1p1\n\n");
dmfx_put("$W/cmd/zpool-status-P", "  pool: tank\n state: ONLINE\nconfig:\n\n\tNAME           STATE     READ WRITE CKSUM\n\ttank           ONLINE       0     0     0\n\t  mirror-0     ONLINE       0     0     0\n\t    /dev/sde1  ONLINE       0     0     0\n\t    /dev/sdf1  ONLINE       0     0     0\n\nerrors: No known data errors\n");
dmfx_put("$W/cmd/zpool-status-gP", "  pool: tank\n state: ONLINE\nconfig:\n\n\tNAME                      STATE     READ WRITE CKSUM\n\ttank                      ONLINE       0     0     0\n\t  1111111111111111111     ONLINE       0     0     0\n\t    2222222222222222222   ONLINE       0     0     0\n\t    3333333333333333333   ONLINE       0     0     0\n\nerrors: No known data errors\n");

