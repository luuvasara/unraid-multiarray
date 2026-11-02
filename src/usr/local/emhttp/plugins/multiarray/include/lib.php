<?PHP
/* multiarray: helpers shared by the generated MainN pages, the nchan copies and the forwarder endpoints.
 * Instance N's emhttpd state/socket directories are host-visible: /var/lib/multiarray/aN/{state,run}. */
const MA_ROOT = '/var/lib/multiarray';
function ma_n($n) { $n = (int)$n; if ($n < 2 || $n > 9) { http_response_code(400); exit; } return $n; }
function ma_state($n) { return MA_ROOT."/a$n/state"; }
function ma_sock($n)  { return MA_ROOT."/a$n/run/emhttpd.socket"; }
function ma_ready($n) { return is_file(MA_ROOT."/a$n/ns.id") && is_file(ma_state($n).'/var.ini'); }
function ma_token($n) { $v = @parse_ini_file(ma_state($n).'/var.ini'); return $v['csrf_token'] ?? ''; }

/* POST a urlencoded body to instance N's emhttpd /update.htm (csrf_token of the instance appended). Returns [status, headers-less body]. */
function ma_send($n, $body) {
  $c = curl_init('http://localhost/update.htm');
  curl_setopt_array($c, [CURLOPT_UNIX_SOCKET_PATH => ma_sock($n), CURLOPT_POST => 1, CURLOPT_RETURNTRANSFER => 1,
    CURLOPT_POSTFIELDS => $body, CURLOPT_TIMEOUT => 600]);
  $r = curl_exec($c); $st = curl_getinfo($c, CURLINFO_HTTP_CODE); curl_close($c);
  return [$st, $r];
}
function ma_cmd($n, $cmd) { return ma_send($n, 'csrf_token='.rawurlencode(ma_token($n)).'&'.$cmd); }

/* Swap the page globals template.php derived from the HOST state for instance N's state; ma_leave() restores them. */
function ma_enter($n) {
  $keys = ['devs','disks','sec','sec_nfs','shares','users','var','pools','pool_devices','bootpools','bootpool_devices'];
  $save = []; foreach ($keys as $k) $save[$k] = $GLOBALS[$k] ?? null;
  $s = ma_state($n);
  foreach (['devs','disks','sec','sec_nfs','shares','users'] as $k) $GLOBALS[$k] = (array)@parse_ini_file("$s/$k.ini", true);
  $GLOBALS['var'] = (array)@parse_ini_file("$s/var.ini");
  $d = $GLOBALS['disks'];
  $GLOBALS['pools'] = pools_filter($d); $GLOBALS['pool_devices'] = false;
  foreach ($GLOBALS['pools'] as $p) $GLOBALS['pool_devices'] |= _var($d[$p], 'devices') != '';
  $GLOBALS['bootpools'] = flash_filter($d); $GLOBALS['bootpool_devices'] = false;
  foreach ($GLOBALS['bootpools'] as $p) $GLOBALS['bootpool_devices'] |= _var($d[$p], 'devices') != '';
  return $save;
}
function ma_leave($save) { foreach ($save as $k => $v) $GLOBALS[$k] = $v; }

/* copies of Helpers.php last_parity_log()/last_parity_check() with instance-N paths */
function ma_log($n) { return MA_ROOT."/cfg$n/parity-checks.log"; }
function ma_vartmp($n) { $d = MA_ROOT."/a$n/vartmp"; @mkdir($d, 0755, true); return $d; }
function ma_last_parity_log($n) {
  $log = ma_log($n);
  if (file_exists($log)) [$date,$duration,$speed,$status,$error,$action,$size] = my_explode('|', tail($log), 7);
  else [$date,$duration,$speed,$status,$error,$action,$size] = array_fill(0, 7, 0);
  if ($date) { [$y,$m,$d,$t] = my_preg_split('/ +/', $date, 4); $date = strtotime("$d-$m-$y $t"); }
  return [$date,$duration,$speed,$status,$error,$action,$size];
}
function ma_last_parity_check($n) {
  global $var;
  $stamps = ma_vartmp($n).'/stamps.ini'; $resync = ma_vartmp($n).'/resync.ini';
  $synced = file_exists($stamps) ? explode(',', file_get_contents($stamps)) : [];
  $sbSynced = array_shift($synced) ?: _var($var,'sbSynced',0);
  $idle = []; while (count($synced) > 1) $idle[] = array_pop($synced) - array_pop($synced);
  $action = _var($var,'mdResyncAction'); $size = _var($var,'mdResyncSize',0);
  if (file_exists($resync)) list($action,$size) = my_explode(',', file_get_contents($resync));
  $duration = $var['sbSynced2'] - $sbSynced - array_sum($idle);
  $status = _var($var,'sbSyncExit');
  $speed = $status == 0 ? round($size*1024/$duration) : 0;
  return [$duration,$speed,$status,_var($var,'sbSyncErrs',0),$action,$size];
}

/* ---- disk pinning (host side) ---- */
/* device names (sdX) of every disk pinned to an extra array: DISKS<N>="by-id-name ..." in multiarray.cfg */
function ma_pinned_devices() {
  $cfg = @parse_ini_file('/boot/config/plugins/multiarray/multiarray.cfg') ?: [];
  $out = [];
  foreach ($cfg as $k => $v) if (preg_match('/^DISKS[2-9]$/', $k))
    foreach (preg_split('/\s+/', trim($v)) as $name) {
      if ($name === '' || strpbrk($name, '/') !== false) continue;
      $r = @realpath("/dev/disk/by-id/$name"); if ($r) $out[basename($r)] = (int)substr($k, 5);
    }
  /* plus every disk a running extra array has assigned, pinned or not, so array 1 can never be offered it */
  for ($n = 2; $n <= 9; $n++)
    foreach ((array)@parse_ini_file("/var/lib/multiarray/a$n/state/disks.ini", true) as $k => $d) if ($k !== "flash" && ($d["type"] ?? "") !== "Flash")
      if (!empty($d['device']) && !isset($out[$d['device']])) $out[$d['device']] = $n;
  return $out;   // device => array number
}
/* used by the generated host device_list override: drop pinned disks from the host Main's unassigned list / assign dropdowns */
function ma_filter_devs($devs) {
  $del = ma_pinned_devices();
  if (!$del) return $devs;
  return array_filter($devs, fn($d) => !isset($del[$d['device'] ?? '']));
}
