<?php
/* Drive map: every name a drive goes by on this server, joined into one record per drive.
 *
 * Logs and tools each name a drive their own way: the kernel says sdk, ata7 or 7:0:0:0, btrfs says devid 2, Unraid
 * says disk3 or cache2, a SMART report gives the serial. DriveMap reads what the server already knows, read-only, and
 * answers "which drive is this" for any of those names.
 *
 *   $map = DriveMap::shared();
 *   $map->drives();                 every drive, one record each (see record() for the fields)
 *   $map->lookup('ata7');           the record, or null when the name is unknown or names more than one drive
 *   $map->lookupAll('host0');       every record a name fits
 *   $map->btrfs($fsid, 2);          the member with devid 2 of that btrfs filesystem
 *   $map->scan($logLine);           the drive names found in a line of text, with their records
 *   DriveMap::label($record);       "disk3 (sda, ST8000VN004-2M2101, serial ZA1B2C3D)"
 *
 * Sources: /var/local/emhttp/disks.ini and devs.ini (Unraid's slots), /sys/block and /dev/disk (kernel names, links,
 * ports), lsblk (serial, WWN, model, size, partitions, file system ids), `btrfs filesystem show --mounted` and
 * `zpool status` (pool members). Nothing here wakes a drive: sysfs, lsblk and the two pool commands answer from the
 * kernel, and btrfs is asked about mounted file systems only, so it scans no devices.
 *
 * PHP 7.4 and newer, no extensions beyond the defaults. Set DRIVEMAP_ROOT to read a captured tree instead of /;
 * the commands then read <root>/cmd/<name>. Results are cached for CACHE_SECONDS in /var/run/drivemap, and sooner when
 * disks.ini or devs.ini change. */

if (!class_exists('DriveMap')) {

class DriveMap {
  const VERSION = '2026.11.08';
  const CACHE_SECONDS = 30;
  const SKIP = '/^(loop|ram|zram|nbd|sr|fd)\d/';

  private $root;
  private $cacheDir;
  private $drives = null;
  private $index = [];
  private $btrfs = [];
  private static $shared = null;

  public function __construct(array $opt = []) {
    $this->root = rtrim((string)($opt['root'] ?? (getenv('DRIVEMAP_ROOT') ?: '')), '/');
    $this->cacheDir = array_key_exists('cache', $opt) ? $opt['cache'] : ($this->root === '' ? '/var/run/drivemap' : null);
  }

  public static function shared(): self {
    if (self::$shared === null) self::$shared = new self();
    return self::$shared;
  }

  /* Forget what was read, so the next call reads the server again. A long-running process calls this now and then. */
  public function refresh(): void { $this->drives = null; $this->index = []; $this->btrfs = []; }

  public function drives(): array { $this->load(); return $this->drives; }

  /* $prefer, 'kernel', 'unraid' or 'id', settles a name that two kinds of name share; see names(). */
  public function lookup(string $name, string $prefer = ''): ?array {
    $all = $this->lookupAll($name, $prefer);
    return count($all) === 1 ? $all[0] : null;
  }

  public function lookupAll(string $name, string $prefer = ''): array {
    $this->load();
    $hits = $this->index[self::key($name)] ?? [];
    $seen = [];
    foreach ($hits as [$i, $kind]) $seen[$i][] = $kind;
    if ($prefer !== '' && count($seen) > 1) {
      $pick = array_filter($seen, function ($kinds) use ($prefer) { return in_array($prefer, $kinds, true); });
      if ($pick) $seen = $pick;
    }
    $out = [];
    foreach (array_keys($seen) as $i) $out[] = $this->drives[$i];
    return $out;
  }

  public function btrfs(string $fsid, int $devid): ?array {
    $this->load();
    $i = $this->btrfs[strtolower($fsid)][$devid] ?? null;
    return $i === null ? null : $this->drives[$i];
  }

  /* The drive names in a line of text. Kernel names, ports, md and mapper devices, serials, WWNs, Unraid's ids and
   * numbered slot names (disk3, cache2) count; bare words such as "parity" or "cache" do not, because they are also
   * ordinary words in log text. A btrfs devid counts when the line names the file system, by "(device sdk1)" or by
   * its fsid. Returns [['name' => as written, 'drive' => record], ...], one entry per drive, in order of appearance. */
  public function scan(string $text): array {
    $this->load();
    $hits = []; $seen = [];
    $add = function (string $name, ?array $d) use (&$hits, &$seen) {
      if ($d === null || isset($seen[$d['key']])) return;
      $seen[$d['key']] = true;
      $hits[] = ['name' => $name, 'drive' => $d];
    };
    $fs = null;
    if (preg_match('/\(device ([a-z0-9]+)\)/', $text, $m)) { $d = $this->lookup($m[1]); $fs = $d['btrfs']['fsid'] ?? null; }
    if (preg_match('/\bfsid[ =:]+([0-9a-f]{8}-[0-9a-f-]{27})/i', $text, $m)) $fs = strtolower($m[1]);
    preg_match_all('#[A-Za-z0-9_./:+-]+#', $text, $tok, PREG_OFFSET_CAPTURE);
    $words = $tok[0];
    foreach ($words as $n => [$w]) {
      if ($fs !== null && strtolower($w) === 'devid' && isset($words[$n + 1]) && ctype_digit($words[$n + 1][0])) {
        $add("devid {$words[$n + 1][0]}", $this->btrfs($fs, (int)$words[$n + 1][0]));
        continue;
      }
      $w = rtrim($w, ':.,;');
      $kind = $w === '' ? '' : $this->scannable($w);
      if ($kind === '') continue;
      $add($w, $this->lookup($w, $kind));
    }
    return $hits;
  }

  /* One line that names a drive the way a person finds it: the Unraid slot first, then the kernel name, model, serial. */
  public static function label(array $d): string {
    $first = $d['slot'] !== '' ? $d['slot'] : $d['device'];
    $more = array_values(array_filter([$d['slot'] !== '' ? $d['device'] : '', $d['model'], $d['serial'] !== '' ? "serial {$d['serial']}" : '']));
    return $more ? "$first (" . implode(', ', $more) . ')' : $first;
  }

  /* ---- reading ---- */

  private function load(): void {
    if ($this->drives !== null) return;
    $cached = $this->readCache();
    if ($cached === null) {
      $cached = $this->build();
      $this->writeCache($cached);
    }
    [$this->drives, $this->index, $this->btrfs] = [$cached['drives'], $cached['index'], $cached['btrfs']];
  }

  private function build(): array {
    $drives = [];
    $byDev = [];
    $lsblk = $this->lsblk();
    foreach ($this->blockDevices() as $kname) {
      $d = self::record();
      $d['device'] = $kname;
      $this->fromSysfs($d, $kname);
      if (isset($lsblk[$kname])) $this->fromLsblk($d, $lsblk[$kname]);
      $byDev[$kname] = count($drives);
      $drives[] = $d;
    }
    $this->fromDiskLinks($drives, $byDev);
    $this->fromUnraid($drives, $byDev);
    $this->fromDeviceMapper($drives, $byDev);
    $btrfs = $this->fromBtrfs($drives, $byDev);
    $this->fromZfs($drives, $byDev);
    foreach ($drives as &$d) $d['key'] = $d['unraid_id'] !== '' ? $d['unraid_id'] : ($d['serial'] !== '' ? $d['serial'] : $d['device']);
    unset($d);
    $index = [];
    foreach ($drives as $i => $d) {
      foreach (self::names($d) as [$n, $kind]) {
        $k = self::key($n);
        if ($k !== '' && !in_array([$i, $kind], $index[$k] ?? [], true)) $index[$k][] = [$i, $kind];
      }
    }
    return ['version' => self::VERSION, 'built' => time(), 'drives' => $drives, 'index' => $index, 'btrfs' => $btrfs];
  }

  /* The fields of a drive record. Empty string or empty list when unknown. */
  public static function record(): array {
    return [
      'key' => '',            // stable id of the record: Unraid's id, else the serial, else the kernel name
      'slot' => '',           // Unraid slot: parity, parity2, disk1, cache, cache2, flash, or devN for unassigned
      'slot_type' => '',      // Parity, Data, Cache, Flash, Unassigned
      'pool' => '',           // array, the pool's name, or flash
      'unraid_id' => '',      // Unraid's identification, model_serial, as on the Main page
      'device' => '',         // kernel name: sda, nvme0n1
      'partitions' => [],     // sda1
      'md' => [],             // Unraid's md device for an array slot: md1p1, and md1 as older releases name it
      'mapper' => [],         // encrypted: /dev/mapper/md1p1 and dm-3
      'transport' => '',      // sata, sas, nvme, usb, scsi, virtio
      'ata' => '',            // ata5
      'hctl' => '',           // 5:0:0:0
      'scsi_host' => '',      // host5
      'sas_port' => '',       // port-0:3
      'nvme' => '',           // nvme0, the controller
      'usb' => '',            // 2-2, the USB port path
      'pci' => '',            // 0000:00:17.0, the controller's PCI address
      'pci_path' => [],       // every PCI address from the root port down to the controller (bridges, switch ports)
      'port_no' => null,      // the drive's port on its SATA/AHCI controller, counted from 1 (ata port_no)
      'model' => '', 'serial' => '', 'wwn' => '', 'size' => 0, 'rotational' => null,
      'by_id' => [], 'by_path' => [], // the drive's own links in /dev/disk
      'part_links' => [],     // the links of its partitions: ata-..._SERIAL-part1, by-uuid ids are not here
      'fs' => '', 'fs_uuid' => '', 'fs_label' => '',   // fs: Unraid's file system of the slot, else what lsblk sees
      'encrypted' => false,
      'btrfs' => null,        // ['fsid' => ..., 'devid' => 2, 'label' => ...]
      'zfs' => null,          // ['pool' => ..., 'guid' => ...]
    ];
  }

  /* Every name a record answers to, with its kind: kernel (the kernel's names and ports), unraid (slot and Unraid's
   * id) or id (serial, WWN, links, uuids). A name can mean two drives only across kinds: on a server with a pool
   * called "nvme", nvme3 is the slot of one drive and the NVMe controller of another. */
  private static function names(array $d): array {
    $n = [];
    $put = function ($kind, $names) use (&$n) { foreach ((array)$names as $x) if ($x !== '' && $x !== null) $n[] = [$x, $kind]; };
    $put('kernel', array_merge([$d['device'], $d['ata'], $d['hctl'], $d['scsi_host'], $d['sas_port'], $d['nvme']], $d['partitions'], $d['md'], $d['mapper']));
    if ($d['sas_port'] !== '') $put('kernel', 'end_device-' . substr($d['sas_port'], 5));
    $put('unraid', [$d['slot'], $d['unraid_id']]);
    $put('id', array_merge([$d['serial'], $d['wwn'], $d['fs_uuid']], $d['by_id'], $d['by_path'], $d['part_links']));
    if (preg_match('/^0x(.+)$/', $d['wwn'], $m)) $put('id', $m[1]);
    if ($d['zfs']) $put('id', $d['zfs']['guid']);
    if ($d['btrfs']) $put('id', "btrfs:{$d['btrfs']['fsid']}:{$d['btrfs']['devid']}");
    return $n;
  }

  /* How names compare: no /dev/ or /dev/disk/by-*\/ in front, no case. */
  public static function key(string $name): string {
    $n = strtolower(trim($name));
    $n = preg_replace('#^/dev/(disk/by-[a-z]+/|mapper/(?=.))?#', '', $n) ?? $n;
    $n = preg_replace('/^(ata\d+)\.\d+$/', '$1', $n) ?? $n;            // ata5.00, the device on port ata5
    $n = preg_replace('/^(nvme\d+)c\d+(n\d+(p\d+)?)$/', '$1$2', $n) ?? $n;  // nvme0c0n1, a path of nvme0n1
    return $n;
  }

  /* Whether scan() looks a token up, and as which kind of name: a kernel name in log text means the kernel's device
   * even where a slot has the same name. Plain words are never looked up. */
  private function scannable(string $w): string {
    if (preg_match('#^(/dev/\S+|sd[a-z]{1,3}\d*|nvme\d+(n\d+(p\d+)?)?|nvme\d+c\d+n\d+(p\d+)?|md\d+(p\d+)?|dm-\d+|ata\d+(\.\d+)?|\d+:\d+:\d+:\d+|host\d+|port-\d+:\d+|end_device-\d+:\d+)$#', $w)) return 'kernel';
    if (preg_match('/^[a-z]+\d+$/', $w)) return 'unraid';                                 // numbered slots: disk3, cache2, parity2
    if (strlen($w) >= 8 && preg_match('/\d/', $w) && preg_match('/^[A-Za-z0-9_.:-]+$/', $w)) return 'id'; // serials, WWNs, ids, uuids
    return '';
  }

  private function path(string $p): string { return $this->root . $p; }

  private function read(string $p): string {
    $v = @file_get_contents($this->path($p));
    return $v === false ? '' : trim($v);
  }

  /* A command's output, or in a captured tree the file <root>/cmd/<name>. */
  private function command(string $name, array $argv): string {
    if ($this->root !== '') return (string)@file_get_contents("{$this->root}/cmd/$name");
    $p = @proc_open($argv, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
    if (!is_resource($p)) return '';
    $out = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    proc_close($p);
    return (string)$out;
  }

  private function blockDevices(): array {
    $out = [];
    foreach (@scandir($this->path('/sys/block')) ?: [] as $n) {
      if ($n[0] === '.' || preg_match(self::SKIP, $n) || preg_match('/^(md|dm-)/', $n)) continue;   // md and dm sit on drives; they are names of one, not drives
      $out[] = $n;
    }
    return $out;
  }

  /* Where the kernel hangs the device: ata port, SCSI address, SAS port, NVMe controller, USB port, PCI address. */
  private function fromSysfs(array &$d, string $kname): void {
    $real = @realpath($this->path("/sys/block/$kname"));
    $p = $real === false ? '' : substr($real, strlen($this->root));
    if (preg_match('#/(ata\d+)/#', $p, $m)) $d['ata'] = $m[1];
    if (preg_match('#/(host\d+)/#', $p, $m)) $d['scsi_host'] = $m[1];
    if (preg_match('#/(\d+:\d+:\d+:\d+)/block/#', $p, $m)) $d['hctl'] = $m[1];
    if (preg_match('#/(port-\d+:\d+)/#', $p, $m)) $d['sas_port'] = $m[1];
    if (preg_match('#/nvme/(nvme\d+)/#', $p, $m)) $d['nvme'] = $m[1];
    if (preg_match('#/usb\d+/(?:[\d.-]+/)*?(\d+-[\d.]+)/\d+-[\d.]+:\d#', $p, $m)) $d['usb'] = $m[1];
    if (preg_match_all('#/([0-9a-f]{4}:[0-9a-f]{2}:[0-9a-f]{2}\.[0-9a-f])(?=/)#', $p, $m)) { $d['pci'] = end($m[1]); $d['pci_path'] = $m[1]; }
    if ($d['ata'] !== '') { $n = $this->read("/sys/class/ata_port/{$d['ata']}/port_no"); if (ctype_digit($n)) $d['port_no'] = (int)$n; }
    $d['transport'] = $d['nvme'] !== '' ? 'nvme' : ($d['usb'] !== '' ? 'usb' : ($d['ata'] !== '' ? 'sata' : ($d['sas_port'] !== '' ? 'sas' : (strpos($p, '/virtio') !== false ? 'virtio' : ($d['hctl'] !== '' ? 'scsi' : '')))));
    $d['model'] = $this->read("/sys/block/$kname/device/model");
    $d['serial'] = $this->read("/sys/block/$kname/device/serial");
    $d['size'] = (int)$this->read("/sys/block/$kname/size") * 512;
    $rot = $this->read("/sys/block/$kname/queue/rotational");
    $d['rotational'] = $rot === '' ? null : $rot === '1';
    foreach (@scandir($this->path("/sys/block/$kname")) ?: [] as $c) {
      if (strpos($c, $kname) === 0 && $c !== $kname) {
        $d['partitions'][] = $c;
        foreach (@scandir($this->path("/sys/block/$kname/$c/holders")) ?: [] as $h) if (strpos($h, 'dm-') === 0) $d['mapper'][] = $h;
      }
    }
    foreach (@scandir($this->path("/sys/block/$kname/holders")) ?: [] as $h) if (strpos($h, 'dm-') === 0) $d['mapper'][] = $h;
  }

  private function lsblk(): array {
    $j = json_decode($this->command('lsblk.json', ['lsblk', '-J', '-b', '-o', 'NAME,KNAME,TYPE,SIZE,MODEL,SERIAL,WWN,TRAN,HCTL,FSTYPE,UUID,LABEL,PKNAME']), true);
    $out = [];
    foreach ($j['blockdevices'] ?? [] as $b) $out[$b['kname'] ?? $b['name']] = $b;
    return $out;
  }

  private function fromLsblk(array &$d, array $b): void {
    foreach (['model' => 'model', 'serial' => 'serial', 'wwn' => 'wwn'] as $f => $k) {
      $v = trim((string)($b[$k] ?? ''));
      if ($v !== '') $d[$f] = $v;
    }
    if (!empty($b['size'])) $d['size'] = (int)$b['size'];
    $tran = (string)($b['tran'] ?? '');
    if ($tran !== '' && $d['transport'] === '') $d['transport'] = $tran;
    if ($d['hctl'] === '' && !empty($b['hctl'])) $d['hctl'] = $b['hctl'];
    // the file system: on the disk itself, else on its first partition that has one
    foreach (array_merge([$b], $b['children'] ?? []) as $c) {
      if (empty($c['fstype'])) continue;
      $d['fs'] = $c['fstype']; $d['fs_uuid'] = (string)($c['uuid'] ?? ''); $d['fs_label'] = (string)($c['label'] ?? '');
      if ($c['fstype'] === 'crypto_LUKS') $d['encrypted'] = true;
      break;
    }
  }

  /* /dev/disk/by-id and by-path: the links name the disk or one of its partitions. */
  private function fromDiskLinks(array &$drives, array $byDev): void {
    foreach (['by-id' => 'by_id', 'by-path' => 'by_path'] as $dir => $field) {
      foreach (@scandir($this->path("/dev/disk/$dir")) ?: [] as $l) {
        if ($l[0] === '.') continue;
        $t = @readlink($this->path("/dev/disk/$dir/$l"));
        if ($t === false) continue;
        $dev = basename($t);
        $disk = $this->owner($dev, $byDev);
        if ($disk === null) continue;
        $drives[$byDev[$disk]][$dev === $disk ? $field : 'part_links'][] = $l;   // a partition's link names the drive too
      }
    }
  }

  /* The disk a kernel name belongs to: sda1 -> sda, nvme0n1p1 -> nvme0n1. */
  private function owner(string $dev, array $byDev): ?string {
    if (isset($byDev[$dev])) return $dev;
    if (preg_match('/^(nvme\d+n\d+|mmcblk\d+)p\d+$/', $dev, $m) && isset($byDev[$m[1]])) return $m[1];
    if (preg_match('/^(sd[a-z]+|vd[a-z]+|xvd[a-z]+|hd[a-z]+)\d+$/', $dev, $m) && isset($byDev[$m[1]])) return $m[1];
    return null;
  }

  /* Unraid's slots: disks.ini for the array, pools and flash; devs.ini for the unassigned. The pool of a slot is its name
   * without the trailing number or ~subpool, as webgui's pool_name() has it. */
  private function fromUnraid(array &$drives, array $byDev): void {
    $disks = @parse_ini_file($this->path('/var/local/emhttp/disks.ini'), true, INI_SCANNER_RAW) ?: [];
    foreach ($disks as $slot => $s) {
      $dev = (string)($s['device'] ?? '');
      if ($dev === '' || !isset($byDev[$dev])) continue;
      $d = &$drives[$byDev[$dev]];
      $type = (string)($s['type'] ?? '');
      $d['slot'] = (string)($s['name'] ?? $slot);
      $d['slot_type'] = $type;
      $d['unraid_id'] = (string)($s['id'] ?? '');
      $d['pool'] = ($type === 'Parity' || $type === 'Data') ? 'array' : ($type === 'Flash' ? 'flash' : (preg_replace('/(\d+$|~.*$)/', '', $d['slot']) ?? $d['slot']));
      $sb = (string)($s['deviceSb'] ?? '');
      if (preg_match('/^md(\d+)p\d+$/', $sb, $m)) { $d['md'][] = $sb; $d['md'][] = "md{$m[1]}"; }
      elseif ($type === 'Data' && preg_match('/^disk(\d+)$/', $d['slot'], $m)) $d['md'][] = "md{$m[1]}";
      if ((int)($s['luksState'] ?? 0) > 0) { $d['encrypted'] = true; if ($sb !== '') $d['mapper'][] = "/dev/mapper/$sb"; }
      if (($s['fsType'] ?? '') !== '' && $s['fsType'] !== 'auto') $d['fs'] = (string)$s['fsType'];
      elseif ($d['fs'] === 'crypto_LUKS') $d['fs'] = '';
      unset($d);
    }
    $devs = @parse_ini_file($this->path('/var/local/emhttp/devs.ini'), true, INI_SCANNER_RAW) ?: [];
    foreach ($devs as $slot => $s) {
      $dev = (string)($s['device'] ?? '');
      if ($dev === '' || !isset($byDev[$dev]) || $drives[$byDev[$dev]]['slot'] !== '') continue;
      $drives[$byDev[$dev]]['slot'] = (string)($s['name'] ?? $slot);
      $drives[$byDev[$dev]]['slot_type'] = 'Unassigned';
      $drives[$byDev[$dev]]['unraid_id'] = (string)($s['id'] ?? '');
    }
  }

  /* dm-N devices (encryption): /sys/block/dm-N/dm/name and slaves name the mapper and what it sits on, an md device for
   * an array slot or a partition for a pool. */
  private function fromDeviceMapper(array &$drives, array $byDev): void {
    foreach (@scandir($this->path('/sys/block')) ?: [] as $dm) {
      if (strpos($dm, 'dm-') !== 0) continue;
      $name = $this->read("/sys/block/$dm/dm/name");
      foreach (@scandir($this->path("/sys/block/$dm/slaves")) ?: [] as $sl) {
        if ($sl[0] === '.') continue;
        $disk = preg_match('/^md\d+(p\d+)?$/', $sl) ? $this->mdOwner($drives, $sl) : $this->owner($sl, $byDev);
        if ($disk === null) continue;
        $d = &$drives[$byDev[$disk]];
        foreach ([$dm, $name !== '' ? "/dev/mapper/$name" : ''] as $n) if ($n !== '' && !in_array($n, $d['mapper'], true)) $d['mapper'][] = $n;
        unset($d);
      }
    }
  }

  /* btrfs members by devid, mounted file systems only: --mounted asks the kernel, so no drive is read or woken. */
  private function fromBtrfs(array &$drives, array $byDev): array {
    $map = []; $fsid = null; $label = '';
    foreach (explode("\n", $this->command('btrfs-show', ['btrfs', 'filesystem', 'show', '--mounted', '--raw'])) as $line) {
      if (preg_match("/^Label: (?:'(.*)'|none)\s+uuid: ([0-9a-f-]{36})/", $line, $m)) { $label = $m[1] ?? ''; $fsid = $m[2]; continue; }
      if ($fsid === null || !preg_match('#^\s+devid\s+(\d+)\s.*\spath\s+(\S+)#', $line, $m)) continue;
      $dev = basename($m[2]);
      if (preg_match('#^/dev/mapper/(.+)$#', $m[2], $mm)) $dev = $this->mapperOwner($drives, $mm[1]) ?? $dev;
      if (preg_match('/^md(\d+)(p\d+)?$/', $dev)) $dev = $this->mdOwner($drives, $dev) ?? $dev;
      $disk = $this->owner($dev, $byDev);
      if ($disk === null) continue;
      $i = $byDev[$disk];
      $drives[$i]['btrfs'] = ['fsid' => $fsid, 'devid' => (int)$m[1], 'label' => $label];
      $map[$fsid][(int)$m[1]] = $i;
    }
    return $map;
  }

  private function mdOwner(array $drives, string $md): ?string {
    foreach ($drives as $d) if (in_array($md, $d['md'], true)) return $d['device'];
    return null;
  }

  private function mapperOwner(array $drives, string $name): ?string {
    foreach ($drives as $d) if (in_array("/dev/mapper/$name", $d['mapper'], true)) return $d['device'];
    if (preg_match('/^(md\d+p\d+)$/', $name)) return $this->mdOwner($drives, $name);
    return null;
  }

  /* zfs vdev guids: `zpool status -P` and `-gP` print the same tree, one with paths, one with guids. */
  private function fromZfs(array &$drives, array $byDev): void {
    $paths = $this->zpoolTree($this->command('zpool-status-P', ['zpool', 'status', '-P']));
    $guids = $this->zpoolTree($this->command('zpool-status-gP', ['zpool', 'status', '-gP']));
    foreach ($paths as $k => [$pool, $path]) {
      if (!isset($guids[$k]) || strpos($path, '/dev/') !== 0) continue;
      $dev = basename($path);
      if (preg_match('#^/dev/mapper/(.+)$#', $path, $m)) $dev = $this->mapperOwner($drives, $m[1]) ?? $dev;
      $disk = $this->owner($dev, $byDev);
      if ($disk !== null) $drives[$byDev[$disk]]['zfs'] = ['pool' => $pool, 'guid' => $guids[$k][1]];
    }
  }

  /* The config lines of each pool, keyed by pool and position: [pool, first column]. */
  private function zpoolTree(string $out): array {
    $rows = []; $pool = null; $in = false; $n = 0;
    foreach (explode("\n", $out) as $line) {
      if (preg_match('/^\s*pool:\s+(\S+)/', $line, $m)) { $pool = $m[1]; $in = false; $n = 0; continue; }
      if (preg_match('/^\s*config:/', $line)) { $in = true; continue; }
      if (preg_match('/^\s*errors:/', $line)) { $in = false; continue; }
      if (!$in || $pool === null || !preg_match('/^\t\s*(\S+)\s+[A-Z]+/', $line, $m) || $m[1] === 'NAME') continue;
      $rows["$pool#" . $n++] = [$pool, $m[1]];
    }
    return $rows;
  }

  /* ---- cache ---- */

  private function cacheFile(): ?string {
    return $this->cacheDir === null ? null : "{$this->cacheDir}/drivemap-" . self::VERSION . '.json';
  }

  private function stamp(): string {
    $s = '';
    foreach (['/var/local/emhttp/disks.ini', '/var/local/emhttp/devs.ini', '/sys/block'] as $f) $s .= @filemtime($this->path($f)) . ':';
    return $s;
  }

  private function readCache(): ?array {
    $f = $this->cacheFile();
    if ($f === null) return null;
    $c = json_decode((string)@file_get_contents($f), true);
    if (!is_array($c) || ($c['version'] ?? '') !== self::VERSION || ($c['stamp'] ?? '') !== $this->stamp()) return null;
    if (time() - (int)($c['built'] ?? 0) > self::CACHE_SECONDS) return null;
    return $c;
  }

  private function writeCache(array $c): void {
    $f = $this->cacheFile();
    if ($f === null) return;
    if (!is_dir($this->cacheDir)) @mkdir($this->cacheDir, 0755, true);
    $c['stamp'] = $this->stamp();
    $tmp = "$f." . getmypid();
    if (@file_put_contents($tmp, json_encode($c)) !== false) @rename($tmp, $f);
  }
}

}
