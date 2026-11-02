<?PHP
/* multiarray control endpoint. GET ?action=status ; POST action=start|stop&n=N (csrf_token checked by local_prepend) */
$bin = '/usr/local/emhttp/plugins/multiarray/scripts/multiarray';
header('Content-Type: application/json');
$action = $_REQUEST['action'] ?? 'status';
$n = isset($_REQUEST['n']) ? (int)$_REQUEST['n'] : 0;
if ($action == 'status') {
  require_once '/usr/local/emhttp/plugins/multiarray/include/lib.php';
  $d = json_decode(shell_exec("$bin status-json"), true) ?: [];
  foreach ($d as &$i) $i['ready'] = ma_ready($i['n']);
  echo json_encode($d); exit; }
if (($action == 'start' || $action == 'stop') && $n >= 2 && $n <= 9 && $_SERVER['REQUEST_METHOD'] == 'POST') {
  shell_exec("nohup setsid $bin $action $n >/dev/null 2>&1 &");
  echo json_encode(['ok' => true]); exit;
}
http_response_code(400); echo json_encode(['ok' => false]);
