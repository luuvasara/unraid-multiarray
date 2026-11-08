<?PHP
/* Disks tab library: every physical disk, grouped by where it belongs (array 1, the extra arrays, pools, unassigned, not
 * usable), with the kit's drive map record, temperature and SMART health from Unraid's own state files, controller,
 * port and enclosure bay. Plus "found arrays": what is on the free disks, read-only, and an import plan made from it.
 *
 * Nothing here writes to a disk or mounts anything. Temperatures and SMART come from the files emhttpd already wrote
 * (disks.ini, devs.ini, smart/<name>), so no sleeping disk is woken. Detection (ma_d_detect) does read the start of free
 * disks (blkid -p and, for btrfs, the 4 KiB superblock at 64 KiB), which wakes a sleeping free disk; the page says so
 * before it runs. All paths come from a context array so tests/disks-test.php can run on fixture trees. */

require_once __DIR__.'/xport.php';
if (!class_exists('DriveMap')) require_once __DIR__.'/../kit/DriveMap.php';          // the kit's libraries, in the package
if (!class_exists('DriveEnclosures')) require_once __DIR__.'/../kit/Enclosure.php';

/* SMART attributes the stock dashboard watches by default (Preselect.php): raw value above 0 = error */
const MA_D_SMART_CODES = ['5', '187', '197', '198', '199'];
/* partition 1 start sectors emhttpd uses: "MBR: unaligned", "MBR/GPT: 4KiB-aligned", "MBR/GPT: 1MiB-aligned" */
const MA_D_STARTS = [63 => 'MBR, unaligned (older releases)', 64 => '4 KiB aligned', 2048 => '1 MiB aligned'];
/* what an array data disk carries on its partition */
const MA_D_DATA_FS = ['xfs', 'btrfs', 'zfs_member', 'crypto_LUKS', 'reiserfs'];
/* shown wherever an SSD is or would be an array disk */
const MA_D_SSD_WARNING = 'SSDs are strongly not recommended as array disks: Unraid does not TRIM disks in an array, so the SSD slows down over time, and an SSD\'s own garbage collection may make parity wrong. Use a pool for SSDs.';

function ma_d_ctx($over = []) {
  /* DRIVEMAP_ROOT (the kit's switch for a captured tree, set only by the render test) moves every path below it */
  $r = rtrim((string)getenv('DRIVEMAP_ROOT'), '/');
  if ($r !== '') $over += ['sys' => "$r/sys", 'byid' => "$r/dev/disk/by-id", 'dev' => "$r/dev", 'emhttp' => "$r/var/local/emhttp", 'plugcfg' => "$r/plugcfg",
    'runroot' => "$r/run", 'mounts' => "$r/mounts", 'swaps' => "$r/swaps", 'hidden' => '', 'dmroot' => $r,
    'blkid' => function ($node) use ($r) { $j = json_decode((string)@file_get_contents("$r/cmd/blkid.json"), true); $b = $j[substr($node, strlen($r))] ?? []; return array_filter($b, 'is_string'); },
    'readblock' => function ($node, $off, $len) { return str_repeat("\0", $len); }];
  return ma_x_ctx($over + ['dmroot' => '', 'encdir' => null, 'readblock' => 'ma_d_readblock', 'hidden' => '/tmp/emhttp/dev',
    'smart' => ($r !== '' ? "$r/var/local/emhttp/smart" : '/var/local/emhttp/smart'), 'monitor' => ($r !== '' ? "$r/var/local/emhttp/monitor.ini" : '/var/local/emhttp/monitor.ini')]);
}

/* read-only: $len bytes at $off of a block node (fopen 'rb' = O_RDONLY) */
function ma_d_readblock($node, $off, $len) {
  if (!is_string($node) || !preg_match('#^/[A-Za-z0-9/_.-]+$#', $node)) return '';
  $f = @fopen($node, 'rb'); if (!$f) return '';
  $s = (@fseek($f, $off) === 0) ? (string)@fread($f, $len) : '';
  fclose($f);
  return $s;
}

function ma_d_map($ctx) { return new DriveMap(['root' => $ctx['dmroot'], 'cache' => $ctx['dmroot'] === '' ? '/var/run/drivemap' : null]); }
function ma_d_enc($ctx) { return new DriveEnclosures(['root' => $ctx['dmroot'], 'cabling' => ma_d_cabling($ctx)] + ($ctx['encdir'] ? ['dir' => $ctx['encdir']] : [])); }

/* the cabling of each enclosure unit as the user confirmed it: plugcfg/enclosures.json {"<switch address>": [group, ...]} */
function ma_d_cabling($ctx) { $j = json_decode((string)@file_get_contents($ctx['plugcfg'].'/enclosures.json'), true); return is_array($j) ? $j : []; }
function ma_d_cabling_save($switch, $order, $ctx = null) {
  $ctx = $ctx ?: ma_d_ctx();
  $enc = ma_d_enc($ctx);
  $unit = null;
  foreach ($enc->units([]) as $u) if ($u['switch'] === $switch) $unit = $u;
  if (!$unit) return ['ok' => false, 'error' => 'No such enclosure on this server.'];
  $prof = $enc->profiles()[$unit['profile']];
  if (!DriveEnclosures::validCabling($prof, $order)) return ['ok' => false, 'error' => 'Each controller needs a different group of bays.'];
  $all = ma_d_cabling($ctx); $all[$switch] = array_map('intval', array_values($order));
  $f = $ctx['plugcfg'].'/enclosures.json';
  if (@file_put_contents("$f.new", json_encode($all, JSON_PRETTY_PRINT)."\n") === false || !@rename("$f.new", $f)) return ['ok' => false, 'error' => 'The setting could not be saved.'];
  return ['ok' => true];
}

function ma_d_role_label($role) {
  if ($role === 'parity') return 'Parity';
  if ($role === 'parity2') return 'Parity 2';
  if (preg_match('/^disk(\d+)$/', $role, $m)) return "Disk {$m[1]}";
  if ($role === 'pinned') return 'pinned, not assigned';
  return $role;
}
function ma_d_rank($role, $idx = null) {
  if ($role === 'parity') return 0;
  if ($role === 'parity2') return 1;
  if (preg_match('/^disk(\d+)$/', $role, $m)) return 1 + (int)$m[1];
  return 100 + (int)$idx;
}
function ma_d_size($b) {
  if ($b <= 0) return '';
  foreach (['TB' => 1e12, 'GB' => 1e9, 'MB' => 1e6] as $u => $f) if ($b >= $f) return rtrim(rtrim(number_format($b / $f, 1, '.', ''), '0'), '.')." $u";
  return "$b B";
}

/* SMART health the way the stock dashboard judges it, from the file emhttpd keeps (never by asking the disk) */
function ma_d_smart($ctx, $name, $spundown) {
  if ($name === '') return ['state' => 'unknown', 'text' => 'unknown', 'title' => 'Unraid keeps no SMART report for this disk'];
  if ($spundown) return ['state' => 'idle', 'text' => '-', 'title' => 'Spun down: not read, so it is not woken'];
  $f = $ctx['smart']."/$name";
  $s = @file_get_contents($f);
  if ($s === false || trim($s) === '') return ['state' => 'unknown', 'text' => 'unknown', 'title' => 'No SMART report yet'];
  if (preg_match('/^SMART.+: ([A-Z]+)/m', $s, $m) && in_array($m[1], ['FAILED', 'NOK'])) return ['state' => 'failed', 'text' => 'failed', 'title' => 'SMART health check failed'];
  $mon = ma_x_ini($ctx['monitor'], true);
  if (!empty($mon['smart'][str_replace('~', '', $name).'.ack'])) return ['state' => 'healthy', 'text' => 'healthy', 'title' => 'Errors acknowledged on the Dashboard'];
  $bad = [];
  foreach (array_slice(preg_split('/\n/', $s), 7) as $l) {
    $c = preg_split('/\s+/', trim($l));
    if (count($c) < 10 || !ctype_digit($c[0])) continue;
    $failing = strpos($c[8], 'FAILING_NOW') !== false;
    if ($failing || (in_array($c[0], MA_D_SMART_CODES, true) && (int)$c[9] > 0)) $bad[] = str_replace('_', ' ', $c[1]).' '.($failing ? $c[8] : $c[9]);
  }
  return $bad ? ['state' => 'error', 'text' => 'error', 'title' => implode('; ', $bad)] : ['state' => 'healthy', 'text' => 'healthy', 'title' => 'No errors reported'];
}

/* which array, pool or list each device belongs to: [device => [group, role, idx, ini row, host name]] and the groups */
function ma_d_owners($ctx) {
  $own = []; $groups = [];
  $g = function ($id, $title, $order, $note = '') use (&$groups) { if (!isset($groups[$id])) $groups[$id] = ['id' => $id, 'title' => $title, 'order' => $order, 'note' => $note]; };
  $g('a1', 'Array 1 (main array)', 10);
  $host = ma_x_ini($ctx['emhttp'].'/disks.ini', true);
  $hostName = [];
  foreach ($host as $k => $d) {
    $dev = (string)($d['device'] ?? ''); if ($dev === '') continue;
    $name = (string)($d['name'] ?? $k); $hostName[$dev] = $name; $type = (string)($d['type'] ?? '');
    if ($type === 'Flash' || $k === 'flash') { $g('unusable', 'Not usable', 95, 'The boot flash, disks mounted or held by something else.'); $own[$dev] = ['group' => 'unusable', 'role' => 'boot flash', 'idx' => 0, 'ini' => $d]; }
    elseif ($type === 'Parity' || $type === 'Data') $own[$dev] = ['group' => 'a1', 'role' => $name, 'idx' => (int)($d['idx'] ?? 0), 'ini' => $d];
    else { $pool = preg_replace('/(\d+$|~.*$)/', '', $name) ?: $name; $g("pool:$pool", "Pool $pool", 50); $own[$dev] = ['group' => "pool:$pool", 'role' => $name, 'idx' => (int)($d['idx'] ?? 0), 'ini' => $d]; }
  }
  $devs = ma_x_ini($ctx['emhttp'].'/devs.ini', true);
  $hostDevs = [];
  foreach ($devs as $k => $d) if (!empty($d['device'])) { $hostDevs[$d['device']] = $d; $hostName[$d['device']] = $hostName[$d['device']] ?? (string)($d['name'] ?? $k); }
  $cfg = ma_x_ini($ctx['plugcfg'].'/multiarray.cfg');
  $arrays = max(1, min(9, (int)($cfg['ARRAYS'] ?? 3)));
  $on = ($cfg['SERVICE'] ?? 'disable') === 'enable';
  for ($n = 2; $n <= 9; $n++) {
    $up = ma_x_instance_up($ctx, $n);
    if (!$up) continue;
    $g("a$n", "Array $n", 10 + $n);
    foreach (ma_x_ini($ctx['runroot']."/a$n/state/disks.ini", true) as $k => $d) {
      $dev = (string)($d['device'] ?? ''); $type = (string)($d['type'] ?? '');
      if ($dev === '' || $type === 'Flash' || $k === 'flash' || isset($own[$dev])) continue;
      if ($type === 'Parity' || $type === 'Data') $own[$dev] = ['group' => "a$n", 'role' => (string)($d['name'] ?? $k), 'idx' => (int)($d['idx'] ?? 0), 'ini' => $d];
      else { $pool = preg_replace('/(\d+$|~.*$)/', '', (string)($d['name'] ?? $k)); $g("a$n-pool:$pool", "Array $n, pool $pool", 60 + $n); $own[$dev] = ['group' => "a$n-pool:$pool", 'role' => (string)($d['name'] ?? $k), 'idx' => (int)($d['idx'] ?? 0), 'ini' => $d]; }
    }
  }
  $pinned = [];
  foreach ($cfg as $k => $v) if (preg_match('/^DISKS([2-9])$/', $k, $m))
    foreach (preg_split('/\s+/', trim($v), -1, PREG_SPLIT_NO_EMPTY) as $name) {
      if (strpbrk($name, '/') !== false) continue;
      $r = @readlink($ctx['byid']."/$name"); if (!$r) continue;
      $dev = basename($r); $n = (int)$m[1]; $pinned[$dev] = $n;
      if (isset($own[$dev])) continue;
      $up = ma_x_instance_up($ctx, $n);
      $g("a$n", $up ? "Array $n" : "Array $n (not running)", 10 + $n, $up ? '' : "Its management instance is not running; the disks pinned to it are listed here.");
      $own[$dev] = ['group' => "a$n", 'role' => 'pinned', 'idx' => 99, 'ini' => []];
    }
  $mounted = [];
  foreach ([$ctx['mounts'], $ctx['swaps']] as $f) foreach (preg_split('/\n/', (string)@file_get_contents($f)) as $l)
    if (preg_match('#^/dev/(sd[a-z]+|hd[a-z]+|vd[a-z]+|nvme\d+n\d+)(p?\d+)?\s#', $l, $m)) $mounted[$m[1]] = true;
  return ['own' => $own, 'groups' => $groups, 'host_name' => $hostName, 'host_devs' => $hostDevs, 'mounted' => $mounted, 'pinned' => $pinned,
          'service' => $on, 'arrays' => $arrays];
}

/* every disk with everything the tab shows: ['groups' => [group + 'disks' => [row, ...]], 'units' => enclosures] */
function ma_d_inventory($ctx = null) {
  $ctx = $ctx ?: ma_d_ctx();
  $map = ma_d_map($ctx); $drives = $map->drives();
  $enc = ma_d_enc($ctx); $places = $enc->place($drives);
  $o = ma_d_owners($ctx);
  $groups = $o['groups'];
  $rows = [];
  foreach ($drives as $d) {
    $dev = $d['device'];
    if (!preg_match('/^(sd[a-z]+|hd[a-z]+|vd[a-z]+|nvme\d+n\d+|mmcblk\d+)$/', $dev)) continue;
    $info = ma_x_devinfo($ctx, $dev);
    if (isset($o['own'][$dev])) $w = $o['own'][$dev];
    else {
      $why = [];
      if (isset($o['mounted'][$dev])) $why[] = 'mounted (Unassigned Devices or by hand)';
      if ($info && $info['holders']) $why[] = 'held by '.implode(', ', $info['holders']);
      if ($why) { $groups['unusable'] = $groups['unusable'] ?? ['id' => 'unusable', 'title' => 'Not usable', 'order' => 95, 'note' => 'The boot flash, disks mounted or held by something else.']; $w = ['group' => 'unusable', 'role' => implode('; ', $why), 'idx' => 0, 'ini' => []]; }
      else { $groups['free'] = $groups['free'] ?? ['id' => 'free', 'title' => 'Unassigned', 'order' => 90, 'note' => 'In no array and no pool.']; $w = ['group' => 'free', 'role' => 'unassigned', 'idx' => 0, 'ini' => []]; }
    }
    $ini = $w['ini'] + ($o['host_devs'][$dev] ?? []);
    $temp = (string)($ini['temp'] ?? '*');
    $spun = ((string)($ini['spundown'] ?? '0') === '1') || strpos((string)($ini['color'] ?? ''), 'blink') !== false;
    $hostName = $o['host_name'][$dev] ?? '';
    $pci = $d['pci']; $drv = $pci !== '' ? basename((string)@readlink(($ctx['dmroot'] ?: '')."/sys/bus/pci/devices/$pci/driver")) : '';
    $port = $d['nvme'] !== '' ? $d['nvme'] : ($d['ata'] !== '' ? $d['ata'].($d['port_no'] ? " (port {$d['port_no']})" : '') : ($d['sas_port'] !== '' ? "{$d['scsi_host']} {$d['sas_port']}" : ($d['usb'] !== '' ? "usb {$d['usb']}" : ($d['hctl'] !== '' ? "scsi {$d['hctl']}" : ''))));
    $place = $places[$d['key']] ?? null;
    $rows[] = [
      'key' => $d['key'], 'device' => $dev, 'group' => $w['group'], 'role' => $w['role'], 'role_label' => ma_d_role_label($w['role']),
      'rank' => ma_d_rank($w['role'], $w['idx']), 'size' => $d['size'], 'size_text' => ma_d_size($d['size']), 'model' => $d['model'],
      'serial' => $d['serial'] !== '' ? $d['serial'] : ma_x_serial($d['unraid_id'] !== '' ? $d['unraid_id'] : (string)($ini['id'] ?? '')),   // as Unraid reads it from the id
      'temp' => is_numeric($temp) ? "$temp °C" : ($spun ? 'spun down' : '-'), 'spundown' => $spun,
      'smart' => ma_d_smart($ctx, $hostName, $spun),
      'controller' => trim($pci.($drv !== '' ? " $drv" : '').($pci !== '' && $enc->pciId($pci) !== '' ? ' ['.$enc->pciId($pci).']' : '')), 'port' => $port,
      'bay' => $place['label'] ?? '', 'transport' => $d['transport'], 'pinned' => $o['pinned'][$dev] ?? null,
      'names' => ma_d_names($d, $w['ini'], $place),
      'ssd' => $d['rotational'] === false,
      'warning' => ($d['rotational'] === false && preg_match('/^a\d$/', $w['group']) && preg_match('/^(parity2?|disk\d+|pinned)$/', $w['role'])) ? MA_D_SSD_WARNING : '',
      'virtual' => $info ? ma_x_virtual_reasons($info, $d['unraid_id'] !== '' ? $d['unraid_id'] : ($ini['id'] ?? '')) : [],
    ];
  }
  foreach ($groups as &$gr) $gr['disks'] = [];
  unset($gr);
  foreach ($rows as $r) $groups[$r['group']]['disks'][] = $r;
  foreach ($groups as &$gr) usort($gr['disks'], function ($a, $b) { return ($a['rank'] <=> $b['rank']) ?: strnatcmp($a['role'], $b['role']) ?: strnatcmp($a['device'], $b['device']); });
  unset($gr);
  uasort($groups, function ($a, $b) { return ($a['order'] <=> $b['order']) ?: strnatcmp($a['title'], $b['title']); });
  return ['groups' => array_values($groups), 'units' => $enc->units($places), 'service' => $o['service']];
}

/* every name of a disk, labelled, for the detail row */
function ma_d_names($d, $ini, $place) {
  $n = [];
  $add = function ($label, $v) use (&$n) { $v = array_values(array_filter((array)$v, function ($x) { return $x !== '' && $x !== null; })); if ($v) $n[] = [$label, implode(', ', $v)]; };
  $add('Unraid id', array_unique(array_filter([$d['unraid_id'], $ini['id'] ?? ''])));
  $add('Slot', $d['slot'] !== '' ? $d['slot'].($d['pool'] !== '' ? " ({$d['pool']})" : '') : '');
  $add('Kernel', array_merge([$d['device']], $d['partitions']));
  $add('md devices', array_unique(array_merge($d['md'], isset($ini['deviceSb']) && $ini['deviceSb'] !== '' ? [$ini['deviceSb']] : [])));
  $add('Mapper (encryption)', $d['mapper']);
  $add('by-id', $d['by_id']);
  $add('by-path', $d['by_path']);
  $add('Partition links', $d['part_links']);
  $add('WWN', $d['wwn']);
  $add('Port', array_filter([$d['ata'], $d['hctl'], $d['scsi_host'], $d['sas_port'], $d['nvme'], $d['usb'] !== '' ? "usb {$d['usb']}" : '']));
  $add('PCI path', implode(' > ', $d['pci_path']));
  $add('File system', trim($d['fs'].($d['encrypted'] ? ', encrypted' : '')));
  $add('File system UUID', $d['fs_uuid']);
  $add('btrfs', $d['btrfs'] ? "fsid {$d['btrfs']['fsid']}, devid {$d['btrfs']['devid']}" : '');
  $add('zfs', $d['zfs'] ? "pool {$d['zfs']['pool']}, guid {$d['zfs']['guid']}" : '');
  $add('Enclosure', $place['label'] ?? '');
  return $n;
}

/* the search box: every disk a name can mean (the kit's lookupAll), a log line through scan(), and enclosure bays */
function ma_d_find($text, $ctx = null) {
  $ctx = $ctx ?: ma_d_ctx();
  $text = trim(substr((string)$text, 0, 2000));
  if ($text === '') return [];
  $map = ma_d_map($ctx);
  $hits = [];
  if (preg_match('/^\S+$/', $text)) foreach ($map->lookupAll($text) as $d) $hits[$d['key']] = $text;
  if (!$hits) foreach ($map->scan($text) as $h) $hits[$h['drive']['key']] = $h['name'];
  $places = ma_d_enc($ctx)->place($map->drives());
  foreach ($places as $key => $p) if ($p['bay'] !== null && strcasecmp($p['bay'], $text) === 0) $hits[$key] = $text;
  $out = [];
  foreach ($hits as $key => $name) foreach ($map->drives() as $d) if ($d['key'] === $key) { $out[] = ['key' => $key, 'name' => $name, 'label' => DriveMap::label($d)]; break; }
  return $out;
}

/* ---------------- found arrays: read-only detection on free disks ----------------
 * Everything below only reads: sysfs, blkid -p (a low-level probe, it does not mount), and reads of the disk through
 * ma_d_readblock (fopen 'rb'). What it reports is evidence; the user decides the mapping and confirms every match. */

const MA_D_SAMPLES = 400;          // blank test: evenly spaced reads over the whole disk
const MA_D_SAMPLE_LEN = 65536;
const MA_D_PARITY_OFFSETS = 10;    // parity plausibility: offsets with non-zero data compared

function ma_d_node($ctx, $name) {
  foreach ([$ctx['dev'], $ctx['hidden']] as $dir) if ($dir && file_exists("$dir/$name")) return "$dir/$name";
  return null;
}

/* btrfs superblock at 64 KiB: fsid (0x20), generation (0x48), num_devices (0x88), dev_item.devid (0xC9); null without magic */
function ma_d_btrfs_sb($raw) {
  if (strlen($raw) < 0xD1 || substr($raw, 0x40, 8) !== '_BHRfS_M') return null;
  $h = bin2hex(substr($raw, 0x20, 16));
  return ['fsid' => substr($h, 0, 8).'-'.substr($h, 8, 4).'-'.substr($h, 12, 4).'-'.substr($h, 16, 4).'-'.substr($h, 20),
          'generation' => unpack('P', substr($raw, 0x48, 8))[1], 'num_devices' => unpack('P', substr($raw, 0x88, 8))[1], 'devid' => unpack('P', substr($raw, 0xC9, 8))[1]];
}

/* the MBR in sector 0: signature, the four entries, and Unraid's "cleared" mark (one entry of type 00 at LBA 1, size
 * ffffffff, as the disk clear leaves it; the kernel ignores a type 00 entry, so sysfs shows no partition) */
function ma_d_mbr($raw) {
  if (strlen($raw) < 512) return null;
  $sig = substr($raw, 510, 2) === "\x55\xaa";
  $e = [];
  for ($i = 0; $i < 4; $i++) { $b = substr($raw, 446 + 16 * $i, 16); $e[] = ['type' => ord($b[4]), 'start' => unpack('V', substr($b, 8, 4))[1], 'size' => unpack('V', substr($b, 12, 4))[1]]; }
  $cleared = $sig && $e[0]['type'] === 0 && $e[0]['start'] === 1 && $e[0]['size'] === 0xffffffff
    && !array_filter(array_slice($e, 1), function ($x) { return $x['type'] || $x['start'] || $x['size']; })
    && trim(substr($raw, 0, 446), "\0") === '';
  return ['signature' => $sig, 'entries' => $e, 'cleared' => $cleared];
}

/* sample $n evenly spaced reads of $len bytes over [$from, $to) bytes of a node; the first 4 KiB of the disk (partition
 * table) are not counted. Returns ['samples' => n read, 'nonzero' => k, 'first' => [GiB of the first non-zero ones]] */
function ma_d_zeroscan($ctx, $node, $from, $to, $n = MA_D_SAMPLES, $len = MA_D_SAMPLE_LEN) {
  $out = ['samples' => 0, 'nonzero' => 0, 'first' => []];
  if ($to - $from < $len) return $out;
  for ($i = 0; $i < $n; $i++) {
    $pos = $from + intdiv(intdiv(($to - $from - $len), $n) * $i, 4096) * 4096;
    $b = (string)call_user_func($ctx['readblock'], $node, $pos, $len);
    if ($b === '') continue;
    if ($pos < 4096) $b = substr($b, 4096 - $pos);
    $out['samples']++;
    if (trim($b, "\0") !== '') { $out['nonzero']++; $g = round($pos / 1073741824, 2); if (count($out['first']) < 5 && !in_array($g, $out['first'], true)) $out['first'][] = $g; }
  }
  return $out;
}

/* what is on one disk: partitions (sysfs), MBR (sector 0), signatures (blkid -p), the btrfs superblock and, with
 * $sample, the blank test. Reads only. */
function ma_d_probe($ctx, $dev, $sample = true) {
  $s = $ctx['sys']."/block/$dev";
  $p = ['device' => $dev, 'sectors' => (int)ma_x_read("$s/size"), 'pttype' => '', 'disk_type' => '', 'disk_label' => '', 'parts' => [], 'mbr' => null, 'scan' => null];
  foreach ((array)@scandir($s) as $c) {
    if (strpos($c, $dev) !== 0 || $c === $dev || !is_file("$s/$c/start")) continue;
    $p['parts'][] = ['name' => $c, 'number' => (int)(ma_x_read("$s/$c/partition") ?: preg_replace('/^.*?(\d+)$/', '$1', $c)), 'start' => (int)ma_x_read("$s/$c/start"), 'size' => (int)ma_x_read("$s/$c/size")];
  }
  usort($p['parts'], function ($a, $b) { return $a['start'] <=> $b['start']; });
  $node = ma_d_node($ctx, $dev);
  $p['probed'] = $node !== null;
  if (!$node) return $p;
  $b = (array)call_user_func($ctx['blkid'], $node);
  $p['pttype'] = (string)($b['PTTYPE'] ?? ''); $p['disk_type'] = (string)($b['TYPE'] ?? ''); $p['disk_label'] = (string)($b['LABEL'] ?? '');
  $p['mbr'] = ma_d_mbr((string)call_user_func($ctx['readblock'], $node, 0, 512));
  foreach ($p['parts'] as &$pt) {
    $pn = ma_d_node($ctx, $pt['name']);
    $pb = $pn ? (array)call_user_func($ctx['blkid'], $pn) : [];
    $pt += ['type' => (string)($pb['TYPE'] ?? ''), 'uuid' => (string)($pb['UUID'] ?? ''), 'uuid_sub' => (string)($pb['UUID_SUB'] ?? ''), 'label' => (string)($pb['LABEL'] ?? ''), 'probed' => $pn !== null, 'btrfs' => null];
    if ($pt['type'] === 'btrfs') $pt['btrfs'] = ma_d_btrfs_sb((string)call_user_func($ctx['readblock'], $node, $pt['start'] * 512 + 65536, 4096));
  }
  unset($pt);
  /* blank test: only where no file system was found (a file system is not blank, and sampling it proves nothing) */
  if ($sample && $p['disk_type'] === '' && !array_filter(array_column($p['parts'], 'type'))) $p['scan'] = ma_d_zeroscan($ctx, $node, 0, $p['sectors'] * 512);
  return $p;
}

/* The evidence lines for one probed disk, in plain words: (a) partition table and start, (b) file system signature,
 * (c) blank or not. */
function ma_d_evidence($p) {
  $ev = [];
  $tab = $p['pttype'] === 'gpt' ? 'GPT' : ($p['pttype'] === 'dos' ? 'MBR' : ($p['pttype'] !== '' ? $p['pttype'] : 'no partition table found by blkid'));
  if ($p['mbr'] && $p['mbr']['cleared']) $ev[] = 'Partition table: MBR with Unraid\'s "cleared" mark (one entry of type 00 at sector 1, size ffffffff, nothing else).';
  elseif ($p['parts']) $ev[] = "Partition table: $tab; ".implode('; ', array_map(function ($x) use ($p) {
      $gap = $p['sectors'] - $x['start'] - $x['size'];
      return "partition {$x['number']} from sector {$x['start']}, ".ma_d_size($x['size'] * 512).($gap <= 34 + 2048 ? ' (to the end of the disk)' : ', '.ma_d_size($gap * 512).' left after it'); }, $p['parts'])).'.';
  else $ev[] = "Partition table: $tab, no partitions.";
  if ($p['disk_type'] !== '') $ev[] = "Signature on the whole disk: {$p['disk_type']}".($p['disk_label'] !== '' ? ", label {$p['disk_label']}" : '').'.';
  foreach ($p['parts'] as $x) {
    if (!$x['probed']) { $ev[] = "Partition {$x['number']}: no device node to read."; continue; }
    if ($x['type'] === '') { $ev[] = "Partition {$x['number']}: no file system signature."; continue; }
    $t = "Partition {$x['number']}: {$x['type']}".($x['uuid'] !== '' ? ", UUID {$x['uuid']}" : '').($x['label'] !== '' ? ", label {$x['label']}" : '');
    if ($x['btrfs']) $t .= ", btrfs devices {$x['btrfs']['num_devices']}, devid {$x['btrfs']['devid']}, generation {$x['btrfs']['generation']}";
    $ev[] = "$t.";
  }
  if ($p['scan']) {
    $sc = $p['scan'];
    $ev[] = $sc['nonzero'] === 0 ? "Blank: all {$sc['samples']} evenly spaced 64 KiB samples are zero (the partition table aside)."
      : "Not blank: {$sc['nonzero']} of {$sc['samples']} evenly spaced 64 KiB samples hold data (first at ".implode(', ', array_map(function ($g) { return "$g GiB"; }, $sc['first'])).').';
  }
  return $ev;
}

/* Unraid array disk or not: one partition (number 1) at one of emhttpd's start sectors, running to the end of the disk
 * (MBR: exactly; GPT: up to the backup table and one alignment step before it). Returns the class and a summary. */
function ma_d_classify($p) {
  $parts = $p['parts'];
  $out = ['kind' => 'foreign', 'layout' => '', 'fs' => '', 'text' => ''];
  $blank = $p['scan'] && $p['scan']['samples'] > 0 && $p['scan']['nonzero'] === 0;
  if ($p['disk_type'] !== '') {
    $t = $p['disk_type'];
    $out['text'] = $t === 'linux_raid_member' ? 'mdadm RAID member on the whole disk' : ($t === 'LVM2_member' ? 'LVM physical volume on the whole disk' :
                   ($t === 'zfs_member' ? 'ZFS pool member on the whole disk'.($p['disk_label'] !== '' ? " (pool {$p['disk_label']})" : '') : "$t on the whole disk, no partition table"));
    return $out;
  }
  if ($p['mbr'] && $p['mbr']['cleared']) { $out['kind'] = 'blank'; $out['text'] = 'Cleared by Unraid'.($blank ? ' and blank' : ($p['scan'] ? ', but not blank' : '')).': ready to be added, not parity and not data'; return $out; }
  if (!$parts) { $out['kind'] = 'blank'; $out['text'] = ($p['pttype'] !== '' ? "{$p['pttype']} partition table with no partitions" : 'No partition table and no file system').($blank ? ', blank' : ''); return $out; }
  $types = array_values(array_filter(array_column($parts, 'type')));
  if (in_array('linux_raid_member', $types)) { $out['text'] = 'mdadm RAID member ('.count($parts).' partitions)'; return $out; }
  if (in_array('LVM2_member', $types)) { $out['text'] = 'LVM physical volume ('.count($parts).' partitions)'; return $out; }
  $p1 = $parts[0];
  $end = $p1['start'] + $p1['size']; $gap = $p['sectors'] - $end;
  $fills = $p['pttype'] === 'gpt' ? ($gap >= 33 && $gap <= 34 + 2048) : ($p['pttype'] === 'dos' ? $gap === 0 : ($gap >= 0 && $gap <= 34 + 2048));
  $unraid = count($parts) === 1 && $p1['number'] === 1 && isset(MA_D_STARTS[$p1['start']]) && $fills;
  if (!$unraid) {
    $zfs = array_values(array_filter($parts, function ($x) { return $x['type'] === 'zfs_member'; }));
    $out['text'] = ($zfs ? 'ZFS pool member'.($zfs[0]['label'] !== '' ? " (pool {$zfs[0]['label']})" : '').', ' : '').($p['pttype'] !== '' ? "{$p['pttype']} partition table, " : '')
      .count($parts).' partition'.(count($parts) > 1 ? 's' : '').($types ? ' ('.implode(', ', array_unique($types)).')' : '').': not the layout of an Unraid array disk';
    return $out;
  }
  $out['layout'] = 'start'.$p1['start'];   // MBR up to 2 TiB, GPT above: one array has both, the start sector is the setting
  $out['fs'] = $p1['type'];
  $style = ($p['pttype'] === 'gpt' ? 'GPT' : ($p['pttype'] === 'dos' ? 'MBR' : 'partition table')).', '.MA_D_STARTS[$p1['start']];
  if ($p1['type'] === '') {
    if ($blank) { $out['kind'] = 'blank'; $out['text'] = "Unraid partition layout ($style), no file system, blank: never used, not a parity disk"; return $out; }
    $out['kind'] = 'parity'; $out['text'] = "Unraid partition layout ($style), no file system, holds data: a parity candidate"; return $out;
  }
  if ($p1['type'] === 'zfs_member' && !preg_match('/^disk\d+$/', $p1['label'])) { $out['kind'] = 'unraid-other'; $out['text'] = "Unraid partition layout ($style) with ZFS pool \"{$p1['label']}\": a pool device, not an array disk"; return $out; }
  if ($p1['type'] === 'btrfs' && $p1['btrfs'] && $p1['btrfs']['num_devices'] > 1) { $out['kind'] = 'unraid-other'; $out['text'] = "Unraid partition layout ($style) with btrfs over {$p1['btrfs']['num_devices']} devices: a pool, not an array disk"; return $out; }
  if (in_array($p1['type'], MA_D_DATA_FS, true)) { $out['kind'] = 'data'; $out['text'] = "Unraid partition layout ($style) with ".($p1['type'] === 'crypto_LUKS' ? 'encryption (LUKS)' : ($p1['type'] === 'zfs_member' ? "ZFS (pool {$p1['label']})" : ($p1['btrfs'] ? 'btrfs, one device' : $p1['type']))); return $out; }
  $out['kind'] = 'unraid-other'; $out['text'] = "Unraid partition layout ($style) with {$p1['type']}: not a file system Unraid uses for array disks";
  return $out;
}

/* the disks detection may look at: unassigned ones, and disks pinned to an extra array whose instance is not running */
function ma_d_free($ctx) {
  $o = ma_d_owners($ctx);
  $out = [];
  foreach ((array)@scandir($ctx['sys'].'/block') as $dev) {
    if (!preg_match('/^(sd[a-z]+|hd[a-z]+|vd[a-z]+|nvme\d+n\d+)$/', $dev)) continue;
    $info = ma_x_devinfo($ctx, $dev); if (!$info) continue;
    if (isset($o['mounted'][$dev]) || $info['holders']) continue;
    $w = $o['own'][$dev] ?? null;
    if ($w && !($w['role'] === 'pinned' && !ma_x_instance_up($ctx, (int)substr($w['group'], 1)))) continue;
    $id = (string)($o['host_devs'][$dev]['id'] ?? '');
    if ($id === '') { $b = ma_x_pick_byid($info['by_id']); $id = $b ? (string)preg_replace('/^(ata|scsi|nvme|usb)-/', '', $b) : ''; }
    $out[$dev] = $info + ['id' => $id, 'pinned' => $o['pinned'][$dev] ?? null];
  }
  return $out;
}

/* Found arrays on the free disks. $sample = run the blank test (slow: 400 reads per disk without a file system).
 * $progress(done, total, device) is called per disk. Returns ['arrays' => [...], 'other' => [disk, ...], 'checked' => n] */
function ma_d_detect($ctx = null, $sample = true, $progress = null) {
  $ctx = $ctx ?: ma_d_ctx();
  $free = ma_d_free($ctx);
  $cand = []; $other = []; $i = 0;
  foreach ($free as $dev => $info) {
    if ($progress) call_user_func($progress, $i++, count($free), $dev);
    $p = ma_d_probe($ctx, $dev, $sample); $c = ma_d_classify($p);
    $disk = ['device' => $dev, 'id' => $info['id'], 'serial' => ma_x_serial($info['id']), 'model' => $info['model'], 'sectors' => $info['sectors'],
             'size_text' => ma_d_size($info['sectors'] * 512), 'by_id' => $info['by_id'], 'pinned' => $info['pinned'], 'kind' => $c['kind'], 'text' => $c['text'],
             'fs' => $c['fs'], 'part' => $p['parts'][0] ?? null, 'pttype' => $p['pttype'], 'evidence' => ma_d_evidence($p), 'ssd' => (int)$info['rotational'] === 0,
             'virtual' => ma_x_virtual_reasons($info, $info['id'])];
    if (!$p['probed']) { $disk['kind'] = 'foreign'; $disk['text'] = 'No device node to read: not checked'; }
    if (in_array($disk['kind'], ['data', 'parity'], true)) $cand[$disk['pinned'] ? 'pin'.$disk['pinned'] : $c['layout']][] = $disk;
    else $other[] = $disk;
  }
  if ($progress) call_user_func($progress, count($free), count($free), '');
  $arrays = [];
  ksort($cand);
  foreach ($cand as $key => $disks) {
    $data = array_values(array_filter($disks, function ($d) { return $d['kind'] === 'data'; }));
    $par = array_values(array_filter($disks, function ($d) { return $d['kind'] === 'parity'; }));
    if (!$data) { foreach ($par as $d) { $d['text'] .= '; no data disk with the same layout was found with it'; $other[] = $d; } continue; }
    $arrays[] = ma_d_found($key, $data, $par, $other);
  }
  foreach ($arrays as $i => &$a) $a['id'] = 'found'.($i + 1);
  unset($a);
  return ['arrays' => $arrays, 'other' => $other, 'checked' => count($free), 'sampled' => $sample];
}

/* Parity plausibility (evidence, optional): XOR the data disks' partitions at up to MA_D_PARITY_OFFSETS offsets where
 * there is data and compare with the parity candidate's partition at the same offset. Offsets where the data XOR and
 * the candidate are both zero are skipped: they match whatever the parity is. Reads only. */
function ma_d_parity_check($parity, $data, $ctx = null, $len = MA_D_SAMPLE_LEN) {
  $ctx = $ctx ?: ma_d_ctx();
  $free = ma_d_free($ctx);
  $devs = array_merge([$parity], (array)$data);
  foreach ($devs as $d) if (!isset($free[$d])) return ['ok' => false, 'error' => "$d is not a free disk"];
  if (count(array_unique($devs)) !== count($devs) || !$data) return ['ok' => false, 'error' => 'Choose one parity disk and at least one other data disk.'];
  $part = function ($dev) use ($ctx) { $p = ma_d_probe($ctx, $dev, false); return [ma_d_node($ctx, $dev), $p['parts'][0] ?? null]; };
  [$pn, $pp] = $part($parity);
  $pstart = $pp ? $pp['start'] : 64; $psize = $pp ? $pp['size'] : $free[$parity]['sectors'] - 64;
  $ds = [];
  foreach ($data as $d) { [$n, $x] = $part($d); if (!$x) return ['ok' => false, 'error' => "$d has no partition"]; $ds[] = [$d, $n, $x['start'], $x['size']]; }
  $rows = []; $tried = 0; $span = $psize * 512 - $len;
  for ($k = 0; $k < 64 && count($rows) < MA_D_PARITY_OFFSETS && $span > 0; $k++) {
    $o = intdiv(intdiv($span, 64) * $k, 4096) * 4096; $tried++;
    $x = str_repeat("\0", $len); $n = 0; $with = [];
    foreach ($ds as [$d, $node, $st, $sz]) {
      if ($o + $len > $sz * 512) continue;
      $b = str_pad((string)call_user_func($ctx['readblock'], $node, $st * 512 + $o, $len), $len, "\0");
      $x ^= $b; $n++;
      if (trim($b, "\0") !== '') $with[] = $d;
    }
    $pb = str_pad((string)call_user_func($ctx['readblock'], $pn, $pstart * 512 + $o, $len), $len, "\0");
    if (trim($x, "\0") === '' && trim($pb, "\0") === '') continue;
    $rows[] = ['offset_gib' => round($o / 1073741824, 2), 'disks' => $n, 'with_data' => $with, 'match' => $x === $pb];
  }
  $m = count(array_filter($rows, function ($r) { return $r['match']; }));
  $seen = []; foreach ($rows as $r) if ($r['match']) foreach ($r['with_data'] as $d) $seen[$d] = 1;
  $silent = array_values(array_diff((array)$data, array_keys($seen)));
  return ['ok' => true, 'parity' => $parity, 'data' => array_values($data), 'rows' => $rows, 'matched' => $m, 'compared' => count($rows), 'tried' => $tried,
          'text' => !$rows ? 'Every offset tried was zero on all disks: nothing to compare.' :
            ($m === count($rows) ? "Evidence: parity matches the XOR of the data disks at all $m offsets with data." :
            ($m === 0 ? 'Evidence: parity matches at none of the '.count($rows).' offsets with data: these disks are not the array of this parity disk, or not all of them, or this is not its parity.' :
             "Evidence: parity matches at $m of ".count($rows).' offsets: a disk may be missing, extra, or parity was not in sync.'))
            .($rows && $silent ? ' '.implode(', ', $silent).(count($silent) > 1 ? ' held' : ' holds').' no data at the offsets that matched, so this check says nothing about '.(count($silent) > 1 ? 'them' : 'it').'.' : ''),
          'silent' => $silent];
}

/* ---------------- background job: detection and the parity check run outside the web request ---------------- */

function ma_d_job_file() { return '/var/run/multiarray/disks-job.json'; }
function ma_d_last_detect_file() { return '/var/run/multiarray/detect-last.json'; }
function ma_d_job_status() {
  $j = json_decode((string)@file_get_contents(ma_d_job_file()), true);
  if (!is_array($j)) return ['state' => 'none'];
  if (($j['state'] ?? '') === 'running' && !empty($j['pid']) && !file_exists("/proc/{$j['pid']}")) $j = ['state' => 'failed', 'error' => 'The job ended without a result.'] + $j;
  return $j;
}
function ma_d_job_start($kind, $args = []) {
  if (!in_array($kind, ['detect', 'parity'], true)) return ['ok' => false, 'error' => 'unknown job'];
  $st = ma_d_job_status();
  if (($st['state'] ?? '') === 'running') return ['ok' => false, 'error' => 'A check is already running.'];
  @mkdir(dirname(ma_d_job_file()), 0755, true);
  file_put_contents(ma_d_job_file(), json_encode(['state' => 'running', 'kind' => $kind, 'started' => time(), 'done' => 0, 'total' => 0]));
  $cmd = '/usr/local/emhttp/plugins/multiarray/scripts/disks-job '.escapeshellarg($kind);
  foreach ($args as $a) $cmd .= ' '.escapeshellarg((string)$a);
  exec("setsid $cmd >/dev/null 2>&1 &");
  return ['ok' => true];
}

/* one found array: roles suggested, evidence and what the slot order means */
function ma_d_found($key, $data, $par, &$other) {
  $maxData = max(array_column($data, 'sectors'));
  $evidence = []; $notes = [];
  // a disk with the same file system UUID as a data disk: with one data disk, single parity is a byte copy of it
  $twins = [];
  $uuids = [];
  foreach ($data as $i => $d) if (($d['part']['uuid'] ?? '') !== '') $uuids[$d['part']['uuid']][] = $i;
  foreach ($uuids as $u => $ix) if (count($ix) > 1) foreach ($ix as $i) $twins[] = $i;
  $parity = [];
  foreach ($par as $d) {
    if ($d['sectors'] >= $maxData) $parity[] = $d;
    else { $d['text'] .= '; smaller than the largest data disk, so not the parity of these disks'; $other[] = $d; }
  }
  usort($parity, function ($a, $b) { return $b['sectors'] <=> $a['sectors'] ?: strnatcmp($a['device'], $b['device']); });
  $pins = array_unique(array_filter(array_column(array_merge($data, $parity), 'pinned')));
  $members = array_merge($data, $parity);
  $starts = array_unique(array_map(function ($d) { return (int)($d['part']['start'] ?? 0); }, $members));
  $tables = array_unique(array_map(function ($d) { return $d['pttype'] === 'gpt' ? 'GPT' : ($d['pttype'] === 'dos' ? 'MBR' : 'unknown'); }, $members));
  $all = count($members);
  if (count($starts) === 1) $evidence[] = ($all === 2 ? 'Both disks have' : "All $all disks have")." Unraid's partition layout with partition 1 at sector ".reset($starts).' ('.MA_D_STARTS[reset($starts)].'), filling the disk'
    .(count($tables) > 1 ? '; MBR on the smaller and GPT on the larger disks, as Unraid makes them (GPT above 2 TiB).' : ' ('.reset($tables).').');
  else $evidence[] = 'The disks have Unraid partition layouts with different start sectors ('.implode(', ', $starts).'), which an array made at one time would not have.';
  if ($pins) $evidence[] = count($pins) === 1 ? 'They are pinned to array '.reset($pins).' in this plugin\'s settings, and that array is not running.' : 'They are pinned to arrays '.implode(', ', $pins).'.';
  else $evidence[] = 'Nothing else ties them together: Unraid writes no array name or member list onto the disks. Grouped by layout only.';
  $fss = array_count_values(array_map(function ($d) { return $d['fs']; }, $data));
  $evidence[] = count($data).' with a file system ('.implode(', ', array_map(function ($f, $n) { return "$n $f"; }, array_keys($fss), $fss)).')'.(count($parity) ? ', '.count($parity).' without (parity '.(count($parity) === 1 ? 'candidate' : 'candidates').', each at least as large as the largest data disk).' : ', none without.');
  if ($ssds = array_column(array_filter(array_merge($data, $parity), function ($d) { return !empty($d['ssd']); }), 'device')) $notes[] = (count($ssds) > 1 ? 'SSDs: ' : 'An SSD: ').implode(', ', $ssds).'. '.MA_D_SSD_WARNING;
  // roles: zfs pool names diskN are Unraid's own slot names for zfs array disks; otherwise device order
  $roles = []; $used = [];
  foreach ($data as $d) if (($d['fs'] === 'zfs_member') && preg_match('/^disk(\d+)$/', $d['part']['label'] ?? '', $m) && !isset($used[$m[1]])) { $roles[$d['device']] = "disk{$m[1]}"; $used[$m[1]] = 1; }
  $next = 1;
  foreach ($data as $d) if (!isset($roles[$d['device']])) { while (isset($used[$next])) $next++; $roles[$d['device']] = "disk$next"; $used[$next] = 1; }
  if ($parity) $roles[$parity[0]['device']] = 'parity';
  foreach (array_slice($parity, 1) as $d) $roles[$d['device']] = '';
  if ($twins && !$parity && count($data) === 2) {
    $notes[] = 'Two disks carry the same file system UUID. With a single data disk, single parity is a byte-for-byte copy of that disk, so one of the two is most likely the parity disk. The disks do not say which one. While parity was in sync the two hold the same bytes, so either choice gives valid parity; the first by device name is suggested. The read-only check afterwards confirms it.';
    $tw = array_map(function ($i) use ($data) { return $data[$i]['device']; }, $twins); sort($tw, SORT_NATURAL);
    $roles[$tw[0]] = 'parity'; $roles[$tw[1]] = 'disk1';
    $evidence[] = 'Same file system UUID on '.implode(' and ', array_map(function ($i) use ($data) { return $data[$i]['device']; }, $twins)).'.';
  }
  $notes[] = 'The slot order can\'t be read from the disks. Unraid records which disk is in which slot only in super.dat on the flash; when it starts an array it compares each slot\'s serial number and size (md_unraid.c, same_disk_info), nothing on the disk itself.';
  if ($twins && !$parity) $notes[] = 'Single parity with one data disk: there is no data order to keep.';
  elseif (count($parity) <= 1) $notes[] = count($parity) ? 'Single parity: parity is the same whatever the order of the data disks, so any data slot order keeps it valid (as the Unraid manual says for a lost flash: the order of the data disks does not matter with single parity).' : 'No parity disk was found with these disks: an array without parity has no order to keep.';
  else $notes[] = 'Two parity candidates: this looks like dual parity. Parity 2 depends on the slot of every data disk, and the old order is only in the old super.dat or an export file. Without one of those, parity 2 can\'t be trusted. Which candidate was parity 1 can\'t be told from the disks either. Safest: import with parity 1 only (the second candidate left out), let the read-only check confirm parity 1, then add parity 2, which Unraid then builds new.';
  $notes[] = 'Suggested data slots: '.(array_filter($data, function ($d) { return $d['fs'] === 'zfs_member'; }) ? 'from the ZFS pool names (diskN) where there are any, otherwise ' : '').'in device name order. Change them if you know the old order.';
  $virtual = [];
  foreach (array_merge($data, $parity) as $d) if ($d['virtual']) $virtual[] = "{$d['device']}: ".implode('; ', $d['virtual']);
  if ($virtual) $notes[] = MA_X_WHY_SHORT.' An import plan is refused for them, as an export is.';
  $twinDevs = array_map(function ($i) use ($data) { return $data[$i]['device']; }, $twins);
  $disks = array_merge($parity, $data);
  foreach ($disks as &$d) {
    $d['role'] = $roles[$d['device']] ?? '';
    $d['can'] = $d['kind'] === 'parity' ? 'parity' : (in_array($d['device'], $twinDevs, true) && !$parity ? 'both' : 'data');   // what roles the disk may take
  }
  unset($d);
  $title = ($twins && !$parity && count($data) === 2) ? 'Single parity, 1 data disk (two identical disks)'
    : (count($parity) ? (count($parity) > 1 ? 'Dual parity' : 'Single parity') : 'No parity').', '.count($data).' data disk'.(count($data) > 1 ? 's' : '');
  return ['key' => $key, 'title' => $title.($pins ? ', pinned to array '.implode(', ', $pins) : ''),
          'disks' => $disks, 'evidence' => $evidence, 'notes' => $notes, 'dual' => count($parity) > 1, 'virtual' => $virtual, 'twins' => (bool)$twins];
}

/* An import plan from detected disks, in the export file format, marked detected. $roles = [device => parity|parity2|diskN|''].
 * Returns ['ok' => true, 'data' => export-format array, 'warnings' => [...]] or ['ok' => false, 'error' => text, 'reasons' => [...]] */
function ma_d_prepare($roles, $ctx = null, $pluginVersion = '', $last = null) {
  $ctx = $ctx ?: ma_d_ctx();
  $det = ma_d_detect($ctx, false);   // fresh: still free, same layout and file systems (the blank test is not repeated)
  $byDev = [];
  foreach ($det['arrays'] as $a) foreach ($a['disks'] as $d) $byDev[$d['device']] = $d + ['found' => $a['key']];
  $e = []; $slots = []; $seen = []; $virtual = []; $found = [];
  $blank = [];   // disks the last full detection found blank: never parity
  foreach (($last ?? [])['other'] ?? [] as $d) if (($d['kind'] ?? '') === 'blank') $blank[$d['device']] = 1;
  foreach ((array)$roles as $dev => $role) {
    $role = (string)$role; $dev = (string)$dev;
    if ($role === '') continue;
    $d = $byDev[$dev] ?? null;
    if (!$d) { $e[] = "$dev is not a disk of a found array any more (assigned, mounted, pinned elsewhere or gone)"; continue; }
    $found[$d['found']] = 1;
    if (!preg_match('/^(parity|parity2|disk([1-9]|1\d|2[0-8]))$/', $role)) { $e[] = "$dev: \"$role\" is not a slot (parity, parity2, disk1-disk28)"; continue; }
    if (isset($seen[$role])) { $e[] = "$role is given to two disks ($seen[$role] and $dev)"; continue; }
    $seen[$role] = $dev;
    $isP = $role === 'parity' || $role === 'parity2';
    if (isset($blank[$dev])) { $e[] = "$dev was found blank; it can't be parity or data"; continue; }
    if ($isP && $d['can'] === 'data') $e[] = "$dev has a file system ({$d['fs']}); it can't be a parity disk: a parity sync or correcting check would overwrite it";
    if (!$isP && $d['can'] === 'parity') $e[] = "$dev has no file system; it can't be a data disk (Unraid would offer to format it)";
    if ($d['virtual']) $virtual[] = "$dev: ".implode('; ', $d['virtual']);
    if ($d['id'] === '' || ma_x_serial($d['id']) === '') { $e[] = "$dev has no Unraid id (no /dev/disk/by-id name with a serial number)"; continue; }
    $idx = $role === 'parity' ? 0 : ($role === 'parity2' ? 29 : (int)substr($role, 4));
    $slots[] = ['slot' => $idx, 'role' => $role, 'id' => $d['id'], 'serial' => ma_x_serial($d['id']),
      'size_kb' => intdiv((int)$d['part']['size'], 2), 'offset' => (int)$d['part']['start'], 'sectors' => $d['sectors'],
      'logical_sector_size' => null, 'physical_sector_size' => null, 'model' => $d['model'], 'vendor' => '', 'rotational' => null,
      'partition' => ['start' => (int)$d['part']['start'], 'size' => (int)$d['part']['size']],
      'fs' => ['type' => $isP ? null : ($d['fs'] === 'crypto_LUKS' ? null : ($d['fs'] === 'zfs_member' ? 'zfs' : $d['fs'])), 'uuid' => $isP ? null : ($d['part']['uuid'] ?: null)],
      'luks' => ['encrypted' => $d['fs'] === 'crypto_LUKS', 'uuid' => $d['fs'] === 'crypto_LUKS' ? ($d['part']['uuid'] ?: null) : null],
      'seen_as' => ['device' => $dev, 'by_id' => $d['by_id']], 'detected_as' => $d['text']];
  }
  if (!$slots && !$e) $e[] = 'no disk was given a slot';
  if (count($found) > 1) $e[] = 'the disks come from more than one found array';
  $pz = []; $dz = 0;
  foreach ($slots as $s) { if ($s['slot'] === 0 || $s['slot'] === 29) $pz[$s['role']] = $s['sectors']; else $dz = max($dz, $s['sectors']); }
  foreach ($pz as $r => $z) if ($z < $dz) $e[] = "$r is smaller than the largest data disk";
  if ($slots && !array_filter($slots, function ($s) { return $s['slot'] >= 1 && $s['slot'] <= 28; })) $e[] = 'no data disk was given a slot';
  if ($virtual && !$ctx['allow_virtual']) return ['ok' => false, 'error' => MA_X_WHY_SHORT, 'reasons' => $virtual, 'why' => true];
  if ($e) return ['ok' => false, 'error' => 'The plan can\'t be made:', 'reasons' => array_values(array_unique($e))];
  usort($slots, function ($a, $b) { return $a['slot'] <=> $b['slot']; });
  $w = ['Made from what is on the disks, not from an export file: the slot order is a suggestion or your choice, not a record.'];
  $ssd = array_values(array_map(function ($s) { return "{$s['seen_as']['device']} ({$s['role']})"; }, array_filter($slots, function ($s) use ($byDev) { return !empty($byDev[$s['seen_as']['device']]['ssd']); })));
  if ($ssd) $w[] = (count($ssd) > 1 ? 'SSDs in the plan: ' : 'An SSD in the plan: ').implode(', ', $ssd).'. '.MA_D_SSD_WARNING;
  if (isset($pz['parity2'])) $w[] = 'Parity 2 is valid only if every data disk is in its old slot, which the disks do not record. Expect the check to find errors unless you know the order.';
  $w[] = 'Run the read-only parity check after the start: 0 errors confirms the parity disk and, with parity 2, the order.';
  $data = ['format' => MA_X_FORMAT, 'format_version' => MA_X_FORMAT_VERSION, 'detected' => true,
    'exported' => gmdate('Y-m-d\TH:i:s\Z', $ctx['now']), 'unraid_version' => ma_x_ini($ctx['version'])['version'] ?? null, 'plugin_version' => $pluginVersion ?: null,
    'server' => $ctx['hostname'], 'array' => 0,
    'state' => ['mdState' => 'DETECTED', 'parity_valid_since' => null, 'last_check_errors' => null, 'last_check_exit' => null, 'last_check_log' => null],
    'encrypted' => (bool)array_filter($slots, function ($s) { return $s['luks']['encrypted']; }), 'settings' => [], 'slots' => $slots, 'warnings' => $w];
  if ($virtual) { $data['test_virtual_allowed'] = true; $data['warnings'][] = 'TEST HOOK: virtual disks allowed: '.implode(' | ', $virtual); }
  return ['ok' => true, 'data' => $data, 'warnings' => $data['warnings']];
}

/* ---------------- locate: read a disk for 20 s so its activity light flashes ---------------- */

const MA_D_LOCATE_SECS = 20;
function ma_d_locate_file() { return '/var/run/multiarray/locate.json'; }

function ma_d_locate_status() {
  $s = json_decode((string)@file_get_contents(ma_d_locate_file()), true);
  if (!is_array($s) || empty($s['pid'])) return ['running' => false];
  $cmd = (string)@file_get_contents("/proc/{$s['pid']}/cmdline");
  $run = strpos($cmd, 'timeout') !== false && strpos($cmd, "/{$s['device']}") !== false;
  if (!$run) { @unlink(ma_d_locate_file()); return ['running' => false, 'last' => $s['device'] ?? '']; }
  return ['running' => true, 'device' => $s['device'], 'left' => max(0, MA_D_LOCATE_SECS - (time() - (int)$s['started']))];
}

/* $spunDown comes from Unraid's state; $confirmed = the user agreed to spin it up */
function ma_d_locate_start($dev, $spunDown, $confirmed, $ctx = null) {
  $ctx = $ctx ?: ma_d_ctx();
  if (!preg_match('/^(sd[a-z]+|hd[a-z]+|vd[a-z]+|nvme\d+n\d+)$/', (string)$dev) || !is_dir($ctx['sys']."/block/$dev")) return ['ok' => false, 'error' => 'No such disk.'];
  $st = ma_d_locate_status();
  if ($st['running']) return ['ok' => false, 'error' => "Another disk is being located ({$st['device']}). Stop it first, or wait {$st['left']} s."];
  if ($spunDown && !$confirmed) return ['ok' => false, 'confirm' => true, 'error' => "$dev is spun down. Locating it spins it up."];
  $node = ma_d_node($ctx, $dev);
  if (!$node || filetype($node) !== 'block') return ['ok' => false, 'error' => "$dev has no device node to read."];
  @mkdir(dirname(ma_d_locate_file()), 0755, true);
  /* read only: dd from the disk into /dev/null, O_DIRECT so the reads reach the disk, not the page cache */
  $pid = (int)exec('setsid timeout '.MA_D_LOCATE_SECS.' dd if='.escapeshellarg($node).' of=/dev/null bs=1M iflag=direct status=none >/dev/null 2>&1 & echo $!');
  if ($pid <= 0) return ['ok' => false, 'error' => 'The read could not be started.'];
  file_put_contents(ma_d_locate_file(), json_encode(['device' => $dev, 'pid' => $pid, 'started' => time()]));
  return ['ok' => true, 'device' => $dev, 'seconds' => MA_D_LOCATE_SECS];
}

function ma_d_locate_stop() {
  $st = ma_d_locate_status();
  if (!$st['running']) return ['ok' => true, 'running' => false];
  $s = json_decode((string)@file_get_contents(ma_d_locate_file()), true);
  @posix_kill((int)$s['pid'], 15);   // timeout passes the signal on to dd
  for ($i = 0; $i < 20 && ma_d_locate_status()['running']; $i++) usleep(100000);
  @unlink(ma_d_locate_file());
  return ['ok' => true, 'running' => false];
}
