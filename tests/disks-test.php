<?php
/* Unit tests of include/disks.php (Disks tab) on fixture trees, no server needed:
 *  K: the server with the TL-D1600S calibrated on 2026-11-08 (kit/render/enclosure-fixture.php: nine single-device btrfs
 *     data disks, a cleared 3T disk, three blank SSDs) plus a main array and the flash on the board;
 *  P: small disk images in files, so the blank test and the parity XOR check read real bytes.
 * Run: php tests/disks-test.php   (exit 0 = all passed) */
define('MA_TEST', 1);
$HERE = dirname(__DIR__);
require "$HERE/kit/runtime/drivemap/DriveMap.php";
require "$HERE/kit/runtime/drivemap/Enclosure.php";
require "$HERE/src/usr/local/emhttp/plugins/multiarray/include/disks.php";
$fail = 0; $n = 0;
function check($cond, $what) { global $fail, $n; $n++; echo ($cond ? 'ok   ' : 'FAIL ').$what."\n"; if (!$cond) $fail = 1; }
function same($got, $want, $what) { check($got === $want, $what.($got === $want ? '' : ': got '.json_encode($got).', want '.json_encode($want))); }
function put($f, $s) { @mkdir(dirname($f), 0755, true); file_put_contents($f, $s); }
function ini($a) { $o = ''; foreach ($a as $k => $v) { $o .= "[\"$k\"]\n"; foreach ($v as $kk => $vv) $o .= "$kk=\"$vv\"\n"; } return $o; }
/* a SATA disk on the board's AHCI controller, sysfs as the kernel lays it out */
function disk($W, $dev, $ata, $sectors, $model, $serial, $parts = [[64, null]]) {
  $real = "/sys/devices/pci0000:00/0000:00:17.0/ata$ata/host$ata/target$ata:0:0/$ata:0:0:0/block/$dev";
  @mkdir("$W$real/holders", 0755, true); @mkdir("$W/sys/block", 0755, true);
  symlink('..'.substr($real, 4), "$W/sys/block/$dev"); symlink('../..', "$W$real/device");
  put("$W$real/size", "$sectors\n"); put("$W$real/device/model", "$model\n"); put("$W$real/device/serial", "$serial\n");
  put("$W$real/queue/logical_block_size", "512\n"); put("$W$real/queue/rotational", "1\n");
  put("$W/sys/class/ata_port/ata$ata/port_no", "1\n");
  foreach ($parts as $i => [$st, $sz]) { $p = $dev.($i + 1); put("$W$real/$p/start", "$st\n"); put("$W$real/$p/size", ($sz ?? $sectors - $st)."\n"); put("$W$real/$p/partition", ($i + 1)."\n"); @mkdir("$W$real/$p/holders", 0755, true); }
  @mkdir("$W/dev/disk/by-id", 0755, true);
  symlink("../../$dev", "$W/dev/disk/by-id/ata-".str_replace(' ', '_', $model)."_$serial");
}
function ctx($W, $blkid, $readblock, $over = []) {
  return ma_d_ctx($over + ['sys' => "$W/sys", 'byid' => "$W/dev/disk/by-id", 'dev' => "$W/dev", 'emhttp' => "$W/var/local/emhttp", 'boot' => "$W/boot",
    'plugcfg' => "$W/plugcfg", 'runroot' => "$W/run", 'proc' => "$W/proc", 'mounts' => "$W/mounts", 'swaps' => "$W/swaps", 'version' => "$W/unraid-version",
    'hostname' => 'Tower', 'now' => 1793900000, 'allow_virtual' => false, 'dmroot' => $W, 'hidden' => '', 'smart' => "$W/var/local/emhttp/smart",
    'monitor' => "$W/var/local/emhttp/monitor.ini", 'blkid' => $blkid, 'readblock' => $readblock]);
}

/* ================= K: the enclosure server ================= */
$W = "$HERE/tests/.work/disks-k"; exec('rm -rf '.escapeshellarg($W)); mkdir($W, 0755, true);
$ENCLOSURE_FIXTURE = $W; $ENCLOSURE_DISKS = null;
require "$HERE/kit/render/enclosure-fixture.php";
disk($W, 'sda', 1, 7814037168, 'WDC WD40EFRX', 'WD-PAR1'); disk($W, 'sdb', 2, 7814037168, 'WDC WD40EFRX', 'WD-DAT1');
disk($W, 'sdc', 3, 62521344, 'Cruzer Fit', '4C530001');
put("$W/dev/sda", ''); put("$W/dev/sdb", ''); put("$W/dev/sdc", '');
put("$W/var/local/emhttp/disks.ini", ini([
  'parity' => ['idx' => 0, 'name' => 'parity', 'device' => 'sda', 'id' => 'WDC_WD40EFRX_WD-PAR1', 'type' => 'Parity', 'temp' => '31', 'color' => 'green-on', 'spundown' => '0'],
  'disk1' => ['idx' => 1, 'name' => 'disk1', 'device' => 'sdb', 'id' => 'WDC_WD40EFRX_WD-DAT1', 'type' => 'Data', 'temp' => '*', 'color' => 'green-blink', 'spundown' => '1', 'deviceSb' => 'md1p1', 'fsType' => 'xfs'],
  'flash' => ['idx' => 54, 'name' => 'flash', 'device' => 'sdc', 'id' => 'Cruzer_Fit_4C530001', 'type' => 'Flash']]));
$devs = []; $i = 0;
foreach ($ENCLOSURE_DISKS as [$bay, $k, $c, $port, $model, $sectors, $content]) {
  $i++; $id = '';
  foreach (glob("$W/dev/disk/by-id/ata-*") as $l) if (readlink($l) === "../../$k") $id = substr(basename($l), 4);
  $devs["dev$i"] = ['name' => "dev$i", 'device' => $k, 'id' => $id, 'temp' => (string)(30 + $i), 'spundown' => '0'];
}
put("$W/var/local/emhttp/devs.ini", ini($devs));
$smartHead = "smartctl 7.5\nCopyright\n\n=== START OF READ SMART DATA SECTION ===\nSMART overall-health self-assessment test result: PASSED\n\nID# ATTRIBUTE_NAME FLAG VALUE WORST THRESH TYPE UPDATED WHEN_FAILED RAW_VALUE\n";
put("$W/var/local/emhttp/smart/parity", $smartHead."  5 Reallocated_Sector_Ct 0x0033 200 200 140 Pre-fail Always - 0\n197 Current_Pending_Sector 0x0032 200 200 000 Old_age Always - 0\n");
put("$W/var/local/emhttp/smart/dev2", $smartHead."  5 Reallocated_Sector_Ct 0x0033 199 199 140 Pre-fail Always - 8\n");
put("$W/var/local/emhttp/smart/dev3", str_replace('PASSED', 'FAILED!', $smartHead)."  5 Reallocated_Sector_Ct 0x0033 100 100 140 Pre-fail Always FAILING_NOW 2000\n");
put("$W/mounts", "/dev/sdc1 /boot vfat rw 0 0\n"); put("$W/swaps", ''); put("$W/unraid-version", "version=\"7.3.4\"\n");
put("$W/plugcfg/multiarray.cfg", "SERVICE=\"disable\"\nARRAYS=\"3\"\n");
$BK = json_decode(file_get_contents("$W/cmd/blkid.json"), true);
$blkidK = function ($node) use ($BK, $W) { $k = substr($node, strlen($W)); $r = $BK[$k] ?? []; unset($r['btrfs'], $r['_content'], $r['_part_start']); return $r; };
/* what the disks hold: sector 0 (MBR), the btrfs superblock, zeros elsewhere; every read is logged to prove reads only */
$READS = [];
$readK = function ($node, $off, $len) use ($BK, $W, &$READS) {
  $READS[] = [$node, $off, $len];
  $k = substr($node, strlen($W)); $info = $BK[$k] ?? null; $buf = str_repeat("\0", $len);
  if (!$info) return $buf;
  $content = $info['_content'] ?? 'none';
  if ($off < 512) {
    $mbr = str_repeat("\0", 512);
    if ($content === 'cleared') $mbr = substr_replace($mbr, "\0\0\0\0\x00\0\0\0".pack('V', 1).pack('V', 0xffffffff), 446, 16);
    else $mbr = substr_replace($mbr, "\x00\0\0\0".($info['PTTYPE'] === 'gpt' ? "\xee" : "\x83")."\0\0\0".pack('V', 64).pack('V', 1000), 446, 16);
    $mbr = substr_replace($mbr, "\x55\xaa", 510, 2);
    $buf = substr_replace($buf, substr($mbr, $off, min($len, 512 - $off)), 0, min($len, 512 - $off));
  }
  $p = $BK["$k".'1'] ?? null;
  if ($p && ($p['TYPE'] ?? '') === 'btrfs' && $off === 64 * 512 + 65536) {
    $sb = str_repeat("\0", 4096); $sb = substr_replace($sb, hex2bin(str_replace('-', '', $p['UUID'])), 0x20, 16);
    $sb = substr_replace($sb, pack('P', 4711), 0x48, 8); $sb = substr_replace($sb, '_BHRfS_M', 0x40, 8);
    $sb = substr_replace($sb, pack('P', $p['btrfs'][0]), 0x88, 8); $sb = substr_replace($sb, pack('P', $p['btrfs'][1]), 0xC9, 8);
    $buf = substr($sb, 0, $len);
  }
  return $buf;
};
put(realpath("$W/sys/block/sdb")."/queue/rotational", "0\n");   // the main array's disk1 is an SSD
$cx = ctx($W, $blkidK, $readK);

/* ---- inventory ---- */
$inv = ma_d_inventory($cx);
same(array_column($inv['groups'], 'title'), ['Array 1 (main array)', 'Unassigned', 'Not usable'], 'groups: main array, unassigned, not usable');
$g = array_column($inv['groups'], null, 'id');
same(array_column($g['a1']['disks'], 'role_label'), ['Parity', 'Disk 1'], 'main array in slot order');
same(count($g['free']['disks']), 13, 'the 13 enclosure disks are unassigned');
same($g['unusable']['disks'][0]['role'], 'boot flash', 'the flash is not usable');
$row = function ($dev) use ($inv) { foreach ($inv['groups'] as $gr) foreach ($gr['disks'] as $d) if ($d['device'] === $dev) return $d; return null; };
same([$row('sda')['temp'], $row('sda')['smart']['state']], ['31 °C', 'healthy'], 'temperature and SMART health from Unraid\'s files');
same([$row('sdb')['temp'], $row('sdb')['smart']['state']], ['spun down', 'idle'], 'a spun-down disk: no temperature, SMART not judged');
same([$row('sdj')['smart']['state'], $row('sdj')['smart']['title']], ['error', 'Reallocated Sector Ct 8'], 'SMART error from a watched attribute');
same($row('sde')['smart']['state'], 'failed', 'SMART failed from the health line');
same($row('sdl')['bay'], 'QNAP TL-D1600S 1, HDD11 (cabling not confirmed)', 'bay from the profile, marked not confirmed');
same($row('sdl')['controller'], '0000:03:00.0 [1b21:1164]', 'controller PCI address and id');
same($row('sdl')['port'], 'ata22 (port 2)', 'ata port and its number on the controller');
same($row('sda')['bay'], '', 'a board disk has no bay');
$names = array_column($row('sdb')['names'], 1, 0);
same([$names['md devices'] ?? null, $names['Slot'] ?? null], ['md1p1, md1', 'disk1 (array)'], 'details: md devices and slot');
same([$row('sdb')['ssd'], strpos($row('sdb')['warning'], 'SSDs are strongly not recommended') === 0, $row('sda')['warning'], $row('sdh')['warning']], [true, true, '', ''], 'an SSD array disk is warned about; a hard disk and an unassigned SSD are not');
same(count($inv['units']), 1, 'one enclosure unit');
same(array_column(ma_d_find('HDD11', $cx), 'key'), [$row('sdl')['key']], 'search finds a bay name');
same(array_column(ma_d_find('ata1', $cx), 'key'), [$row('sda')['key']], 'search finds a port');
same(array_column(ma_d_find('kernel: ata22.00: failed command: READ FPDMA QUEUED', $cx), 'key'), [$row('sdl')['key']], 'search reads a log line');
/* cabling */
same(ma_d_cabling_save('0000:01:00.0', [3, 3, 1, 2], $cx)['ok'], false, 'cabling with a group twice is refused');
same(ma_d_cabling_save('0000:09:00.0', [1, 2, 3, 4], $cx)['ok'], false, 'cabling of an unknown enclosure is refused');
same(ma_d_cabling_save('0000:01:00.0', [3, 4, 1, 2], $cx)['ok'], true, 'cabling confirmed and saved');
same($row2 = (function () use ($cx) { foreach (ma_d_inventory($cx)['groups'] as $gr) foreach ($gr['disks'] as $d) if ($d['device'] === 'sdl') return $d['bay']; })(), 'QNAP TL-D1600S 1, HDD11', 'confirmed cabling drops the note');
@unlink("$W/plugcfg/enclosures.json");

/* ---- detection: evidence and found arrays ---- */
$READS = [];
$det = ma_d_detect($cx);
same($det['checked'], 13, '13 free disks checked (main array and flash left alone)');
same(count($det['arrays']), 1, 'one found array');
$a = $det['arrays'][0];
same($a['title'], 'No parity, 9 data disks', 'nine single-device btrfs data disks, no parity');
same(array_column($a['disks'], 'role'), ['disk1', 'disk2', 'disk3', 'disk4', 'disk5', 'disk6', 'disk7', 'disk8', 'disk9'], 'data slots suggested in device order');
check(strpos($a['evidence'][0], 'MBR on the smaller and GPT on the larger disks') !== false, 'evidence: same start sector, MBR and GPT by size: '.$a['evidence'][0]);
$oth = array_column($det['other'], null, 'device');
same(array_keys($oth), ['sdd', 'sdg', 'sdh', 'sdk'], 'the cleared disk and the three SSDs are not part of it');
same($oth['sdk']['kind'], 'blank', 'sdk: cleared, blank');
check(strpos($oth['sdk']['text'], 'Cleared by Unraid and blank') === 0, 'sdk says cleared and blank: '.$oth['sdk']['text']);
check(in_array('Partition table: MBR with Unraid\'s "cleared" mark (one entry of type 00 at sector 1, size ffffffff, nothing else).', $oth['sdk']['evidence'], true), 'sdk evidence names the cleared mark');
check(in_array('Blank: all 400 evenly spaced 64 KiB samples are zero (the partition table aside).', $oth['sdk']['evidence'], true), 'sdk evidence: 400 samples, all zero');
check(strpos($oth['sdh']['text'], 'no file system, blank: never used, not a parity disk') !== false, 'SSD: Unraid partition, blank, not parity');
$sdi = array_values(array_filter($a['disks'], function ($d) { return $d['device'] === 'sdi'; }))[0];
check((bool)array_filter($sdi['evidence'], function ($l) { return preg_match('/^Partition 1: btrfs, UUID [0-9a-f-]{36}, btrfs devices 1, devid 1, generation 4711\.$/', $l); }), 'btrfs evidence: UUID, devices, devid, generation');
check((bool)array_filter($sdi['evidence'], function ($l) { return strpos($l, 'Partition table: MBR; partition 1 from sector 64') === 0; }), 'evidence: partition table and start');
$sampled = array_count_values(array_map(function ($r) { return basename($r[0]); }, array_filter($READS, function ($r) { return $r[2] === MA_D_SAMPLE_LEN; })));
same([$sampled['sdk'] ?? 0, $sampled['sdh'] ?? 0, $sampled['sdi'] ?? 0], [400, 400, 0], 'the blank test samples disks without a file system only');
$noSample = ma_d_detect($cx, false);
same($noSample['arrays'][0]['title'], 'No parity, 9 data disks', 'without the blank test the found array is the same');
/* plan from the found array */
$roles = array_column($a['disks'], 'role', 'device');
$r = ma_d_prepare($roles, $cx, '2026.11.08e', $det);
check($r['ok'], 'prepare import: ok');
$d = $r['data'] ?? [];
same([$d['format'] ?? null, $d['detected'] ?? null, count($d['slots'] ?? []), $d['slots'][0]['role'] ?? null], [MA_X_FORMAT, true, 9, 'disk1'], 'plan in the export format, marked detected, 9 slots');
same(ma_x_validate($d, $cx), [], 'the plan passes the import file check');
same([$d['slots'][0]['partition']['start'], $d['slots'][0]['size_kb'] * 2 === $d['slots'][0]['partition']['size']], [64, true], 'slot geometry from the partition, as md records it');
$bad = ma_d_prepare(['sdi' => 'parity'] + $roles, $cx, '', $det);
check(!$bad['ok'] && preg_grep('/sdi has a file system/', $bad['reasons']), 'a disk with a file system is refused as parity');
$bad = ma_d_prepare(['sdh' => 'parity'] + $roles, $cx, '', $det);
check(!$bad['ok'] && preg_grep('/sdh is not a disk of a found array/', $bad['reasons']), 'a blank SSD is refused as parity');
$bad = ma_d_prepare(['sdi' => 'disk1', 'sdj' => 'disk1'], $cx, '', $det);
check(!$bad['ok'] && preg_grep('/disk1 is given to two disks/', $bad['reasons']), 'one slot for two disks is refused');
$bad = ma_d_prepare(['sda' => 'disk1'], $cx, '', $det);
check(!$bad['ok'] && preg_grep('/sda is not a disk of a found array/', $bad['reasons']), 'a disk of the main array is refused');
check(!array_filter($READS, function ($r) { return !is_string($r[0]); }), 'every disk access went through the read-only reader');

/* ================= P: images with real bytes: single parity, blank disk, XOR check ================= */
$W = "$HERE/tests/.work/disks-p"; exec('rm -rf '.escapeshellarg($W)); mkdir($W, 0755, true);
$S = 65536 * 2;   // 64 MiB in sectors
foreach (['sdx' => [1, 'D1'], 'sdy' => [2, 'D2'], 'sdz' => [3, 'P1'], 'sdw' => [4, 'BL']] as $dev => [$ata, $ser]) {
  disk($W, $dev, $ata, $S, 'TOSHIBA HDWD120', "TS-$ser");
  $f = fopen("$W/dev/$dev", 'w'); ftruncate($f, $S * 512);
  fseek($f, 446); fwrite($f, "\0\0\0\0\x83\0\0\0".pack('V', 64).pack('V', $S - 64)); fseek($f, 510); fwrite($f, "\x55\xaa"); fclose($f);
  copy("$W/dev/$dev", "$W/dev/{$dev}1.unused"); unlink("$W/dev/{$dev}1.unused");
  put("$W/dev/{$dev}1", '');   // the partition node: reads go through the whole disk node with the partition start
}
mt_srand(7);
$blocks = [];
foreach ([1, 5, 9, 17, 33, 50] as $mib) {
  $x = random_bytes(65536); $y = $mib % 2 ? random_bytes(65536) : str_repeat("\0", 65536);
  $blocks[$mib] = [$x, $y, $x ^ $y];
}
foreach (['sdx' => 0, 'sdy' => 1, 'sdz' => 2] as $dev => $j) {
  $f = fopen("$W/dev/$dev", 'r+');
  foreach ($blocks as $mib => $b) { fseek($f, 64 * 512 + $mib * 1048576); fwrite($f, $b[$j]); }
  fclose($f);
}
put("$W/var/local/emhttp/disks.ini", ''); put("$W/mounts", ''); put("$W/swaps", ''); put("$W/plugcfg/multiarray.cfg", "SERVICE=\"disable\"\n");
$devsP = []; $i = 0; foreach (['sdx', 'sdy', 'sdz', 'sdw'] as $dev) { $i++; foreach (glob("$W/dev/disk/by-id/ata-*") as $l) if (readlink($l) === "../../$dev") $devsP["dev$i"] = ['name' => "dev$i", 'device' => $dev, 'id' => substr(basename($l), 4)]; }
put("$W/var/local/emhttp/devs.ini", ini($devsP));
$blkidP = function ($node) { $b = basename($node); if (!preg_match('/\d$/', $b)) return ['PTTYPE' => 'dos']; return in_array($b, ['sdx1', 'sdy1']) ? ['TYPE' => 'xfs', 'UUID' => "uuid-$b"] : []; };
$readP = function ($node, $off, $len) { if (preg_match('/^(.*\/sd[a-z]+)1$/', $node, $m)) { $node = $m[1]; $off += 64 * 512; } return ma_d_readblock($node, $off, $len); };
$cp = ctx($W, $blkidP, $readP);
$sums = array_map('md5_file', glob("$W/dev/sd?"));
$det = ma_d_detect($cp);
same(count($det['arrays']), 1, 'P: one found array');
$a = $det['arrays'][0];
same([$a['title'], array_column($a['disks'], 'role', 'device')], ['Single parity, 2 data disks', ['sdz' => 'parity', 'sdx' => 'disk1', 'sdy' => 'disk2']], 'P: the disk with data and no file system is the parity candidate');
same(array_column($det['other'], 'kind', 'device'), ['sdw' => 'blank'], 'P: the empty disk is blank, not parity');
$sz = array_values(array_filter($a['disks'], function ($d) { return $d['device'] === 'sdz'; }))[0];
check((bool)preg_grep('/^Not blank: \d+ of 400 evenly spaced 64 KiB samples hold data/', $sz['evidence']), 'P: parity candidate evidence: not blank');
$pc = ma_d_parity_check('sdz', ['sdx', 'sdy'], $cp);
check($pc['ok'] && $pc['compared'] > 0 && $pc['matched'] === $pc['compared'], "P: parity matches the XOR of the data at every offset with data ({$pc['matched']}/{$pc['compared']})");
check(!array_filter($pc['rows'], function ($r) { return $r['disks'] !== 2; }), 'P: both data disks reach every compared offset');
$pc1 = ma_d_parity_check('sdz', ['sdx'], $cp);
check($pc1['ok'] && $pc1['matched'] < $pc1['compared'], "P: with a data disk missing, offsets do not match ({$pc1['matched']}/{$pc1['compared']})");
$f = fopen("$W/dev/sdz", 'r+'); fseek($f, 64 * 512 + 5 * 1048576); fwrite($f, random_bytes(65536)); fclose($f);
$pc2 = ma_d_parity_check('sdz', ['sdx', 'sdy'], $cp);
check($pc2['matched'] === $pc2['compared'] - 1, "P: one damaged block is one offset that does not match ({$pc2['matched']}/{$pc2['compared']})");
$pcw = ma_d_parity_check('sdz', ['sdx', 'sdy', 'sdw'], $cp);
check($pcw['silent'] === ['sdw'] && strpos($pcw['text'], 'sdw holds no data at the offsets that matched') !== false, 'P: an extra blank disk is named as saying nothing: '.$pcw['text']);
same(ma_d_parity_check('sdz', ['sdz'], $cp)['ok'], false, 'P: a disk as parity and data at once is refused');
same(ma_d_parity_check('sda', ['sdx'], $cp)['ok'], false, 'P: a disk that is not free is refused');
$rnoSsd = ma_d_prepare(['sdz' => 'parity', 'sdx' => 'disk1', 'sdy' => 'disk2'], $cp, '', $det);
check(!preg_grep('/SSD/', $rnoSsd['warnings']), 'P: no SSD warning for hard disks');
put(realpath("$W/sys/block/sdy")."/queue/rotational", "0\n");   // sdy is an SSD now
$detS = ma_d_detect($cp, false);
check((bool)preg_grep('/^An SSD: sdy\. SSDs are strongly not recommended as array disks/', $detS['arrays'][0]['notes']), 'P: an SSD in a found array gets the warning');
$r = ma_d_prepare(['sdz' => 'parity', 'sdx' => 'disk1', 'sdy' => 'disk2'], $cp, '', $det);
check($r['ok'] && preg_grep('/^An SSD in the plan: sdy \(disk2\)\. SSDs are strongly not recommended/', $r['warnings']), 'P: Prepare import warns about the SSD');
put(realpath("$W/sys/block/sdy")."/queue/rotational", "1\n");
check($r['ok'] && $r['data']['slots'][0]['role'] === 'parity' && $r['data']['slots'][0]['fs']['type'] === null, 'P: plan with the parity candidate as parity');
$bad = ma_d_prepare(['sdz' => 'parity', 'sdw' => 'parity2', 'sdx' => 'disk1'], $cp, '', $det);
check(!$bad['ok'] && preg_grep('/sdw/', $bad['reasons']), 'P: the blank disk is refused as parity 2');
/* twins: one data disk and its single parity carry the same bytes, so the same UUID */
$blkidT = function ($node) { $b = basename($node); if (!preg_match('/\d$/', $b)) return ['PTTYPE' => 'dos']; return in_array($b, ['sdx1', 'sdy1']) ? ['TYPE' => 'xfs', 'UUID' => 'same-uuid'] : []; };
$dt = ma_d_detect(ctx($W, $blkidT, $readP), false);
$t = array_values(array_filter($dt['arrays'], function ($x) { return $x['twins']; }));
check($t && array_column($t[0]['disks'], 'role', 'device') === ['sdx' => 'parity', 'sdy' => 'disk1'] || ($t && in_array('parity', array_column($t[0]['disks'], 'role'), true)), 'twins: same UUID on two disks, one suggested as parity');
/* nothing was written by detection, the checks or the plan (the one change is the test's own damaged block on sdz) */
$after = array_map('md5_file', glob("$W/dev/sd?"));
same([$after[0] === $sums[0], $after[1] === $sums[1], $after[2] === $sums[2]], [true, true, true], 'P: sdw, sdx, sdy unchanged by detection and checks');

echo "disks tests: ".($fail ? 'FAILED' : "all $n passed")."\n";
exit($fail);
