<?PHP
/* Endpoint of Settings > Multi Arrays > Disks. Logic: include/disks.php.
 * GET  ?action=inventory              every disk, grouped (no disk is read; temperatures and SMART from Unraid's files)
 * GET  ?action=locate_status          which disk is being located
 * POST action=find&text=...           the disks a name or a log line means
 * POST action=detect                  start the found-array check in the background (reads free disks, never writes or mounts)
 * POST action=parity&parity=sdX&data[]=sdY...   start the parity plausibility check in the background (reads only)
 * GET  ?action=job                     state and result of that background check
 * POST action=prepare&roles[dev]=...  an import plan in the export format, marked detected
 * POST action=locate&dev=sdX[&spinup=yes]   read the disk for 20 s so its light flashes; action=locate_stop
 * POST action=cabling&switch=...&order[]=...   the confirmed cabling of an enclosure unit
 * POST requests carry csrf_token, checked by Unraid's local_prepend before this runs. */
require_once __DIR__.'/disks.php';
header('Content-Type: application/json');
header('Cache-Control: no-store');
function ma_d_reply($code, $a) { http_response_code($code); echo json_encode($a, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE); exit; }
function ma_d_pver() { $p = @file_get_contents('/boot/config/plugins/multiarray.plg'); return preg_match('/<!ENTITY version\s+"([^"]+)">/', (string)$p, $m) ? $m[1] : ''; }
$action = (string)($_REQUEST['action'] ?? '');
$post = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';
if (!$post && !in_array($action, ['inventory', 'locate_status', 'job'], true)) ma_d_reply(405, ['ok' => false, 'error' => 'POST only']);
switch ($action) {
case 'inventory':
  ma_d_reply(200, ['ok' => true] + ma_d_inventory());
case 'locate_status':
  ma_d_reply(200, ['ok' => true] + ma_d_locate_status());
case 'find':
  ma_d_reply(200, ['ok' => true, 'hits' => ma_d_find((string)($_POST['text'] ?? ''))]);
case 'detect':
  ma_d_reply(200, ma_d_job_start('detect'));
case 'parity':
  $devs = array_merge([(string)($_POST['parity'] ?? '')], array_map('strval', (array)($_POST['data'] ?? [])));
  foreach ($devs as $d) if (!preg_match('/^(sd[a-z]+|hd[a-z]+|vd[a-z]+|nvme\d+n\d+)$/', $d)) ma_d_reply(400, ['ok' => false, 'error' => 'not a disk name']);
  ma_d_reply(200, ma_d_job_start('parity', $devs));
case 'job':
  ma_d_reply(200, ['ok' => true] + ma_d_job_status());
case 'prepare':
  $last = json_decode((string)@file_get_contents(ma_d_last_detect_file()), true);
  $r = ma_d_prepare((array)($_POST['roles'] ?? []), null, ma_d_pver(), $last);
  ma_d_reply($r['ok'] ? 200 : 409, $r);
case 'locate':
  $dev = (string)($_POST['dev'] ?? '');
  $spun = false;
  foreach (ma_d_inventory()['groups'] as $g) foreach ($g['disks'] as $d) if ($d['device'] === $dev) $spun = $d['spundown'];
  ma_d_reply(200, ma_d_locate_start($dev, $spun, ($_POST['spinup'] ?? '') === 'yes'));
case 'locate_stop':
  ma_d_reply(200, ma_d_locate_stop());
case 'cabling':
  ma_d_reply(200, ma_d_cabling_save((string)($_POST['switch'] ?? ''), (array)($_POST['order'] ?? [])));
}
ma_d_reply(400, ['ok' => false, 'error' => 'unknown action']);
