<?PHP
/* multiarray export / import library. No output, no side effects on load.
 *
 * Export: a read-only description of one array (1 = the main array, 2..9 = extra arrays) as JSON:
 *   every assigned slot with the identity md compares (serial = text after the last '_' of the id, size in 1K blocks)
 *   and its layout (sectors, sector size, partition 1 start/size), file system and LUKS UUIDs; array settings from
 *   disk.cfg (a fixed list of keys, never anything else). Refused when a slot disk is virtual or the array is not clean.
 * Import: match every exported slot to exactly one free disk (serial + size, like md_unraid.c same_disk_info, plus the
 *   whole-disk size and the partition layout); the plan is shown and confirmed before anything is sent; the
 *   assignments go through the target emhttpd's own commands (changeSlots, changeDevice), never through super.dat.
 *
 * All paths come from a context array ($ctx) so the unit tests can run on fixture trees (tests/xport-test.php).
 */

const MA_X_FORMAT = 'multiarray-array-export';
const MA_X_FORMAT_VERSION = 2;   /* 2 (2026.11.09l): zfs pool name per slot, pools, shares. Version 1 files still import. */
const MA_X_FORMAT_READS = [1, 2];
/* disk.cfg keys that belong to the array; nothing else is exported */
const MA_X_SETTING_KEYS = '/^(md_[a-z_]+|spindownDelay|spinupGroups|defaultFsType|startArray|shutdownTimeout|poll_attributes|warning|critical|hot|max)$/';
/* names a hypervisor gives its emulated disks */
const MA_X_VIRTUAL_RE = '/qemu|virtio|vmware|virtual\s*disk|vbox|virtualbox|msft\s+virtual|hyper-?v|xen/i';
/* drivers of emulated disk controllers (a disk below one of them is a virtual disk) */
const MA_X_VIRTUAL_DRIVERS = ['virtio_scsi', 'virtio_blk', 'virtio-pci', 'virtio_pci', 'vmw_pvscsi', 'hv_storvsc', 'xen-blkfront', 'xen_blkfront'];
const MA_X_WHY_SHORT = "This array's disks are virtual disks of a VM, not the real disks, so it can't be exported.";

function ma_x_ctx($over = []) {
  return $over + [
    'sys'       => '/sys',
    'byid'      => '/dev/disk/by-id',
    'dev'       => '/dev',
    'emhttp'    => '/var/local/emhttp',     // array 1 state
    'boot'      => '/boot/config',          // array 1 flash config
    'plugcfg'   => '/boot/config/plugins/multiarray',
    'runroot'   => '/var/lib/multiarray',
    'proc'      => '/proc',
    'mounts'    => '/proc/mounts',
    'swaps'     => '/proc/swaps',
    'version'   => '/etc/unraid-version',
    'hostname'  => gethostname(),
    'now'       => time(),
    'blkid'     => 'ma_x_blkid',            // callable(devnode) => ['TYPE'=>..,'UUID'=>..] (read-only probe)
    'zpools'    => 'ma_x_zpools',           // callable() => names of the imported zfs pools
    'allow_virtual' => ma_x_test_allow_virtual(),
  ];
}

/* TEST HOOK, never on by default: lets the virtual-disk check pass so the export/import path can be tested on a VM.
 * Set by the test only: env MULTIARRAY_TEST_ALLOW_VIRTUAL=1, or the RAM-only file /var/run/multiarray/test-allow-virtual
 * (gone at reboot). An export made this way is marked "test_virtual_allowed" and import refuses it unless the hook is set too. */
function ma_x_test_allow_virtual() {
  return getenv('MULTIARRAY_TEST_ALLOW_VIRTUAL') === '1' || is_file('/var/run/multiarray/test-allow-virtual');
}

function ma_x_ini($file, $sections = false) { return is_file($file) ? ((array)@parse_ini_file($file, $sections)) : []; }
function ma_x_read($file) { $s = @file_get_contents($file); return $s === false ? null : trim($s); }
function ma_x_role($idx) { return $idx == 0 ? 'parity' : ($idx == 29 ? 'parity2' : "disk$idx"); }
function ma_x_serial($id) { $p = strrpos((string)$id, '_'); return $p === false ? (string)$id : substr($id, $p + 1); }
/* one name per file system: blkid says zfs_member, Unraid's settings say zfs */
function ma_x_fsname($t) { $t = (string)$t; return $t === 'zfs_member' ? 'zfs' : ($t === '' ? null : $t); }

function ma_x_blkid($node) {
  if (!is_string($node) || !preg_match('#^/dev/[A-Za-z0-9/_.-]+$#', $node)) return [];
  $out = []; @exec('/sbin/blkid -p -o export '.escapeshellarg($node).' 2>/dev/null', $lines);
  foreach ((array)$lines as $l) if (preg_match('/^([A-Z_]+)=(.*)$/', $l, $m)) $out[$m[1]] = $m[2];
  return $out;
}

/* /proc/mdstat style key=value file -> array */
function ma_x_kv($file) {
  $o = [];
  foreach (preg_split('/\n/', (string)@file_get_contents($file)) as $l) if (($p = strpos($l, '=')) !== false) $o[substr($l, 0, $p)] = substr($l, $p + 1);
  return $o;
}

/* where array N keeps its state */
function ma_x_paths($ctx, $n) {
  if ($n == 1) return ['state' => $ctx['emhttp'], 'mdstat' => $ctx['proc'].'/mdstat', 'cfg' => $ctx['boot'], 'major' => ma_x_major($ctx, 'md')];
  return ['state' => $ctx['runroot']."/a$n/state", 'mdstat' => $ctx['proc']."/mdstat$n", 'cfg' => $ctx['plugcfg']."/array$n",
          'major' => ma_x_major($ctx, "md$n")];
}
function ma_x_major($ctx, $name) {
  foreach (preg_split('/\n/', (string)@file_get_contents($ctx['proc'].'/devices')) as $l)
    if (preg_match('/^\s*(\d+)\s+(\S+)$/', $l, $m) && $m[2] === $name) return (int)$m[1];
  return null;
}
function ma_x_instance_up($ctx, $n) { return $n == 1 || (is_file($ctx['runroot']."/a$n/ns.id") && is_file($ctx['runroot']."/a$n/state/var.ini")); }

/* everything the kernel says about a whole disk (sysfs, read-only) */
function ma_x_devinfo($ctx, $dev) {
  $s = $ctx['sys']."/block/$dev";
  if (!preg_match('/^[a-z][a-z0-9]*$/', (string)$dev) || !is_dir($s)) return null;
  $part = preg_match('/[0-9]$/', $dev) ? "{$dev}p1" : "{$dev}1";
  $real = (string)@realpath("$s/device");
  $drivers = [];
  for ($p = $real; $p && $p !== '/' && strlen($p) > strlen($ctx['sys']); $p = dirname($p))
    if (is_link("$p/driver")) $drivers[] = basename((string)@readlink("$p/driver"));
  $byid = [];
  foreach ((array)@scandir($ctx['byid']) as $l) {
    if ($l === '.' || $l === '..' || strpos($l, '-part') !== false) continue;
    if (basename((string)@readlink($ctx['byid']."/$l")) === $dev) $byid[] = $l;
  }
  sort($byid);
  return [
    'device'  => $dev,
    'sectors' => (int)ma_x_read("$s/size"),                            // 512-byte units, always
    'logical_sector_size'  => (int)(ma_x_read("$s/queue/logical_block_size") ?: 512),
    'physical_sector_size' => (int)(ma_x_read("$s/queue/physical_block_size") ?: 512),
    'model'   => trim((string)(ma_x_read("$s/device/model") ?? '')),
    'vendor'  => trim((string)(ma_x_read("$s/device/vendor") ?? '')),
    'rotational' => (int)ma_x_read("$s/queue/rotational"),
    'partition'  => is_dir("$s/$part") ? ['name' => $part, 'start' => (int)ma_x_read("$s/$part/start"), 'size' => (int)ma_x_read("$s/$part/size")] : null,
    'holders' => array_values(array_diff(array_merge(@scandir("$s/holders") ?: [], @scandir("$s/$part/holders") ?: []), ['.', '..'])),   // (array)false would be [false]
    'sysfs_path' => $real,
    'drivers' => $drivers,
    'by_id'   => $byid,
  ];
}

/* why a disk counts as virtual (empty = real) */
function ma_x_virtual_reasons($info, $id) {
  $r = [];
  if (preg_match(MA_X_VIRTUAL_RE, $info['model'].' '.$info['vendor'])) $r[] = "model \"".trim($info['vendor'].' '.$info['model'])."\" is a hypervisor's emulated disk";
  $p = strrpos((string)$id, '_');   // the model part of the id only: a serial is random letters and digits
  if ($p !== false && preg_match(MA_X_VIRTUAL_RE, substr($id, 0, $p))) $r[] = "the Unraid id \"$id\" names a virtual disk";
  foreach ($info['by_id'] as $b) if (strncmp($b, 'virtio-', 7) === 0) { $r[] = "it is a virtio disk ($b)"; break; }
  if (ma_x_serial($id) === '' ) $r[] = 'it has no serial number';
  if (strpos($info['sysfs_path'], '/virtio') !== false) $r[] = 'it sits on a virtio controller';
  foreach ($info['drivers'] as $d) if (in_array($d, MA_X_VIRTUAL_DRIVERS)) { $r[] = "its controller driver is $d"; break; }
  return array_values(array_unique($r));
}

/* the block node to probe for the file system: the raw partition while it exists, else the array's md node (same content
 * for data slots: emhttpd moves claimed raw nodes away while the array runs) */
function ma_x_fsnode($ctx, $info, $paths, $idx) {
  if ($info['partition'] && file_exists($ctx['dev'].'/'.$info['partition']['name'])) return $ctx['dev'].'/'.$info['partition']['name'];
  if ($idx == 0 || $idx == 29 || $paths['major'] === null) return null;
  foreach ((array)@glob($ctx['dev'].'/md*p1') as $f) {
    $st = @stat($f); if (!$st) continue;
    $rdev = $st['rdev'];
    if ((($rdev >> 8) & 0xfff) == $paths['major'] && (($rdev & 0xff) | (($rdev >> 12) & 0xfff00)) == $idx) return $f;
  }
  return null;
}

/* ---------------- export ---------------- */

/* returns ['ok'=>true,'data'=>[...],'warnings'=>[...]] or ['ok'=>false,'error'=>text,'reasons'=>[...],'why'=>bool] */
function ma_x_export($n, $ctx = null, $pluginVersion = '') {
  $ctx = $ctx ?: ma_x_ctx();
  $n = (int)$n;
  if ($n < 1 || $n > 9) return ['ok' => false, 'error' => "There is no array $n (1 is the main array, 2-9 extra arrays).", 'reasons' => []];
  if (!ma_x_instance_up($ctx, $n)) return ['ok' => false, 'error' => "Array $n is not running (its management instance is stopped), so there is nothing to read. Start it from its Main$n tab first.", 'reasons' => []];
  $p = ma_x_paths($ctx, $n);
  $var = ma_x_ini($p['state'].'/var.ini');
  $disks = ma_x_ini($p['state'].'/disks.ini', true);
  $md = ma_x_kv($p['mdstat']);
  $unclean = []; $warnings = [];
  $state = $var['mdState'] ?? ($md['mdState'] ?? '');
  if ($state !== 'STARTED' && $state !== 'STOPPED') $unclean[] = "the array state is ".($state ?: 'unknown').", not Started or Stopped";
  $action = explode(' ', (string)($var['mdResyncAction'] ?? ''));
  if ((int)($var['mdResyncPos'] ?? $md['mdResyncPos'] ?? 0) > 0 || (int)($var['mdResync'] ?? 0) > 0) $unclean[] = 'a parity check, sync or rebuild is running';
  if ($state === 'STOPPED') {
    if (($var['sbClean'] ?? '') !== 'yes') $unclean[] = 'the array was not stopped cleanly (unclean shutdown)';
    if (in_array($action[0], ['recon', 'clear'])) $unclean[] = "starting it would begin a ".($action[0] == 'recon' ? 'parity sync or rebuild' : 'disk clear');
  }
  $slots = []; $virtual = [];
  for ($i = 0; $i <= 29; $i++) {
    $did = $md["diskId.$i"] ?? '';
    $role = ma_x_role($i);
    $d = $disks[$role] ?? [];
    if ($did === '') {   // slot not in the superblock: must also be empty in emhttpd's view
      if (($d['id'] ?? '') !== '' && ($d['type'] ?? '') !== '') $unclean[] = "$role has a disk assigned that is not part of the array yet";
      continue;
    }
    $status = $md["rdevStatus.$i"] ?? '';
    if ($status !== 'DISK_OK') $unclean[] = "$role is not OK ($status: disabled, missing, wrong, invalid or emulated)";
    $dev = $md["rdevName.$i"] ?? ($d['device'] ?? '');
    $info = $dev !== '' ? ma_x_devinfo($ctx, $dev) : null;
    if (!$info) { $unclean[] = "$role: its disk is not present"; continue; }
    $vr = ma_x_virtual_reasons($info, $did);
    if ($vr) $virtual[] = "$role ($dev): ".implode('; ', $vr);
    $fs = ['type' => null, 'uuid' => null]; $luks = ['encrypted' => false, 'uuid' => null];
    $fsType = (string)($d['fsType'] ?? '');
    if ($i != 0 && $i != 29) {
      $node = ma_x_fsnode($ctx, $info, $p, $i);
      $b = $node ? (array)call_user_func($ctx['blkid'], $node) : [];
      $cfgFs = preg_replace('/^luks:/', '', $fsType) ?: null;
      if (($b['TYPE'] ?? '') === 'crypto_LUKS') { $luks = ['encrypted' => true, 'uuid' => $b['UUID'] ?? null]; $fs['type'] = ma_x_fsname($cfgFs); }
      elseif ($node && ($b['TYPE'] ?? '') === '') {   /* probed and empty: the setting alone does not make a file system */
        if ($cfgFs) $warnings[] = "$role: no file system found on the disk, although its setting says $fsType (for example a disk that was wiped or never formatted). After an import it shows Unmountable and Format is offered: format it only if it holds nothing you need.";
      } else { $fs = ['type' => ma_x_fsname(($b['TYPE'] ?? null) ?: $cfgFs), 'uuid' => $b['UUID'] ?? null];
             if (strncmp($fsType, 'luks:', 5) === 0) $luks['encrypted'] = true; }
      /* zfs: the pool name is what an import must not clash with (names are server-wide). Unraid names an array zfs
       * disk's pool after its slot (disk1); an encrypted one cannot be read without its key, so the slot name stands */
      if ($fs['type'] === 'zfs') $fs['pool'] = ($b['LABEL'] ?? '') !== '' && !$luks['encrypted'] ? $b['LABEL'] : $role;
      if (!$node) $warnings[] = "$role: file system UUID not read (no device node to probe)";
      $fss = (string)($d['fsStatus'] ?? '');
      if ($state === 'STARTED' && $fss !== '' && $fss !== 'Mounted') $warnings[] = "$role: file system status is \"$fss\"";
    }
    $slots[] = [
      'slot' => $i, 'role' => $role,
      'id' => $did, 'serial' => ma_x_serial($did),
      'size_kb' => (int)($md["diskSize.$i"] ?? 0), 'offset' => (int)($md["rdevOffset.$i"] ?? 0),
      'sectors' => $info['sectors'], 'logical_sector_size' => $info['logical_sector_size'], 'physical_sector_size' => $info['physical_sector_size'],
      'model' => $info['model'], 'vendor' => $info['vendor'], 'rotational' => $info['rotational'],
      'partition' => $info['partition'] ? ['start' => $info['partition']['start'], 'size' => $info['partition']['size']] : null,
      'fs' => $fs, 'luks' => $luks,
      'seen_as' => ['device' => $dev, 'by_id' => $info['by_id']],
    ];
  }
  if (!$slots) $unclean[] = 'the array has no assigned disks';
  $hasParity = (bool)array_filter($slots, fn($s) => $s['slot'] == 0 || $s['slot'] == 29);
  $synced = (int)($var['sbSynced'] ?? $md['sbSynced'] ?? 0);
  if ($hasParity && $synced == 0) $unclean[] = 'parity was never valid (no parity sync has completed)';
  if ($virtual && !$ctx['allow_virtual'])
    return ['ok' => false, 'error' => MA_X_WHY_SHORT, 'reasons' => $virtual, 'why' => true];
  if ($unclean) return ['ok' => false, 'error' => "Array $n is not clean, so it can't be exported. Fix this first, then export again.", 'reasons' => array_values(array_unique($unclean))];
  $errs = (int)($var['sbSyncErrs'] ?? $md['sbSyncErrs'] ?? 0); $exit = (int)($var['sbSyncExit'] ?? $md['sbSyncExit'] ?? 0);
  if ($errs > 0) $warnings[] = "the last parity check found $errs errors";
  if ($exit != 0) $warnings[] = "the last parity check did not finish (exit code $exit)";
  $last = null;
  $log = $p['cfg'].'/parity-checks.log';
  if (is_file($log)) { $ls = array_values(array_filter(preg_split('/\n/', (string)@file_get_contents($log)))); if ($ls) $last = end($ls); }
  $settings = [];
  foreach (ma_x_ini($p['cfg'].'/disk.cfg') as $k => $v) if (preg_match(MA_X_SETTING_KEYS, $k)) $settings[$k] = $v;
  ksort($settings);
  $ver = ma_x_ini($ctx['version']);
  [$pools, $pw] = ma_x_export_pools($ctx, $p, $disks); $warnings = array_merge($warnings, $pw);
  $data = [
    'format' => MA_X_FORMAT, 'format_version' => MA_X_FORMAT_VERSION,
    'exported' => gmdate('Y-m-d\TH:i:s\Z', $ctx['now']),
    'unraid_version' => $ver['version'] ?? null, 'plugin_version' => $pluginVersion ?: null,
    'server' => $ctx['hostname'], 'array' => $n,
    'state' => ['mdState' => $state, 'parity_valid_since' => $synced ? gmdate('Y-m-d\TH:i:s\Z', $synced) : null,
                'last_check_errors' => $errs, 'last_check_exit' => $exit, 'last_check_log' => $last],
    'encrypted' => (bool)array_filter($slots, fn($s) => $s['luks']['encrypted']),
    'settings' => $settings,
    'slots' => $slots,
    'pools' => $pools,
    'shares' => ma_x_export_shares($p['cfg']),
    'warnings' => $warnings,
  ];
  if ($virtual) { $data['test_virtual_allowed'] = true; $data['warnings'][] = 'TEST HOOK: virtual disks allowed: '.implode(' | ', $virtual); }
  return ['ok' => true, 'data' => $data, 'warnings' => $data['warnings']];
}

/* the array's pools as its own emhttpd has them (disks.ini type Cache; members of pool "cache" are cache, cache2, ...)
 * plus the pool's settings file (pools/<name>.cfg). Recorded so an import can say what to recreate; never assigned
 * automatically. Returns [pools, warnings]. */
function ma_x_export_pools($ctx, $p, $disks) {
  $pools = []; $w = [];
  foreach ($disks as $k => $d) {
    if (($d['type'] ?? '') !== 'Cache') continue;
    $name = preg_replace('/\d+$/', '', (string)$k);   // Unraid's prefix(): pool names cannot end in a digit
    if (!isset($pools[$name])) {
      $cfg = ma_x_ini($p['cfg']."/pools/$name.cfg");
      $pools[$name] = ['name' => $name, 'fs' => ma_x_fsname(preg_replace('/^luks:/', '', (string)($d['fsType'] ?? ''))),
                       'encrypted' => strncmp((string)($d['fsType'] ?? ''), 'luks:', 5) === 0, 'profile' => (string)($d['fsProfile'] ?? ''),
                       'slots' => (int)($cfg['diskSlots'] ?? $d['slots'] ?? 0), 'zfs_pool' => null, 'settings' => $cfg, 'devices' => []];
    }
    $id = (string)($d['id'] ?? ''); $dev = (string)($d['device'] ?? '');
    if ($id === '') continue;   // an empty member slot
    $info = $dev !== '' ? ma_x_devinfo($ctx, $dev) : null;
    $b = []; if ($info && $info['partition'] && file_exists($ctx['dev'].'/'.$info['partition']['name'])) $b = (array)call_user_func($ctx['blkid'], $ctx['dev'].'/'.$info['partition']['name']);
    if (ma_x_fsname($b['TYPE'] ?? '') === 'zfs' && ($b['LABEL'] ?? '') !== '') $pools[$name]['zfs_pool'] = $b['LABEL'];
    $pools[$name]['devices'][] = ['member' => (string)$k, 'id' => $id, 'serial' => ma_x_serial($id), 'size_kb' => (int)($d['size'] ?? 0),
      'sectors' => $info['sectors'] ?? null, 'model' => $info['model'] ?? '', 'by_id' => $info['by_id'] ?? [],
      'fs' => ['type' => ma_x_fsname($b['TYPE'] ?? ''), 'uuid' => $b['UUID'] ?? null]];
    if (!$info) $w[] = "pool $name: member $k ($id) is not present; the export lists it from the configuration only";
  }
  foreach ($pools as &$pl) if ($pl['fs'] === 'zfs' && $pl['zfs_pool'] === null) $pl['zfs_pool'] = $pl['name'];   // encrypted or not readable: Unraid names it after the pool
  unset($pl);
  return [array_values($pools), $w];
}
/* the array's share settings files (shares/<name>.cfg): which pool a share uses, mover direction, export settings.
 * For an extra array these are its own shares (its own emhttpd, its own flash folder arrayN/shares). */
function ma_x_export_shares($cfgdir) {
  $out = [];
  foreach ((array)@glob("$cfgdir/shares/*.cfg") as $f) $out[] = ['name' => basename($f, '.cfg'), 'settings' => ma_x_ini($f)];
  return $out;
}

function ma_x_filename($data) {
  $srv = preg_replace('/[^A-Za-z0-9_.-]+/', '_', (string)$data['server']) ?: 'server';
  return "multiarray-export-$srv-array{$data['array']}-".substr($data['exported'], 0, 10).'.json';
}

/* ---------------- import ---------------- */

/* shape check of an uploaded export; returns [] when fine, else a list of problems */
function ma_x_validate($data, $ctx) {
  $e = [];
  if (!is_array($data)) return ['the file is not JSON from a Multi Arrays export'];
  if (($data['format'] ?? '') !== MA_X_FORMAT) $e[] = 'the file is not a Multi Arrays array export';
  if (!in_array((int)($data['format_version'] ?? 0), MA_X_FORMAT_READS, true)) $e[] = 'the export format version is '.($data['format_version'] ?? 'missing').', this plugin reads versions '.implode(' and ', MA_X_FORMAT_READS).' (a newer file needs a newer plugin)';
  if (!empty($data['test_virtual_allowed']) && !$ctx['allow_virtual']) $e[] = 'the file was made with the virtual-disk test hook on; it is for testing only';
  $slots = $data['slots'] ?? null;
  if (!is_array($slots) || !$slots) { $e[] = 'the file lists no slots'; return $e; }
  $seen = []; $ser = [];
  foreach ($slots as $s) {
    $i = $s['slot'] ?? -1;
    if (!is_int($i) || $i < 0 || $i > 29) { $e[] = 'a slot number is out of range'; continue; }
    if (isset($seen[$i])) $e[] = "slot $i is listed twice";
    $seen[$i] = 1;
    if (($s['role'] ?? '') !== ma_x_role($i)) $e[] = "slot $i has the role \"".($s['role'] ?? '')."\", expected ".ma_x_role($i);
    if (!is_string($s['serial'] ?? null) || $s['serial'] === '' || $s['serial'] !== ma_x_serial($s['id'] ?? '')) $e[] = "slot $i: serial and id do not agree";
    if ((int)($s['size_kb'] ?? 0) <= 0 || (int)($s['sectors'] ?? 0) <= 0) $e[] = "slot $i: no size";
    if (isset($ser[$s['serial'] ?? ''])) $e[] = "the serial ".($s['serial'] ?? '')." is in two slots";
    $ser[$s['serial'] ?? ''] = 1;
  }
  return $e;
}

/* disks a target array can be given: [dev => [info + id (target's own id string) + why-not reasons]] */
function ma_x_candidates($ctx, $target) {
  $tp = ma_x_paths($ctx, $target);
  $tdevs = ma_x_ini($tp['state'].'/devs.ini', true);   // the target emhttpd's own unassigned disks and their id strings
  $tid = []; foreach ($tdevs as $d) if (!empty($d['device'])) $tid[$d['device']] = $d['id'] ?? '';
  /* in use: array 1 / pools (host emhttpd), every running extra array, every pin to another array */
  $used = [];
  foreach (ma_x_ini($ctx['emhttp'].'/disks.ini', true) as $k => $d) if (!empty($d['device']) && ($d['type'] ?? '') !== 'Flash' && $k !== 'flash') $used[$d['device']] = $target == 1 ? 'assigned in the main array or a pool' : 'in the main array or a pool';
  for ($n = 2; $n <= 9; $n++) {
    foreach (ma_x_ini($ctx['runroot']."/a$n/state/disks.ini", true) as $k => $d) if (!empty($d['device']) && ($d['type'] ?? '') !== 'Flash' && $k !== 'flash') $used[$d['device']] = "assigned in array $n";
  }
  $cfg = ma_x_ini($ctx['plugcfg'].'/multiarray.cfg');
  foreach ($cfg as $k => $v) if (preg_match('/^DISKS([2-9])$/', $k, $m) && (int)$m[1] != $target)
    foreach (preg_split('/\s+/', trim($v), -1, PREG_SPLIT_NO_EMPTY) as $name) {
      if (strpbrk($name, '/') !== false) continue;
      $r = @readlink($ctx['byid']."/$name"); if ($r) $used[basename($r)] = $used[basename($r)] ?? "pinned to array {$m[1]}";
    }
  $mounted = [];
  foreach (preg_split('/\n/', (string)@file_get_contents($ctx['mounts'])) as $l) if (preg_match('#^/dev/([a-z]+[a-z0-9]*?)(p?\d+)?\s#', $l, $m)) $mounted[$m[1]] = true;
  foreach (preg_split('/\n/', (string)@file_get_contents($ctx['swaps'])) as $l) if (preg_match('#^/dev/([a-z]+[a-z0-9]*?)(p?\d+)?\s#', $l, $m)) $mounted[$m[1]] = true;
  $flash = null; foreach (ma_x_ini($ctx['emhttp'].'/disks.ini', true) as $k => $d) if ($k === 'flash') $flash = $d['device'] ?? null;
  $out = [];
  foreach ((array)@scandir($ctx['sys'].'/block') as $dev) {
    if (!preg_match('/^(sd[a-z]+|hd[a-z]+|vd[a-z]+|nvme\d+n\d+)$/', $dev)) continue;
    $info = ma_x_devinfo($ctx, $dev); if (!$info) continue;
    $info['id'] = $tid[$dev] ?? '';
    $no = [];
    if ($dev === $flash) $no[] = 'the boot flash';
    if (isset($used[$dev])) $no[] = $used[$dev];
    if (isset($mounted[$dev])) $no[] = 'mounted (Unassigned Devices or by hand)';
    if ($info['holders']) $no[] = 'held by '.implode(', ', $info['holders']);
    if ($info['id'] === '' && !$no) $no[] = $target == 1 ? 'not offered by the main array' : "not offered by array $target";
    $info['not_free'] = $no;
    $out[$dev] = $info;
  }
  return $out;
}

/* the target must be stopped and have nothing assigned; returns problems */
function ma_x_target_problems($ctx, $target) {
  $e = [];
  if ($target < 1 || $target > 9) return ["there is no array $target"];
  if (!ma_x_instance_up($ctx, $target)) return ["array $target's management instance is not running; start it from Settings > Multi Arrays (or its Main$target tab) with no disks assigned"];
  $tp = ma_x_paths($ctx, $target);
  $var = ma_x_ini($tp['state'].'/var.ini');
  if (($var['fsState'] ?? '') !== 'Stopped') $e[] = "array $target is not stopped (".($var['fsState'] ?? 'unknown').')';
  foreach (ma_x_ini($tp['state'].'/disks.ini', true) as $k => $d) {
    if (!isset($d['idx']) || ($d['type'] ?? '') === 'Flash' || $k === 'flash') continue;
    if (!in_array($d['type'] ?? '', ['Parity', 'Data'])) { if (($d['id'] ?? '') !== '' || ($d['idSb'] ?? '') !== '') $e[] = "pool device $k is assigned (the target must be a fresh, empty array)"; continue; }
    if (($d['id'] ?? '') !== '' || ($d['idSb'] ?? '') !== '') $e[] = "$k already has a disk assigned (the target must be a fresh, empty array)";
  }
  if ($target >= 2) {
    $cfg = ma_x_ini($ctx['plugcfg'].'/multiarray.cfg');
    if (trim($cfg["DISKS$target"] ?? '') !== '') $e[] = "array $target already has pinned disks (Settings > Multi Arrays); the target must have none";
  }
  return $e;
}

/* match like md: serial (after the last '_') and size; plus whole-disk sectors, sector size and partition layout.
 * returns ['ok'=>bool, 'rows'=>[slot rows], 'problems'=>[...]] */
function ma_x_plan($data, $target, $ctx = null) {
  $ctx = $ctx ?: ma_x_ctx();
  $problems = ma_x_validate($data, $ctx);
  if ($problems) return ['ok' => false, 'rows' => [], 'problems' => $problems];
  $problems = ma_x_target_problems($ctx, (int)$target);
  if (!ma_x_instance_up($ctx, (int)$target)) return ['ok' => false, 'rows' => [], 'problems' => $problems];
  $cand = ma_x_candidates($ctx, (int)$target);
  $rows = []; $taken = [];
  $slots = $data['slots']; usort($slots, fn($a, $b) => $a['slot'] <=> $b['slot']);
  foreach ($slots as $s) {
    $row = ['slot' => $s['slot'], 'role' => $s['role'], 'want' => ['id' => $s['id'], 'serial' => $s['serial'], 'size_kb' => $s['size_kb'], 'sectors' => $s['sectors'], 'model' => $s['model'] ?? ''],
            'disk' => null, 'problem' => null];
    $hits = []; $near = [];
    foreach ($cand as $dev => $c) {
      $serial = $c['id'] !== '' ? ma_x_serial($c['id']) : null;
      $same = $serial !== null && $serial === $s['serial'];
      if (!$same) {   // a disk the target does not offer (in use elsewhere) can still carry the serial: say so
        foreach ($c['by_id'] as $b) if (substr($b, -strlen('_'.$s['serial'])) === '_'.$s['serial'] || substr($b, -strlen('-'.$s['serial'])) === '-'.$s['serial']) { $near[] = "$dev (".implode('; ', $c['not_free'] ?: ['serial seen in its by-id name only']).')'; break; }
        continue;
      }
      $why = [];
      if ($c['sectors'] != $s['sectors']) $why[] = "size differs ({$c['sectors']} sectors, the export says {$s['sectors']})";
      if (!empty($s['logical_sector_size']) && $c['logical_sector_size'] != $s['logical_sector_size']) $why[] = "sector size differs ({$c['logical_sector_size']} vs {$s['logical_sector_size']})";
      $pp = $s['partition'] ?? null;
      if (!$c['partition']) $why[] = 'it has no partition 1';
      elseif ($pp && ($c['partition']['start'] != $pp['start'] || $c['partition']['size'] != $pp['size'])) $why[] = "partition 1 differs (start {$c['partition']['start']} size {$c['partition']['size']}, the export says start {$pp['start']} size {$pp['size']})";
      elseif (intdiv($c['partition']['size'], 2) != (int)$s['size_kb']) $why[] = 'partition size does not give the size md recorded';
      if ($c['not_free']) $why[] = 'not free: '.implode('; ', $c['not_free']);
      if ($why) { $near[] = "$dev (".implode('; ', $why).')'; continue; }
      $hits[] = $dev;
    }
    if (count($hits) == 1 && !isset($taken[$hits[0]])) {
      $c = $cand[$hits[0]]; $taken[$hits[0]] = 1;
      $row['disk'] = ['device' => $c['device'], 'id' => $c['id'], 'by_id' => $c['by_id'], 'serial' => ma_x_serial($c['id']), 'sectors' => $c['sectors'],
                      'size_kb' => intdiv($c['partition']['size'], 2), 'model' => $c['model']];
    } elseif (count($hits) > 1) $row['problem'] = 'more than one free disk matches ('.implode(', ', $hits).'); unplug the extra one';
    else $row['problem'] = 'no free disk with serial '.$s['serial'].' and the same size'.($near ? ': '.implode('; ', $near) : ' is connected');
    if ($row['problem']) $problems[] = "{$s['role']}: {$row['problem']}";
    $rows[] = $row;
  }
  $maxData = 0; foreach ($slots as $s) if ($s['slot'] >= 1 && $s['slot'] <= 28) $maxData = max($maxData, $s['slot']);
  $problems = array_merge($problems, ma_x_zfs_clashes($data, (int)$target, $ctx));
  [$pools, $notes] = ma_x_plan_pools($data, (int)$target, $cand, $taken);
  $notes = array_merge($notes, ma_x_plan_shares($data, (int)$target, $ctx));
  return ['ok' => !$problems, 'rows' => $rows, 'problems' => $problems, 'target' => (int)$target, 'max_data_slot' => $maxData,
          'encrypted' => !empty($data['encrypted']), 'settings' => $data['settings'] ?? [], 'warnings' => $data['warnings'] ?? [],
          'pools' => $pools, 'notes' => $notes];
}

/* zfs pool names the export asks for (array zfs disks: named after the slot; pools: their own name) */
function ma_x_wanted_zfs($data) {
  $w = [];
  foreach ($data['slots'] ?? [] as $s) { $t = ma_x_fsname($s['fs']['type'] ?? ''); if ($t === 'zfs') $w[$s['fs']['pool'] ?? $s['role']] = $s['role']; }   // version 1: no pool recorded, the slot name is Unraid's
  foreach ($data['pools'] ?? [] as $pl) if (($pl['fs'] ?? '') === 'zfs') $w[$pl['zfs_pool'] ?? $pl['name']] = 'pool '.$pl['name'];
  return $w;
}
/* zfs pool names are server-wide: a name already imported, or one another array (or array 1) is set up to use, can't
 * be used by the imported array too. Found here, in the plan, before anything is assigned (else only Start refuses). */
function ma_x_zfs_clashes($data, $target, $ctx) {
  $want = ma_x_wanted_zfs($data); if (!$want) return [];
  $imported = array_filter(array_map('trim', (array)call_user_func($ctx['zpools'] ?? 'ma_x_zpools')));
  $users = [];   // pool name => which array's configuration uses it
  for ($n = 1; $n <= 9; $n++) {
    if ($n == $target) continue;
    $st = $n == 1 ? $ctx['emhttp'] : $ctx['runroot']."/a$n/state";
    foreach (ma_x_ini("$st/disks.ini", true) as $k => $d) {
      if (strpos((string)($d['fsType'] ?? ''), 'zfs') === false || ($d['id'] ?? '') === '') continue;
      $name = ($d['type'] ?? '') === 'Cache' ? preg_replace('/\d+$/', '', (string)$k) : (string)$k;
      $users[$name] = $users[$name] ?? ($n == 1 ? 'array 1 (the main array) or its pools' : "array $n");
    }
  }
  $e = [];
  foreach ($want as $name => $what) {
    $who = isset($users[$name]) ? $users[$name] : (in_array($name, $imported, true) ? 'a pool that is imported on this server now' : null);
    if ($who === null) continue;
    $fix = strpos($what, 'pool ') === 0
      ? "rename one of the two pools first, for example on the source server (stop its array, click the pool name on the Main tab, change it) and export again, or rename the pool here"
      : "an array zfs disk always gets its slot's name, so this one can't be renamed. If the other is a pool you made yourself, rename it (example: zpool export $name; zpool import $name {$name}_old). If it is another array's zfs $name, the two can't run at the same time: keep that array stopped while this one runs, or move that disk's files off and reformat one of the two with xfs or btrfs";
    $e[] = "$what: its zfs pool name \"$name\" is already used by $who. zfs pool names are server-wide, so starting this array would be refused (or fail to import). To fix: $fix. Check with: zpool list -H -o name";
  }
  return $e;
}
function ma_x_zpools() { $o = []; @exec('/usr/sbin/zpool list -H -o name 2>/dev/null', $o); return $o; }
/* pools in the export: which disks here carry their members (same serial + size, free). Nothing is assigned: the plan
 * says how to add the pool on the target's Main tab; the found disks are pinned with the array so array 1 does not offer them. */
function ma_x_plan_pools($data, $target, $cand, $taken) {
  $out = []; $notes = [];
  foreach ($data['pools'] ?? [] as $pl) {
    $found = []; $missing = [];
    foreach ($pl['devices'] ?? [] as $m) {
      $hit = null;
      foreach ($cand as $dev => $c) if (!isset($taken[$dev]) && !$c['not_free'] && $c['id'] !== '' && ma_x_serial($c['id']) === $m['serial'] && (!$m['sectors'] || $c['sectors'] == $m['sectors'])) { $hit = $c; break; }
      if ($hit) { $found[] = ['member' => $m['member'], 'device' => $hit['device'], 'id' => $hit['id'], 'by_id' => $hit['by_id']]; $taken[$hit['device']] = 1; }
      else $missing[] = "{$m['member']} (serial {$m['serial']})";
    }
    $out[] = ['name' => $pl['name'], 'fs' => $pl['fs'] ?? null, 'profile' => $pl['profile'] ?? '', 'found' => $found, 'missing' => $missing];
    $what = trim(($pl['fs'] ?? '').' '.($pl['profile'] ?? ''));
    $how = $target == 1 ? 'Main' : "Main$target";
    $n = "pool {$pl['name']}".($what !== '' ? " ($what)" : '').': not created by the import. After it, on the '.$how.' tab: Add Pool, name '.$pl['name'].', '.max(count($pl['devices'] ?? []), 1).' slot(s), assign '
       .($found ? implode(', ', array_map(fn($f) => "{$f['id']} ({$f['device']}) as {$f['member']}", $found)) : 'its disks')
       .', then start: Unraid reads the existing file system, so do not format it (Format would erase the pool).';
    if ($missing) $n .= ' Not connected or not free here: '.implode(', ', $missing).'; without them the pool starts degraded or not at all.';
    if ($found && $target >= 2) $n .= " Its found disks are pinned to array $target with the slots, so array 1 does not offer them.";
    $notes[] = $n;
  }
  return [$out, $notes];
}
/* shares in the export (their settings files). For an extra array target they are written into its own flash folder
 * (arrayN/shares), only where no share of that name exists; for array 1 they are listed for doing by hand. */
function ma_x_plan_shares($data, $target, $ctx) {
  $sh = $data['shares'] ?? []; if (!$sh) return [];
  $names = array_map(fn($s) => (string)($s['name'] ?? ''), $sh);
  if ($target == 1) return ['shares '.implode(', ', $names).': their settings are in the export file ("shares"). They are not written into array 1, where shares of the same name may exist; set them on the Shares tab (for example which pool a share uses and its mover direction).'];
  $dir = $ctx['plugcfg']."/array$target/shares"; $new = []; $kept = [];
  foreach ($names as $n) if (is_file("$dir/$n.cfg")) $kept[] = $n; else $new[] = $n;
  $t = [];
  if ($new) $t[] = 'shares '.implode(', ', $new).": their settings (pool, mover direction, export) are written into array $target's config with the assignments; the folders themselves are on the disks.";
  if ($kept) $t[] = 'shares '.implode(', ', $kept).": array $target already has settings for them; those are kept. Compare them with the export file if they should match.";
  return $t;
}

/* ---------------- apply (the only part that changes anything; always after an explicit confirm) ---------------- */

/* hash of what the user confirmed: slot -> device + id. apply refuses when the fresh plan differs */
function ma_x_plan_hash($plan) {
  $k = [$plan['target']];
  foreach ($plan['rows'] as $r) $k[] = $r['slot'].'='.($r['disk']['device'] ?? '').'|'.($r['disk']['id'] ?? '').'|'.($r['want']['serial'] ?? '');
  foreach ($plan['pools'] ?? [] as $pl) foreach ($pl['found'] as $f) $k[] = "pool {$pl['name']} {$f['member']}={$f['device']}|{$f['id']}";
  return substr(hash('sha256', implode("\n", $k)), 0, 16);
}

/* POST a command to the target's own emhttpd (array 1: the host's; N: instance N's), the same request its Main page makes */
function ma_x_emcmd($target, $body) {
  if ($target == 1) {
    $tok = (ma_x_ini('/var/local/emhttp/var.ini')['csrf_token'] ?? '');
    $c = curl_init('http://localhost/update.htm');
    curl_setopt_array($c, [CURLOPT_UNIX_SOCKET_PATH => '/var/run/emhttpd.socket', CURLOPT_POST => 1, CURLOPT_RETURNTRANSFER => 1,
      CURLOPT_POSTFIELDS => 'csrf_token='.rawurlencode($tok).'&'.$body, CURLOPT_TIMEOUT => 600]);
    curl_exec($c); $st = curl_getinfo($c, CURLINFO_HTTP_CODE); curl_close($c);
    return $st;
  }
  require_once '/usr/local/emhttp/plugins/multiarray/include/lib.php';
  exec('/usr/local/emhttp/plugins/multiarray/scripts/multiarray guard '.(int)$target.' '.escapeshellarg($body).' 2>&1', $why, $rc);   // same guard as the GUI forwarder
  if ($rc !== 0) { error_log('multiarray import: '.implode(' ', $why)); return 409; }
  return ma_cmd($target, $body)[0];
}

/* share settings files of the export into an extra array's shares/ folder; an existing file is never replaced */
function ma_x_write_shares($data, $dir) {
  $log = [];
  foreach ($data['shares'] ?? [] as $sh) {
    $n = (string)($sh['name'] ?? '');
    if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9 ._-]*$/', $n) || !is_array($sh['settings'] ?? null)) { $log[] = "share \"$n\": not written (unusual name or no settings)"; continue; }
    if (is_file("$dir/$n.cfg")) { $log[] = "share $n: settings kept (it exists already)"; continue; }
    $t = ''; foreach ($sh['settings'] as $k => $v) if (preg_match('/^[A-Za-z0-9_.]+$/', (string)$k) && is_scalar($v)) $t .= $k.'="'.str_replace(['"', "\n", "\r"], '', (string)$v)."\"\n";
    @mkdir($dir, 0755, true);
    $log[] = file_put_contents("$dir/$n.cfg", $t) !== false ? "share $n: settings written" : "share $n: could not write its settings";
  }
  return $log;
}

function ma_x_wait($fn, $secs) { for ($i = 0; $i < $secs * 2; $i++) { if ($fn()) return true; usleep(500000); } return (bool)$fn(); }

function ma_x_pick_byid($byid) {
  foreach (['ata-', 'scsi-', 'nvme-', 'usb-'] as $pre) foreach ($byid as $b) if (strncmp($b, $pre, strlen($pre)) === 0) return $b;
  foreach ($byid as $b) if (strncmp($b, 'wwn-', 4) !== 0 && strncmp($b, 'eui.', 4) !== 0) return $b;
  return $byid[0] ?? null;
}

/* set DISKS<N>="..." in multiarray.cfg (the plugin's own settings file), keeping every other line */
function ma_x_set_pins($ctx, $n, $names) {
  $f = $ctx['plugcfg'].'/multiarray.cfg';
  $lines = is_file($f) ? preg_split('/\r?\n/', rtrim((string)file_get_contents($f), "\n")) : [];
  $new = "DISKS$n=\"".implode(' ', $names).'"'; $done = false;
  foreach ($lines as &$l) if (strncmp($l, "DISKS$n=", strlen("DISKS$n=")) === 0) { $l = $new; $done = true; }
  unset($l);
  if (!$done) $lines[] = $new;
  return file_put_contents("$f.new", implode("\n", $lines)."\n") !== false && rename("$f.new", $f);
}

/* returns ['ok'=>bool, 'log'=>[lines], 'error'=>text] */
function ma_x_apply($data, $target, $hash, $ctx = null) {
  $ctx = $ctx ?: ma_x_ctx(); $target = (int)$target; $log = [];
  $plan = ma_x_plan($data, $target, $ctx);
  if (!$plan['ok']) return ['ok' => false, 'log' => $log, 'error' => 'The plan is not clean any more: '.implode('; ', $plan['problems'])];
  if (!hash_equals(ma_x_plan_hash($plan), (string)$hash)) return ['ok' => false, 'log' => $log, 'error' => 'The disks changed since you confirmed the plan. Check the plan again.'];
  $tp = ma_x_paths($ctx, $target);
  if ($target >= 2) {   // 1. pin the disks to the target: hidden from the main array and every other array from now on
    $names = [];
    foreach ($plan['rows'] as $r) { $b = ma_x_pick_byid($r['disk']['by_id']); if (!$b) return ['ok' => false, 'log' => $log, 'error' => "{$r['role']}: the disk has no /dev/disk/by-id name to pin"]; $names[] = $b; }
    foreach ($plan['pools'] ?? [] as $pl) foreach ($pl['found'] as $f) { $b = ma_x_pick_byid($f['by_id']); if ($b) $names[] = $b; }
    if (!ma_x_set_pins($ctx, $target, $names)) return ['ok' => false, 'log' => $log, 'error' => 'could not write multiarray.cfg'];
    $log[] = "pinned to array $target: ".implode(' ', $names);
    exec('/usr/local/emhttp/plugins/multiarray/scripts/multiarray refresh 2>&1', $o, $rc);
    $want = array_map(fn($r) => $r['disk']['device'], $plan['rows']);
    $seen = ma_x_wait(function() use ($tp, $want) { $d = []; foreach (ma_x_ini($tp['state'].'/devs.ini', true) as $x) $d[] = $x['device'] ?? ''; return !array_diff($want, $d); }, 30);
    if (!$seen) return ['ok' => false, 'log' => $log, 'error' => "array $target does not list the pinned disks yet; nothing was assigned. Reload and check the plan again."];
    $log[] = "array $target sees the pinned disks";
  }
  /* 2. enough slots for the highest data slot (the stock Main page's slot count, changeSlots) */
  $var = ma_x_ini($tp['state'].'/var.ini');
  if ($plan['max_data_slot'] > (int)($var['SYS_ARRAY_SLOTS'] ?? 0)) {
    $st = ma_x_emcmd($target, 'changeSlots=apply&SYS_ARRAY_SLOTS='.$plan['max_data_slot']);
    $ok = ma_x_wait(fn() => (int)(ma_x_ini($tp['state'].'/var.ini')['SYS_ARRAY_SLOTS'] ?? 0) >= $plan['max_data_slot'], 20);
    $log[] = "slots set to {$plan['max_data_slot']} (HTTP $st)";
    if (!$ok) return ['ok' => false, 'log' => $log, 'error' => 'the slot count did not change'];
  }
  /* 3. one assignment per slot, the request the Main page's slot dropdown sends (changeDevice + slotId.<idx>) */
  foreach ($plan['rows'] as $r) {
    $st = ma_x_emcmd($target, 'changeDevice=apply&slotId.'.$r['slot'].'='.rawurlencode($r['disk']['id']));
    $ok = ma_x_wait(function() use ($tp, $r) { $d = ma_x_ini($tp['state'].'/disks.ini', true)[$r['role']] ?? []; return ($d['id'] ?? '') === $r['disk']['id']; }, 20);
    $log[] = "{$r['role']} <- {$r['disk']['id']} ({$r['disk']['device']}): ".($ok ? 'assigned' : 'NOT assigned')." (HTTP $st)";
    if (!$ok) return ['ok' => false, 'log' => $log, 'error' => "{$r['role']} was not assigned; stopped here. The slots assigned so far can be unassigned on the Main$target tab."];
  }
  if ($target >= 2) $log = array_merge($log, ma_x_write_shares($data, $ctx['plugcfg']."/array$target/shares"));
  $var = ma_x_ini($tp['state'].'/var.ini');
  $log[] = "array $target state: ".($var['mdState'] ?? '?');
  return ['ok' => true, 'log' => $log, 'mdState' => $var['mdState'] ?? '', 'error' => null];
}

/* start an imported, not encrypted array with "Parity is already valid" (the stock NEW_ARRAY start form) */
function ma_x_start($data, $target, $ctx = null) {
  $ctx = $ctx ?: ma_x_ctx(); $target = (int)$target;
  $tp = ma_x_paths($ctx, $target);
  $var = ma_x_ini($tp['state'].'/var.ini'); $disks = ma_x_ini($tp['state'].'/disks.ini', true);
  if (($var['mdState'] ?? '') !== 'NEW_ARRAY') return ['ok' => false, 'error' => "array $target is in state ".($var['mdState'] ?? '?').', not a new array waiting to start; start it from its Main tab'];
  if (!empty($data['encrypted'])) return ['ok' => false, 'error' => 'the array is encrypted: start it from its Main tab, tick "Parity is already valid" and give the passphrase there'];
  foreach ($data['slots'] as $s) {
    $d = $disks[$s['role']] ?? [];
    if (ma_x_serial($d['id'] ?? '') !== $s['serial']) return ['ok' => false, 'error' => "{$s['role']} does not hold the exported disk (serial {$s['serial']}); not started"];
  }
  foreach ($disks as $k => $d) if (in_array($d['type'] ?? '', ['Parity', 'Data']) && ($d['id'] ?? '') !== '' && !in_array($k, array_column($data['slots'], 'role')))
    return ['ok' => false, 'error' => "$k holds a disk that is not in the export; not started"];
  $parity = (bool)array_filter($data['slots'], fn($s) => $s['slot'] == 0 || $s['slot'] == 29);
  $st = ma_x_emcmd($target, 'startState=NEW_ARRAY&file='.($parity ? '&md_invalidslot=99' : '').'&cmdStart=Start');
  $ok = ma_x_wait(fn() => in_array(ma_x_ini($tp['state'].'/var.ini')['fsState'] ?? '', ['Started']), 120);
  $v = ma_x_ini($tp['state'].'/var.ini');
  return ['ok' => $ok, 'error' => $ok ? null : 'the array did not start; see its Main tab and the syslog', 'mdState' => $v['mdState'] ?? '', 'fsState' => $v['fsState'] ?? '',
          'resync' => $v['mdResyncAction'] ?? '', 'running' => (int)($v['mdResyncPos'] ?? 0) > 0, 'http' => $st];
}

/* read-only parity check (the Check button with "Write corrections to parity" unticked) */
function ma_x_check($target, $ctx = null) {
  $ctx = $ctx ?: ma_x_ctx(); $target = (int)$target;
  $tp = ma_x_paths($ctx, $target);
  $v = ma_x_ini($tp['state'].'/var.ini');
  if (($v['mdState'] ?? '') !== 'STARTED') return ['ok' => false, 'error' => "array $target is not started"];
  if ((int)($v['mdResyncPos'] ?? 0) > 0) return ['ok' => false, 'error' => "a check or sync is already running on array $target"];
  $st = ma_x_emcmd($target, 'startState=STARTED&cmdCheck=Check');
  $ok = ma_x_wait(fn() => (int)(ma_x_ini($tp['state'].'/var.ini')['mdResyncPos'] ?? 0) > 0 || (int)(ma_x_ini($tp['state'].'/var.ini')['mdResync'] ?? 0) > 0, 20);
  $v = ma_x_ini($tp['state'].'/var.ini');
  return ['ok' => $ok, 'error' => $ok ? null : 'the check did not start', 'action' => $v['mdResyncAction'] ?? '', 'corr' => $v['mdResyncCorr'] ?? '', 'http' => $st];
}

/* the running or last parity check of array N, from its var.ini: percent, errors (sbSyncErrs: so far, then found), exit code */
function ma_x_check_status($target, $ctx = null) {
  $ctx = $ctx ?: ma_x_ctx(); $target = (int)$target;
  if ($target < 1 || $target > 9 || !ma_x_instance_up($ctx, $target)) return ['ok' => false, 'error' => "array $target is not running"];
  $v = ma_x_ini(ma_x_paths($ctx, $target)['state'].'/var.ini');
  $pos = (int)($v['mdResyncPos'] ?? 0); $size = (int)($v['mdResyncSize'] ?? 0);
  $running = $pos > 0;
  return ['ok' => true, 'running' => $running, 'percent' => $running && $size > 0 ? round(100 * $pos / $size, 1) : 100,
          'errors' => (int)($v['sbSyncErrs'] ?? 0), 'exit' => (int)($v['sbSyncExit'] ?? 0), 'action' => (string)($v['mdResyncAction'] ?? '')];
}
