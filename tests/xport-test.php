<?php
/* Unit tests of include/xport.php on fixture trees (no server needed): export JSON shape, the refusal rules (virtual
 * disk, unclean array), the matcher (serial + size like md, free disks only) and the import target rules.
 * Run: php tests/xport-test.php   (exit 0 = all passed) */
require __DIR__.'/../src/usr/local/emhttp/plugins/multiarray/include/xport.php';
$fail = 0; $n = 0;
function check($cond, $what) { global $fail, $n; $n++; echo ($cond ? 'ok   ' : 'FAIL ').$what."\n"; if (!$cond) $fail = 1; }
function rmrf($d) { if (!file_exists($d) && !is_link($d)) return; if (is_dir($d) && !is_link($d)) { foreach (array_diff(scandir($d), ['.', '..']) as $f) rmrf("$d/$f"); rmdir($d); } else unlink($d); }
function put($f, $s) { @mkdir(dirname($f), 0755, true); file_put_contents($f, $s); }
function ini($a, $sections = false) {
  $o = '';
  foreach ($a as $k => $v) {
    if ($sections) { $o .= "[\"$k\"]\n"; foreach ($v as $kk => $vv) $o .= "$kk=\"$vv\"\n"; }
    else $o .= "$k=\"$v\"\n";
  }
  return $o;
}

/* a fake server: $disks = [dev => [sectors, model, vendor, serial, opts]] */
function world($disks, $opt = []) {
  $R = sys_get_temp_dir().'/ma-xport-'.getmypid().'-'.mt_rand(); mkdir($R, 0755, true);
  $ctl = $opt['ctl'] ?? '/devices/pci0000:00/0000:00:1f.2';
  @mkdir("$R/sys/block", 0755, true); @mkdir("$R/byid", 0755, true); @mkdir("$R/dev", 0755, true);
  $i = 0;
  foreach ($disks as $dev => $d) {
    [$sectors, $model, $vendor, $serial] = $d; $o = $d[4] ?? [];
    $c = $o['ctl'] ?? $ctl;
    $real = "$R/sys$c/ata$i/host$i/target$i:0:0/$i:0:0:0"; @mkdir($real, 0755, true); $i++;
    if (!is_link("$R/sys$c/driver")) symlink('../../bus/pci/drivers/'.($o['driver'] ?? 'ahci'), "$R/sys$c/driver");
    put("$real/model", "$model\n"); put("$real/vendor", "$vendor\n");
    $b = "$R/sys/block/$dev"; @mkdir("$b/queue", 0755, true); @mkdir("$b/holders", 0755, true);
    symlink($real, "$b/device");
    put("$b/size", "$sectors\n"); put("$b/queue/logical_block_size", ($o['lbs'] ?? 512)."\n"); put("$b/queue/physical_block_size", "4096\n"); put("$b/queue/rotational", "1\n");
    if (($o['part'] ?? true) !== false) {
      [$ps, $pz] = $o['part'] ?? [64, $sectors - 64];
      @mkdir("$b/{$dev}1/holders", 0755, true); put("$b/{$dev}1/start", "$ps\n"); put("$b/{$dev}1/size", "$pz\n");
      touch("$R/dev/{$dev}1");
    }
    foreach ($o['holders'] ?? [] as $h) touch("$b/{$dev}1/holders/$h");
    foreach ($o['byid'] ?? ['ata-'.str_replace(' ', '_', $model)."_$serial"] as $l) symlink("../../$dev", "$R/byid/$l");
  }
  return $R;
}
function ctx($R, $over = []) {
  return ma_x_ctx($over + ['sys' => "$R/sys", 'byid' => "$R/byid", 'dev' => "$R/dev", 'emhttp' => "$R/emhttp", 'boot' => "$R/boot",
    'plugcfg' => "$R/plugcfg", 'runroot' => "$R/run", 'proc' => "$R/proc", 'mounts' => "$R/mounts", 'swaps' => "$R/swaps",
    'version' => "$R/unraid-version", 'hostname' => 'Tower', 'now' => 1793900000, 'allow_virtual' => false,
    'zpools' => fn() => [], 'blkid' => function($node) { return ['TYPE' => 'xfs', 'UUID' => 'uuid-'.basename($node)]; }]);
}
/* array 1 on the fake server: slots [idx => [dev, id]] */
function array1($R, $slots, $var = [], $mdx = [], $diskx = []) {
  $md = "sbName=/boot/config/super.dat\nmdState=".($var['mdState'] ?? 'STARTED')."\n";
  $disks = [];
  for ($i = 0; $i <= 29; $i++) {
    [$dev, $id] = $slots[$i] ?? ['', ''];
    $size = $dev ? (int)(trim(file_get_contents("$R/sys/block/$dev/{$dev}1/size")) / 2) : 0;
    $md .= "diskId.$i=$id\nrdevName.$i=$dev\nrdevStatus.$i=".($mdx["rdevStatus.$i"] ?? ($dev ? 'DISK_OK' : ($i == 29 || $i == 0 ? 'DISK_NP_DSBL' : 'DISK_NP')))."\n";
    $md .= "diskSize.$i=$size\nrdevSize.$i=$size\nrdevOffset.$i=".($dev ? 64 : 0)."\nrdevId.$i=$id\n";
    $role = ma_x_role($i);
    if ($i <= 4 || $i == 29) $disks[$role] = ['idx' => $i, 'name' => $role, 'device' => $dev, 'id' => $id, 'idSb' => $id, 'type' => ($i == 0 || $i == 29) ? 'Parity' : 'Data',
      'status' => $dev ? 'DISK_OK' : 'DISK_NP'] + ($diskx[$role] ?? []) + ($i && $i != 29 && $dev ? ['fsType' => 'xfs', 'fsStatus' => 'Mounted'] : []);
  }
  $disks['flash'] = ['idx' => 30, 'name' => 'flash', 'device' => 'sdz', 'id' => 'Flash_X', 'type' => 'Flash'];
  put("$R/proc/mdstat", $md);
  put("$R/proc/devices", "Block devices:\n  8 sd\n  9 md\n251 md2\n");
  put("$R/emhttp/var.ini", ini($var + ['mdState' => 'STARTED', 'fsState' => 'Started', 'sbSynced' => '1793800000', 'sbSyncErrs' => '0', 'sbSyncExit' => '0',
    'mdResync' => '0', 'mdResyncPos' => '0', 'mdResyncAction' => 'check P', 'sbClean' => 'yes', 'luksKeyfile' => '/root/keyfile', 'csrf_token' => 'SECRETTOKEN']));
  put("$R/emhttp/disks.ini", ini($disks, true));
  put("$R/boot/disk.cfg", "md_num_stripes=\"1280\"\nspindownDelay=\"0\"\ndefaultFsType=\"xfs\"\nluksKeyfile=\"/root/keyfile\"\ndiskComment.1=\"private\"\n");
  put("$R/unraid-version", "version=\"7.3.4\"\n");
  put("$R/mounts", "/dev/sdz1 /boot vfat rw 0 0\n"); put("$R/swaps", "Filename Type\n");
}
$G = 32*1024*1024;   // 16 GiB in sectors

/* ===== export ===== */
$real = ['sda' => [$G, 'WDC WD40EFRX', 'ATA', 'WD-AAA1'], 'sdb' => [$G, 'WDC WD40EFRX', 'ATA', 'WD-BBB2'], 'sdc' => [$G, 'WDC WD40EFRX', 'ATA', 'WD-CCC3']];
$R = world($real); array1($R, [0 => ['sda', 'WDC_WD40EFRX_WD-AAA1'], 1 => ['sdb', 'WDC_WD40EFRX_WD-BBB2'], 2 => ['sdc', 'WDC_WD40EFRX_WD-CCC3']]);
$r = ma_x_export(1, ctx($R), '2026.11.08c');
check($r['ok'], 'clean array on real disks exports');
$d = $r['data'] ?? [];
check(($d['format'] ?? '') === MA_X_FORMAT && $d['format_version'] === 2 && $d['array'] === 1 && $d['server'] === 'Tower', 'JSON head: format, version, array, server');
check(count($d['slots'] ?? []) === 3 && array_column($d['slots'], 'role') === ['parity', 'disk1', 'disk2'], 'three slots in slot order; empty parity2 left out');
$s1 = $d['slots'][1] ?? [];
check($s1['serial'] === 'WD-BBB2' && $s1['id'] === 'WDC_WD40EFRX_WD-BBB2' && $s1['slot'] === 1, 'serial = text after the last _ of the id, as md compares');
check($s1['size_kb'] === intdiv($G - 64, 2) && $s1['sectors'] === $G && $s1['partition'] === ['start' => 64, 'size' => $G - 64], 'size as md stores it (1K) + sectors + partition');
check($s1['fs'] === ['type' => 'xfs', 'uuid' => 'uuid-sdb1'] && $s1['luks'] === ['encrypted' => false, 'uuid' => null], 'fs type + UUID, LUKS no');
check($d['slots'][0]['fs']['uuid'] === null, 'parity has no file system probe');
check($d['settings'] === ['defaultFsType' => 'xfs', 'md_num_stripes' => '1280', 'spindownDelay' => '0'], 'settings: only array keys (no keyfile path, no comments)');
$js = json_encode($d);
check(strpos($js, 'keyfile') === false && strpos($js, 'SECRETTOKEN') === false && strpos($js, 'private') === false, 'no key path, token or comment anywhere in the file');
check(ma_x_filename($d) === 'multiarray-export-Tower-array1-2026-11-05.json', 'file name multiarray-export-<server>-array<N>-<date>.json');
check($d['state']['parity_valid_since'] !== null && $d['warnings'] === [], 'parity valid time, no warnings');

/* warnings, not refusals */
array1($R, [0 => ['sda', 'WDC_WD40EFRX_WD-AAA1'], 1 => ['sdb', 'WDC_WD40EFRX_WD-BBB2']], ['sbSyncErrs' => '3']);
$r = ma_x_export(1, ctx($R)); check($r['ok'] && preg_grep('/found 3 errors/', $r['warnings']), 'last check with errors: exported with a warning');
array1($R, [0 => ['sda', 'WDC_WD40EFRX_WD-AAA1'], 1 => ['sdb', 'WDC_WD40EFRX_WD-BBB2']], ['mdState' => 'STOPPED', 'fsState' => 'Stopped']);
$r = ma_x_export(1, ctx($R)); check($r['ok'], 'STOPPED cleanly (sbClean=yes) exports');

/* unclean refusals */
$cases = [
  'a disabled disk' => [[], ['rdevStatus.1' => 'DISK_DSBL'], '/disk1 is not OK/'],
  'an invalid (emulated) disk' => [[], ['rdevStatus.1' => 'DISK_INVALID'], '/disk1 is not OK/'],
  'a missing disk' => [[], ['rdevStatus.1' => 'DISK_NP_MISSING'], '/disk1 is not OK/'],
  'a sync running' => [['mdResyncPos' => '1234'], [], '/running/'],
  'parity never valid' => [['sbSynced' => '0'], [], '/never valid/'],
  'an unclean stop' => [['mdState' => 'STOPPED', 'fsState' => 'Stopped', 'sbClean' => 'no'], [], '/not stopped cleanly/'],
  'a new array' => [['mdState' => 'NEW_ARRAY', 'fsState' => 'Stopped'], [], '/NEW_ARRAY/'],
  'a pending rebuild' => [['mdState' => 'STOPPED', 'fsState' => 'Stopped', 'mdResyncAction' => 'recon D1'], [], '/rebuild/'],
];
foreach ($cases as $what => [$var, $mdx, $re]) {
  array1($R, [0 => ['sda', 'WDC_WD40EFRX_WD-AAA1'], 1 => ['sdb', 'WDC_WD40EFRX_WD-BBB2']], $var, $mdx);
  $r = ma_x_export(1, ctx($R));
  check(!$r['ok'] && empty($r['why']) && preg_grep($re, $r['reasons']), "refused: $what");
}
array1($R, [0 => ['sda', 'WDC_WD40EFRX_WD-AAA1'], 1 => ['sdb', 'WDC_WD40EFRX_WD-BBB2']]);
check(ma_x_export(1, ctx($R))['ok'], 'empty parity2 (DISK_NP_DSBL, counted in mdNumDisabled) is not "disabled"');
$r = ma_x_export(3, ctx($R)); check(!$r['ok'] && preg_match('/not running/', $r['error']), 'refused: extra array whose instance is not running');
rmrf($R);

/* virtual refusals */
$virt = [
  'QEMU model' => [['sda' => [$G, 'QEMU HARDDISK', 'QEMU', 'QM00001'], 'sdb' => [$G, 'WDC WD40EFRX', 'ATA', 'WD-BBB2']], 'QEMU_HARDDISK_QM00001'],
  'VMware model' => [['sda' => [$G, 'Virtual disk', 'VMware', '6000c29'], 'sdb' => [$G, 'WDC WD40EFRX', 'ATA', 'WD-BBB2']], 'VMware_Virtual_disk_6000c29'],
  'VirtualBox model' => [['sda' => [$G, 'VBOX HARDDISK', 'ATA', 'VB1234'], 'sdb' => [$G, 'WDC WD40EFRX', 'ATA', 'WD-BBB2']], 'VBOX_HARDDISK_VB1234'],
  'Hyper-V model' => [['sda' => [$G, 'Virtual Disk', 'Msft', '600224'], 'sdb' => [$G, 'WDC WD40EFRX', 'ATA', 'WD-BBB2']], 'Msft_Virtual_Disk_600224'],
  'virtio by-id' => [['sda' => [$G, 'Disk', 'X', 'abc', ['byid' => ['virtio-abc']]], 'sdb' => [$G, 'WDC WD40EFRX', 'ATA', 'WD-BBB2']], 'Disk_abc'],
  'virtio controller' => [['sda' => [$G, 'WDC WD40EFRX', 'ATA', 'WD-AAA1', ['ctl' => '/devices/pci0000:00/0000:00:03.0/virtio0']], 'sdb' => [$G, 'WDC WD40EFRX', 'ATA', 'WD-BBB2']], 'WDC_WD40EFRX_WD-AAA1'],
  'pvscsi controller driver' => [['sda' => [$G, 'WDC WD40EFRX', 'ATA', 'WD-AAA1', ['ctl' => '/devices/pci0000:00/0000:00:10.0', 'driver' => 'vmw_pvscsi']], 'sdb' => [$G, 'WDC WD40EFRX', 'ATA', 'WD-BBB2']], 'WDC_WD40EFRX_WD-AAA1'],
  'no serial' => [['sda' => [$G, 'WDC WD40EFRX', 'ATA', ''], 'sdb' => [$G, 'WDC WD40EFRX', 'ATA', 'WD-BBB2']], 'WDC_WD40EFRX_'],
];
foreach ($virt as $what => [$disks, $id0]) {
  $R = world($disks); array1($R, [0 => ['sda', $id0], 1 => ['sdb', 'WDC_WD40EFRX_WD-BBB2']]);
  $r = ma_x_export(1, ctx($R));
  check(!$r['ok'] && !empty($r['why']) && $r['error'] === MA_X_WHY_SHORT && preg_grep('/^parity \(sda\)/', $r['reasons']), "refused with Why: $what");
  rmrf($R);
}
$R = world(['sda' => [$G, 'WDC WD40EFRX', 'ATA', 'WD-XEN1']]); array1($R, [1 => ['sda', 'WDC_WD40EFRX_WD-XEN1']], ['sbSynced' => '1']);
check(ma_x_export(1, ctx($R))['ok'], 'a serial that happens to contain "XEN" is not a virtual disk');
rmrf($R);
/* the test hook */
$R = world(['sda' => [$G, 'QEMU HARDDISK', 'QEMU', 'Q1'], 'sdb' => [$G, 'QEMU HARDDISK', 'QEMU', 'Q2']]);
array1($R, [0 => ['sda', 'QEMU_HARDDISK_Q1'], 1 => ['sdb', 'QEMU_HARDDISK_Q2']]);
$r = ma_x_export(1, ctx($R, ['allow_virtual' => true]));
check($r['ok'] && !empty($r['data']['test_virtual_allowed']) && preg_grep('/TEST HOOK/', $r['warnings']), 'test hook: virtual export allowed and marked as a test file');
check(!ma_x_validate($r['data'], ctx($R, ['allow_virtual' => true])) && preg_grep('/test hook/', ma_x_validate($r['data'], ctx($R))), 'import refuses a test-hook file unless the hook is on');
rmrf($R);

/* ===== import: matcher and target ===== */
function source_export($disks, $slots) {
  $R = world($disks); array1($R, $slots);
  $r = ma_x_export(1, ctx($R)); rmrf($R); return $r['data'];
}
$src = ['sda' => [$G, 'WDC WD40EFRX', 'ATA', 'WD-AAA1'], 'sdb' => [$G, 'WDC WD40EFRX', 'ATA', 'WD-BBB2'], 'sdc' => [$G, 'WDC WD40EFRX', 'ATA', 'WD-CCC3']];
$exp = source_export($src, [0 => ['sda', 'WDC_WD40EFRX_WD-AAA1'], 1 => ['sdb', 'WDC_WD40EFRX_WD-BBB2'], 3 => ['sdc', 'WDC_WD40EFRX_WD-CCC3']]);
check($exp && array_column($exp['slots'], 'slot') === [0, 1, 3], 'source export with a gap (disk3, no disk2) keeps slot numbers');

/* target server: main array busy on sdx; extra array 2 fresh and stopped; the three source disks under new names */
function target($disks, $opt = []) {
  $R = world($disks + ['sdx' => [$GLOBALS['G'], 'ST4000', 'ATA', 'ZZZ9']]);
  array1($R, [1 => ['sdx', 'ST4000_ZZZ9']]);
  $devs = []; $k = 0;
  foreach ($disks as $dev => $d) if (!in_array($dev, $opt['hide'] ?? [])) $devs['dev'.(++$k)] = ['name' => "dev$k", 'id' => ($opt['idfmt'] ?? fn($d) => str_replace(' ', '_', $d[1]).'_'.$d[3])($d), 'device' => $dev, 'sectors' => $d[0]];
  $disks2 = [];
  foreach (['parity' => 0, 'disk1' => 1, 'disk2' => 2, 'disk3' => 3, 'parity2' => 29] as $role => $i) $disks2[$role] = array_merge(['idx' => $i, 'name' => $role, 'device' => '', 'id' => '', 'idSb' => '', 'type' => ($i == 0 || $i == 29) ? 'Parity' : 'Data'], $opt['assigned'][$role] ?? []);
  $disks2['flash'] = ['idx' => 30, 'device' => 'sdz', 'id' => 'Flash_X', 'type' => 'Flash'];
  put("$R/run/a2/ns.id", "mnt:[1]\n");
  put("$R/run/a2/state/var.ini", ini(['mdState' => 'NEW_ARRAY', 'fsState' => $opt['fsState'] ?? 'Stopped', 'SYS_ARRAY_SLOTS' => '4']));
  put("$R/run/a2/state/disks.ini", ini($disks2, true));
  put("$R/run/a2/state/devs.ini", ini($devs, true));
  put("$R/plugcfg/multiarray.cfg", "SERVICE=\"enable\"\nARRAYS=\"3\"\nDISKS2=\"".($opt['pin2'] ?? '')."\"\nDISKS3=\"".($opt['pin3'] ?? '')."\"\n");
  if (!empty($opt['mount'])) file_put_contents("$R/mounts", "/dev/{$opt['mount']}1 /mnt/disks/x xfs rw 0 0\n", FILE_APPEND);
  return $R;
}
$tgt = ['sdd' => $src['sdc'], 'sde' => $src['sda'], 'sdf' => $src['sdb']];
$R = target($tgt);
$p = ma_x_plan($exp, 2, ctx($R));
check($p['ok'] && !$p['problems'], 'plan: every slot finds its disk');
$map = []; foreach ($p['rows'] as $r) $map[$r['role']] = $r['disk']['device'];
check($map === ['parity' => 'sde', 'disk1' => 'sdf', 'disk3' => 'sdd'], 'matched by serial + size, not by device name');
check($p['max_data_slot'] === 3 && $p['rows'][0]['disk']['id'] === 'WDC_WD40EFRX_WD-AAA1', 'slot count needed and the target emhttpd\'s own id string');
check(ma_x_plan_hash($p) === ma_x_plan_hash(ma_x_plan($exp, 2, ctx($R))) && strlen(ma_x_plan_hash($p)) === 16, 'confirm code is stable');
check(!ma_x_plan($exp, 1, ctx($R))['ok'] && preg_grep('/disk1 already has a disk/', ma_x_plan($exp, 1, ctx($R))['problems']), 'main array with assignments is not a target');
rmrf($R);
/* the target emhttpd writes the id without the model (seen in extra-array instances): md compares the serial only */
$R = target($tgt, ['idfmt' => fn($d) => $d[3]]);
check(ma_x_plan($exp, 2, ctx($R))['ok'], 'id string differs before the last _ (model): still the same disk, as in md');
rmrf($R);

$bad = [
  'a disk missing' => [array_diff_key($tgt, ['sdf' => 1]), [], '/disk1: no free disk with serial WD-BBB2/'],
  'serial mismatch' => [['sdd' => $src['sdc'], 'sde' => $src['sda'], 'sdf' => [$G, 'WDC WD40EFRX', 'ATA', 'WD-OTHER']], [], '/disk1: no free disk/'],
  'size mismatch' => [['sdd' => $src['sdc'], 'sde' => $src['sda'], 'sdf' => [$G * 2, 'WDC WD40EFRX', 'ATA', 'WD-BBB2']], [], '/disk1: .*size differs/'],
  'partition mismatch' => [['sdd' => $src['sdc'], 'sde' => $src['sda'], 'sdf' => [$G, 'WDC WD40EFRX', 'ATA', 'WD-BBB2', ['part' => [2048, $G - 2048]]]], [], '/disk1: .*partition 1 differs/'],
  'sector size mismatch' => [['sdd' => $src['sdc'], 'sde' => $src['sda'], 'sdf' => [$G, 'WDC WD40EFRX', 'ATA', 'WD-BBB2', ['lbs' => 4096]]], [], '/disk1: .*sector size differs/'],
  'two disks with the serial' => [$tgt + ['sdg' => [$G, 'WDC WD40EFRX', 'ATA', 'WD-BBB2', ['byid' => ['ata-WDC_WD40EFRX_WD-BBB2-dup']]]], [], '/disk1: more than one free disk/'],
  'disk mounted (Unassigned Devices)' => [$tgt, ['mount' => 'sdf'], '/disk1: .*mounted/'],
  'disk held by dm' => [['sdd' => $src['sdc'], 'sde' => $src['sda'], 'sdf' => [$G, 'WDC WD40EFRX', 'ATA', 'WD-BBB2', ['holders' => ['dm-0']]]], [], '/disk1: .*held by dm-0/'],
  'disk pinned to array 3' => [$tgt, ['pin3' => 'ata-WDC_WD40EFRX_WD-BBB2'], '/disk1: .*pinned to array 3/'],
  'target array started' => [$tgt, ['fsState' => 'Started'], '/array 2 is not stopped/'],
  'target has an assignment' => [$tgt, ['assigned' => ['disk2' => ['id' => 'X_Y', 'idSb' => 'X_Y']]], '/disk2 already has a disk assigned/'],
  'target has pinned disks' => [$tgt, ['pin2' => 'ata-foo'], '/already has pinned disks/'],
];
foreach ($bad as $what => [$disks, $opt, $re]) {
  $R = target($disks, $opt); $p = ma_x_plan($exp, 2, ctx($R));
  check(!$p['ok'] && preg_grep($re, $p['problems']), "import refused: $what");
  rmrf($R);
}
/* a disk of the main array with the wanted serial is never offered */
$R = target($tgt); array1($R, [1 => ['sdf', 'WDC_WD40EFRX_WD-BBB2']]);
$p = ma_x_plan($exp, 2, ctx($R)); check(!$p['ok'] && preg_grep('/disk1: .*main array/', $p['problems']), 'import refused: the disk is in the main array');
rmrf($R);
$R = target($tgt);
check(!ma_x_plan($exp, 5, ctx($R))['ok'], 'import refused: target instance not running');
/* file validation */
$e = $exp; $e['format'] = 'other'; check(preg_grep('/not a Multi Arrays/', ma_x_validate($e, ctx($R))), 'validate: wrong format');
$e = $exp; $e['format_version'] = 3; check(preg_grep('/format version/', ma_x_validate($e, ctx($R))), 'validate: newer format version');
$e = $exp; $e['slots'][1]['role'] = 'parity'; check(preg_grep('/expected disk1/', ma_x_validate($e, ctx($R))), 'validate: a data disk is never parity');
$e = $exp; $e['slots'][1]['serial'] = 'X'; check(preg_grep('/serial and id do not agree/', ma_x_validate($e, ctx($R))), 'validate: serial must come from the id');
$e = $exp; $e['slots'][] = $e['slots'][0]; check(preg_grep('/listed twice/', ma_x_validate($e, ctx($R))), 'validate: slot twice');
check(ma_x_validate('x', ctx($R)) === ['the file is not JSON from a Multi Arrays export'], 'validate: not JSON');
rmrf($R);

/* ===== F12: zfs pool names, wiped slots, pools and shares in the export; zfs clash and pool notes in the plan ===== */
$disksF = ['sda' => [$G, 'WDC WD40EFRX', 'ATA', 'WD-AAA1'], 'sdb' => [$G, 'WDC WD40EFRX', 'ATA', 'WD-BBB2'], 'sdc' => [$G, 'WDC WD40EFRX', 'ATA', 'WD-CCC3'],
           'sdd' => [$G, 'Samsung SSD', 'ATA', 'S-PPP1'], 'sde' => [$G, 'Samsung SSD', 'ATA', 'S-PPP2']];
$probe = ['sdb1' => ['TYPE' => 'zfs_member', 'LABEL' => 'disk1', 'UUID' => '111'], 'sdc1' => [], 'sdd1' => ['TYPE' => 'btrfs', 'UUID' => 'b-1'], 'sde1' => ['TYPE' => 'btrfs', 'UUID' => 'b-1']];
$R = world($disksF);
array1($R, [0 => ['sda', 'WDC_WD40EFRX_WD-AAA1'], 1 => ['sdb', 'WDC_WD40EFRX_WD-BBB2'], 2 => ['sdc', 'WDC_WD40EFRX_WD-CCC3']], [], [],
  ['disk1' => ['fsType' => 'zfs'], 'disk2' => ['fsType' => 'zfs']]);
$di = parse_ini_file("$R/emhttp/disks.ini", true);
$di['cache'] = ['idx' => 31, 'name' => 'cache', 'device' => 'sdd', 'id' => 'Samsung_SSD_S-PPP1', 'type' => 'Cache', 'fsType' => 'btrfs', 'fsProfile' => 'raid1', 'size' => '16777184', 'slots' => '2'];
$di['cache2'] = ['idx' => 32, 'name' => 'cache2', 'device' => 'sde', 'id' => 'Samsung_SSD_S-PPP2', 'type' => 'Cache', 'fsType' => 'btrfs', 'size' => '16777184'];
put("$R/emhttp/disks.ini", ini($di, true));
put("$R/boot/pools/cache.cfg", "diskSlots=\"2\"\ndiskFsType=\"btrfs\"\n");
put("$R/boot/shares/media.cfg", "shareUseCache=\"yes\"\nshareCachePool=\"cache\"\n");
$c = ctx($R, ['blkid' => function($node) use ($probe) { return $probe[basename($node)] ?? []; }]);
$r = ma_x_export(1, $c); $d = $r['data'] ?? [];
$sl = []; foreach ($d['slots'] ?? [] as $s) $sl[$s['role']] = $s;
check(($sl['disk1']['fs'] ?? null) === ['type' => 'zfs', 'uuid' => '111', 'pool' => 'disk1'], 'export: zfs_member is written as zfs, with its pool name');
check(($sl['disk2']['fs'] ?? null) === ['type' => null, 'uuid' => null] && preg_grep('/disk2: no file system found.*says zfs/', $r['warnings'] ?? []), 'export: a slot whose setting says zfs but holds no file system is exported without one, with a warning');
$pl = $d['pools'][0] ?? [];
check(count($d['pools'] ?? []) === 1 && $pl['name'] === 'cache' && $pl['fs'] === 'btrfs' && $pl['profile'] === 'raid1' && $pl['slots'] === 2
  && array_column($pl['devices'], 'member') === ['cache', 'cache2'] && $pl['devices'][1]['serial'] === 'S-PPP2' && $pl['settings']['diskFsType'] === 'btrfs', 'export: the pool with its members, profile and settings file');
check(($d['shares'] ?? null) === [['name' => 'media', 'settings' => ['shareUseCache' => 'yes', 'shareCachePool' => 'cache']]], 'export: share settings files');
rmrf($R);
$expF = $d;
/* plan into array 2: pool disks found and noted, share noted; then a zfs name clash */
$R = target(['sdk' => $disksF['sda'], 'sdl' => $disksF['sdb'], 'sdm' => $disksF['sdc'], 'sdn' => $disksF['sdd'], 'sdo' => $disksF['sde']]);
$p = ma_x_plan($expF, 2, ctx($R));
check($p['ok'] && ($p['pools'][0]['found'][1]['device'] ?? '') === 'sdo' && preg_grep('/pool cache \(btrfs raid1\): not created by the import.*Add Pool.*do not format/', $p['notes'])
  && preg_grep('/shares media: .*written into array 2/', $p['notes']), 'plan: pool members found by serial, with the steps to add the pool; share noted');
check(ma_x_plan_hash($p) !== ma_x_plan_hash(['target' => 2, 'rows' => $p['rows']]), 'confirm code covers the pool disks too');
$p = ma_x_plan($expF, 2, ctx($R, ['zpools' => fn() => ['disk1']]));
check(!$p['ok'] && preg_grep('/disk1: its zfs pool name "disk1" is already used by a pool that is imported.*slot\'s name.*zpool list/', $p['problems']), 'plan: a zfs pool name already imported is refused with the reason and what to do');
$a3 = "$R/run/a3/state/disks.ini"; put($a3, ini(['disk1' => ['idx' => 1, 'type' => 'Data', 'id' => 'X_1', 'fsType' => 'luks:zfs']], true));
$p = ma_x_plan($expF, 2, ctx($R));
check(!$p['ok'] && preg_grep('/"disk1" is already used by array 3/', $p['problems']), 'plan: a zfs pool name another array is set up to use is refused (before Start would)');
rmrf($R);
/* version 1 files: still read; a zfs slot without a recorded pool name stands for its slot name */
$v1 = $expF; $v1['format_version'] = 1; unset($v1['pools'], $v1['shares']); unset($v1['slots'][1]['fs']['pool']); $v1['slots'][1]['fs']['type'] = 'zfs_member';
$R = target(['sdk' => $disksF['sda'], 'sdl' => $disksF['sdb'], 'sdm' => $disksF['sdc']]);
check(!ma_x_validate($v1, ctx($R)) && ma_x_plan($v1, 2, ctx($R))['ok'], 'a version 1 export still imports');
check(!ma_x_plan($v1, 2, ctx($R, ['zpools' => fn() => ['disk1']]))['ok'], 'version 1: a zfs slot (zfs_member) is checked under its slot name');
rmrf($R);
/* shares are written into an extra array's own folder, never over an existing file */
$T = sys_get_temp_dir().'/ma-sh-'.getmypid(); put("$T/keep.cfg", "x=\"1\"\n");
$l = ma_x_write_shares(['shares' => [['name' => 'media', 'settings' => ['shareUseCache' => 'yes']], ['name' => 'keep', 'settings' => ['x' => '2']], ['name' => '../evil', 'settings' => []]]], $T);
check(file_get_contents("$T/media.cfg") === "shareUseCache=\"yes\"\n" && file_get_contents("$T/keep.cfg") === "x=\"1\"\n" && !file_exists(dirname($T).'/evil.cfg') && count($l) === 3, 'shares: new ones written, existing kept, odd names refused');
rmrf($T);

echo $fail ? "xport tests FAILED\n" : "xport tests: all $n passed\n";
exit($fail);
