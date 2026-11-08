<?PHP
/* Export / import endpoint of Settings > Multi Arrays > Export and import.
 * GET  ?action=arrays            which arrays can be exported / imported into
 * GET  ?action=export&n=N        the export file (attachment) or 409 + {error, reasons, why}
 * GET  ?action=checkstatus&target=N  progress and result of the parity check of array N
 * POST action=plan|apply|start|check (csrf_token checked by Unraid's local_prepend before this runs) */
require_once '/usr/local/emhttp/plugins/multiarray/include/xport.php';
header('Content-Type: application/json');
header('Cache-Control: no-store');
$action = $_REQUEST['action'] ?? '';
$post = $_SERVER['REQUEST_METHOD'] === 'POST';
function reply($code, $a) { http_response_code($code); echo json_encode($a, JSON_UNESCAPED_SLASHES); exit; }
function pver() { $p = @file_get_contents('/boot/config/plugins/multiarray.plg'); return preg_match('/<!ENTITY version\s+"([^"]+)">/', (string)$p, $m) ? $m[1] : ''; }
function upload() { $d = json_decode((string)($_POST['json'] ?? ''), true); if (!is_array($d)) reply(400, ['ok' => false, 'error' => 'The file is not JSON from a Multi Arrays export.']); return $d; }
$ctx = ma_x_ctx();
switch ($action) {
case 'arrays':
  $cfg = ma_x_ini($ctx['plugcfg'].'/multiarray.cfg');
  $on = ($cfg['SERVICE'] ?? 'disable') === 'enable';
  $list = [];
  for ($n = 1; $n <= 9; $n++) {
    if ($n > 1 && (!$on || $n > (int)($cfg['ARRAYS'] ?? 3))) continue;
    $up = ma_x_instance_up($ctx, $n);
    $v = $up ? ma_x_ini(ma_x_paths($ctx, $n)['state'].'/var.ini') : [];
    $tp = ma_x_target_problems($ctx, $n);
    $list[] = ['n' => $n, 'up' => $up, 'mdState' => $v['mdState'] ?? '', 'fsState' => $v['fsState'] ?? '', 'importable' => !$tp,
               'why' => $up ? ((($v['fsState'] ?? '') !== 'Stopped') ? 'started' : 'has disks assigned or pinned') : 'not running'];
  }
  reply(200, ['ok' => true, 'service' => $on, 'arrays' => $list]);
case 'export':
  $r = ma_x_export((int)($_GET['n'] ?? 0), $ctx, pver());
  if (!$r['ok']) reply(409, $r);
  header('Content-Disposition: attachment; filename="'.ma_x_filename($r['data']).'"');
  echo json_encode($r['data'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES); exit;
case 'plan':
  if (!$post) reply(405, ['ok' => false]);
  $p = ma_x_plan(upload(), (int)($_POST['target'] ?? 0), $ctx);
  $p['code'] = $p['ok'] ? ma_x_plan_hash($p) : null;
  reply(200, $p);
case 'apply':
  if (!$post || ($_POST['confirm'] ?? '') !== 'yes') reply(400, ['ok' => false, 'error' => 'not confirmed']);
  ignore_user_abort(true); set_time_limit(0);
  reply(200, ma_x_apply(upload(), (int)($_POST['target'] ?? 0), (string)($_POST['code'] ?? ''), $ctx));
case 'start':
  if (!$post || ($_POST['confirm'] ?? '') !== 'yes') reply(400, ['ok' => false, 'error' => 'not confirmed']);
  ignore_user_abort(true); set_time_limit(0);
  reply(200, ma_x_start(upload(), (int)($_POST['target'] ?? 0), $ctx));
case 'checkstatus':
  reply(200, ma_x_check_status((int)($_GET['target'] ?? 0), $ctx));
case 'check':
  if (!$post) reply(405, ['ok' => false]);
  reply(200, ma_x_check((int)($_POST['target'] ?? 0), $ctx));
}
reply(400, ['ok' => false, 'error' => 'unknown action']);
