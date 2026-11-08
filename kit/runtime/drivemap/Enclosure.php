<?php
/* Enclosures: which bay of a disk enclosure a drive sits in, from the drive's PCI path and port.
 *
 * A profile (enclosures/<id>.json next to this file) names the controller chip of the enclosure's card, the PCIe switch
 * the controllers sit behind, the groups of bays each cable feeds and the front layout. Its "calibration" holds two
 * lists measured on a real unit:
 *   port_to_position    for a controller's port 1..n, the position of its bay in the group (a fact of the card);
 *   card_port_to_group  for the card's controllers in PCI address order, the group of bays its cable feeds. This one
 *                       depends on how the cables are plugged, so it is a default (card_port_to_group_default: true)
 *                       until the plugin passes a unit's confirmed cabling: ['cabling' => [switch address => [group, ...]]].
 * A list that is null means unknown: the drive is then shown by its controller (PCI order, counted from 1) and port,
 * never by a guessed bay.
 *
 *   $enc = new DriveEnclosures();                  // or ['root' => captured tree, 'dir' => profile dir, 'cabling' => [...]]
 *   $places = $enc->place(DriveMap::shared()->drives());   // [drive key => place], drives in no known enclosure left out
 *   $place = ['profile' => id, 'name' => 'QNAP TL-D1600S', 'unit' => 1, 'switch' => '0000:01:00.0', 'controller' => 2,
 *             'port' => 3, 'group' => 1|null, 'bay' => 'HDD3'|null, 'confirmed' => bool, 'label' => '...']
 *   $enc->units($places);                          // each enclosure found: profile, unit, front grid with the drive keys
 *
 * Reads sysfs only (/sys/bus/pci/devices/<address>/vendor, device). PHP 7.4 and newer. */

if (!class_exists('DriveEnclosures')) {

class DriveEnclosures {
  private $root;
  private $dir;
  private $cabling;
  private $profiles = null;
  private $ids = [];

  public function __construct(array $opt = []) {
    $this->root = rtrim((string)($opt['root'] ?? (getenv('DRIVEMAP_ROOT') ?: '')), '/');
    $this->dir = (string)($opt['dir'] ?? (__DIR__ . '/enclosures'));
    $this->cabling = (array)($opt['cabling'] ?? []);
  }

  /* Every profile, keyed by id. A profile that does not parse or lacks a field is left out. */
  public function profiles(): array {
    if ($this->profiles !== null) return $this->profiles;
    $this->profiles = [];
    foreach (glob($this->dir . '/*.json') ?: [] as $f) {
      $p = json_decode((string)@file_get_contents($f), true);
      if (!is_array($p) || empty($p['id']) || empty($p['match']['controller']) || empty($p['groups']) || empty($p['front'])) continue;
      $p['calibration'] = ($p['calibration'] ?? []) + ['card_port_to_group' => null, 'port_to_position' => null, 'card_port_to_group_default' => true];
      $this->profiles[$p['id']] = $p;
    }
    ksort($this->profiles);
    return $this->profiles;
  }

  /* vendor:device of a PCI address, lower case without 0x: "1b21:1164"; '' when unknown */
  public function pciId(string $addr): string {
    if (isset($this->ids[$addr])) return $this->ids[$addr];
    $v = $this->read("/sys/bus/pci/devices/$addr/vendor"); $d = $this->read("/sys/bus/pci/devices/$addr/device");
    return $this->ids[$addr] = ($v !== '' && $d !== '') ? strtolower(preg_replace('/^0x/i', '', $v) . ':' . preg_replace('/^0x/i', '', $d)) : '';
  }

  /* A cabling the plugin may store for a unit: one group per controller, each group once. */
  public static function validCabling(array $p, $order): bool {
    $n = (int)($p['match']['controllers'] ?? count($p['groups']));
    if (!is_array($order) || count($order) !== $n) return false;
    $order = array_map('strval', array_values($order));
    return count(array_unique($order)) === $n && !array_diff($order, array_map('strval', array_keys($p['groups'])));
  }

  public function place(array $drives): array {
    $out = [];
    foreach ($this->profiles() as $p) {
      $unit = 0;
      foreach ($this->unitsOf($p) as $sw => $ctls) {
        $unit++;
        foreach ($drives as $d) {
          if (empty($d['pci_path']) || isset($out[$d['key']])) continue;
          $i = array_search(end($d['pci_path']), $ctls, true);
          if ($i !== false) $out[$d['key']] = $this->where($p, $unit, $sw, $i + 1, $d['port_no'] ?? null);
        }
      }
    }
    return $out;
  }

  /* The enclosures found: [['profile' => id, 'name' => ..., 'unit' => 1, 'switch' => address, 'cabling' => [group, ...]|null,
   * 'confirmed' => bool, 'controllers' => [addr, ...], 'groups' => profile groups,
   * 'front' => [[['bay' => 'HDD9', 'key' => drive key or null], ...], ...], 'unplaced' => [drive keys with no bay]], ...] */
  public function units(array $places): array {
    $out = [];
    foreach ($this->profiles() as $p) {
      $unit = 0;
      foreach ($this->unitsOf($p) as $sw => $ctls) {
        $unit++;
        $byBay = []; $unplaced = [];
        foreach ($places as $key => $pl) {
          if ($pl['profile'] !== $p['id'] || $pl['unit'] !== $unit) continue;
          if ($pl['bay'] !== null) $byBay[$pl['bay']] = $key; else $unplaced[] = $key;
        }
        $front = [];
        foreach ($p['front'] as $row) $front[] = array_map(function ($b) use ($byBay) { return ['bay' => $b, 'key' => $byBay[$b] ?? null]; }, $row);
        [$cab, $confirmed] = $this->cablingOf($p, $sw);
        $out[] = ['profile' => $p['id'], 'name' => $p['name'], 'unit' => $unit, 'switch' => $sw, 'cabling' => $cab, 'confirmed' => $confirmed,
                  'ports_known' => is_array($p['calibration']['port_to_position']), 'controllers' => $ctls, 'groups' => $p['groups'],
                  'front' => $front, 'unplaced' => $unplaced];
      }
    }
    return $out;
  }

  /* ---- matching ---- */

  /* [group list or null, confirmed] for one unit: the plugin's confirmed cabling, else the profile's default */
  private function cablingOf(array $p, string $sw): array {
    $c = $this->cabling[$sw] ?? null;
    if ($c !== null && self::validCabling($p, $c)) return [array_map('intval', array_values($c)), true];
    $d = $p['calibration']['card_port_to_group'];
    return [is_array($d) ? $d : null, is_array($d) && empty($p['calibration']['card_port_to_group_default'])];
  }

  /* The units of a profile on this server: [switch upstream address => its controllers in PCI address order]. A controller
   * with the right id behind no switch of the profile's id is not this enclosure's card. */
  private function unitsOf(array $p): array {
    $want = strtolower($p['match']['controller']);
    $switch = strtolower((string)($p['match']['switch'] ?? ''));
    $units = [];
    foreach (@scandir($this->path('/sys/bus/pci/devices')) ?: [] as $addr) {
      if ($addr[0] === '.' || $this->pciId($addr) !== $want) continue;
      $real = @realpath($this->path("/sys/bus/pci/devices/$addr"));
      if ($real === false) continue;
      preg_match_all('#/([0-9a-f]{4}:[0-9a-f]{2}:[0-9a-f]{2}\.[0-9a-f])(?=/)#', substr($real, strlen($this->root)) . '/', $m);
      $top = null;
      foreach ($m[1] as $a) if ($a !== $addr && $switch !== '' && $this->pciId($a) === $switch) { $top = $a; break; }   // the switch's upstream port
      if ($switch !== '' && $top === null) continue;
      $units[$top ?? 'none'][] = $addr;
    }
    ksort($units);
    $max = (int)($p['match']['controllers'] ?? 0);
    $out = [];
    foreach ($units as $sw => $ctls) {
      sort($ctls);
      if ($max > 0 && count($ctls) > $max) continue;   // more controllers than the card has: not this card
      $out[(string)$sw] = $ctls;
    }
    return $out;
  }

  private function where(array $p, int $unit, string $sw, int $ctl, ?int $port): array {
    [$cab, $confirmed] = $this->cablingOf($p, $sw);
    $ptp = $p['calibration']['port_to_position'];
    $group = is_array($cab) ? ($cab[$ctl - 1] ?? null) : null;
    $pos = (is_array($ptp) && $port !== null) ? ($ptp[$port - 1] ?? null) : null;
    $bay = ($group !== null && $pos !== null) ? ($p['groups'][(string)$group][$pos - 1] ?? null) : null;
    $name = "{$p['name']} $unit";
    if ($bay !== null) $label = "$name, $bay" . ($confirmed ? '' : ' (cabling not confirmed)');
    elseif ($group !== null) { $g = array_values($p['groups'][(string)$group] ?? []); $label = "$name, cable $group" . ($g ? " ({$g[0]}-" . end($g) . ')' : '') . ($port !== null ? ", port $port" : ''); }
    else $label = "$name, controller $ctl" . ($port !== null ? ", port $port" : '');
    return ['profile' => $p['id'], 'name' => $p['name'], 'unit' => $unit, 'switch' => $sw, 'controller' => $ctl, 'port' => $port,
            'group' => $group === null ? null : (int)$group, 'bay' => $bay, 'confirmed' => $confirmed, 'label' => $label];
  }

  private function path(string $p): string { return $this->root . $p; }

  private function read(string $p): string {
    $v = @file_get_contents($this->path($p));
    return $v === false ? '' : trim($v);
  }
}

}
