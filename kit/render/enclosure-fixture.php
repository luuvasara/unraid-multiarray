<?php
/* enclosure-fixture.php <dir>: a made-up server tree with a QNAP TL-D1600S, for DRIVEMAP_ROOT=<dir> (use an empty dir).
 * The card is a PCIe switch (1b21:2824) with four AHCI controllers (1b21:1164), as the QXP-1600eS has. The cabling and
 * the contents copy the unit calibrated on 2026-11-08 (bay -> controller and port; model, size, what is on each disk);
 * serials, UUIDs and ids are invented. Also a lone ASM1164 behind no switch, which is not the enclosure.
 * Writes, besides the sysfs tree: /dev nodes (empty files), /dev/disk/by-id links, cmd/lsblk.json, and cmd/blkid.json:
 * what blkid -p would say per node, with "btrfs" => [num_devices, devid] for the superblock a test can synthesize, and per
 * whole disk "_content" and "_part_start" so a test can synthesize sector 0 and the disk's blocks. */
$W = $argv[1] ?? ($ENCLOSURE_FIXTURE ?? '');
if ($W === '') { fwrite(STDERR, "usage: enclosure-fixture.php DIR\n"); exit(2); }
if (!function_exists('encfx_put')) {
function encfx_put(string $f, string $v): void { @mkdir(dirname($f), 0755, true); file_put_contents($f, $v); }
function encfx_pci(string $W, string $path, string $id): void {
  [$v, $d] = explode(':', $id);
  encfx_put("$W$path/vendor", "0x$v\n"); encfx_put("$W$path/device", "0x$d\n");
  @mkdir("$W/sys/bus/pci/devices", 0755, true);
  $l = "$W/sys/bus/pci/devices/" . basename($path);
  if (!is_link($l)) symlink('../../..' . substr($path, 4), $l);
}
}
$up = '/sys/devices/pci0000:00/0000:00:01.0/0000:01:00.0';
encfx_pci($W, '/sys/devices/pci0000:00/0000:00:01.0', '8086:a8b0');
encfx_pci($W, $up, '1b21:2824');
$ctl = [];
foreach (['00', '04', '08', '0c'] as $i => $dn) {
  $down = "$up/0000:02:$dn.0"; encfx_pci($W, $down, '1b21:2824');
  $c = sprintf("$down/0000:%02x:00.0", 3 + $i); encfx_pci($W, $c, '1b21:1164'); $ctl[$i + 1] = $c;
}
encfx_pci($W, '/sys/devices/pci0000:00/0000:00:1c.4/0000:0a:00.0', '1b21:1164');   // a lone ASM1164, no switch: not the enclosure
$T3 = 5860533168; $T2 = 3907029168; $T1 = 1953525168;
/* bay, kernel name, controller (1 = 03:00.0), port, model, sectors, content: btrfs (single-device data disk) | none (Unraid
 * partition at 64, no file system, blank) | cleared (Unraid's cleared mark: an MBR whose only entry is type 00 at sector 1,
 * size ffffffff, which the kernel ignores, so no partition; blank) */
$ENCLOSURE_DISKS = [
  ['HDD1', 'sdk', 3, 4, 'WDC WD30EFRX-68EUZN0', $T3, 'cleared'],   ['HDD2', 'sdj', 3, 3, 'ST32000641AS', $T2, 'btrfs'],
  ['HDD4', 'sde', 3, 1, 'ST32000641AS', $T2, 'btrfs'],          ['HDD5', 'sdp', 4, 4, 'WDC WD20PURX-64P6ZY0', $T2, 'btrfs'],
  ['HDD6', 'sdo', 4, 3, 'WDC WD30EFRX-68EUZN0', $T3, 'btrfs'],  ['HDD8', 'sdf', 4, 1, 'ST2000DM008-2FR102', $T2, 'btrfs'],
  ['HDD9', 'sdn', 1, 4, 'ST3000DM001-1ER166', $T3, 'btrfs'],    ['HDD10', 'sdm', 1, 3, 'ST3000VN006-3AW104', $T3, 'btrfs'],
  ['HDD11', 'sdl', 1, 2, 'WDC WD1001FALS-00J7B0', $T1, 'btrfs'], ['HDD12', 'sdi', 1, 1, 'ST32000641AS', $T2, 'btrfs'],
  ['SSD1', 'sdh', 2, 4, 'LITEONIT LCS-128M6S', 250069680, 'none'], ['SSD2', 'sdg', 2, 3, 'INTEL SSDSC2BW120A4', 234441648, 'none'],
  ['SSD3', 'sdd', 2, 2, 'Samsung SSD 860 EVO 500GB', 937703088, 'none'],
];
$lsblk = []; $blkid = [];
foreach ($ENCLOSURE_DISKS as $n => [$bay, $k, $c, $port, $model, $sectors, $content]) {
  $a = 20 + ($c - 1) * 4 + $port;   // ata ports of a controller count up with its port number
  $serial = sprintf('ENC%02d%s', $n, substr(md5($k), 0, 5));
  $real = "{$ctl[$c]}/ata$a/host$a/target$a:0:0/$a:0:0:0/block/$k";
  @mkdir("$W$real", 0755, true); @mkdir("$W/sys/block", 0755, true);
  if (!is_link("$W/sys/block/$k")) symlink('..' . substr($real, 4), "$W/sys/block/$k");
  if (!is_link("$W$real/device")) symlink('../..', "$W$real/device");   // the SCSI device, as in sysfs
  encfx_put("$W/sys/block/$k/size", "$sectors\n"); encfx_put("$W/sys/block/$k/queue/rotational", strpos($bay, 'SSD') === 0 ? "0\n" : "1\n");
  encfx_put("$W/sys/block/$k/queue/logical_block_size", "512\n"); encfx_put("$W/sys/block/$k/queue/physical_block_size", "4096\n");
  encfx_put("$W/sys/block/$k/device/model", "$model\n"); encfx_put("$W/sys/block/$k/device/serial", "$serial\n");
  @mkdir("$W/sys/block/$k/holders", 0755, true);
  $gpt = $sectors * 512 > 2199023255552;   // Unraid: MBR up to 2 TiB, GPT above
  $size = $gpt ? $sectors - 64 - 34 - (($sectors - 64 - 34) % 8) : $sectors - 64;
  if ($content !== 'cleared') {
    encfx_put("$W/sys/block/$k/{$k}1/start", "64\n"); encfx_put("$W/sys/block/$k/{$k}1/size", "$size\n"); encfx_put("$W/sys/block/$k/{$k}1/partition", "1\n");
    @mkdir("$W/sys/block/$k/{$k}1/holders", 0755, true);
  }
  encfx_put("$W/sys/class/ata_port/ata$a/port_no", "$port\n");
  encfx_put("$W/dev/$k", ''); if ($content !== 'cleared') encfx_put("$W/dev/{$k}1", '');
  $id = str_replace(' ', '_', $model) . "_$serial";
  @mkdir("$W/dev/disk/by-id", 0755, true);
  @symlink("../../$k", "$W/dev/disk/by-id/ata-$id"); if ($content !== 'cleared') @symlink("../../{$k}1", "$W/dev/disk/by-id/ata-$id-part1");
  $uuid = $content === 'btrfs' ? sprintf('%08x-0000-4000-8000-%012x', crc32($k), $n) : '';
  $blkid["/dev/$k"] = ['PTTYPE' => $gpt && $content !== 'cleared' ? 'gpt' : 'dos', '_content' => $content, '_part_start' => 64];
  if ($content !== 'cleared') $blkid["/dev/{$k}1"] = $content === 'btrfs' ? ['TYPE' => 'btrfs', 'UUID' => $uuid, 'UUID_SUB' => sprintf('%08x-1111-4000-8000-%012x', crc32($k), $n), 'btrfs' => [1, 1]] : [];
  $lsblk[] = ['name' => $k, 'kname' => $k, 'serial' => $serial, 'tran' => 'sata', 'children' => $content === 'cleared' ? [] : [['kname' => "{$k}1"] + ($uuid ? ['fstype' => 'btrfs', 'uuid' => $uuid] : [])]];
}
encfx_put("$W/cmd/lsblk.json", json_encode(['blockdevices' => $lsblk]));
encfx_put("$W/cmd/blkid.json", json_encode($blkid, JSON_UNESCAPED_SLASHES));
