<?PHP
/* copy of dynamix ToggleState.php: spin up/down + clear stats for instance N (emcmd -> forwarded to emhttpd N) */
require_once '/usr/local/emhttp/plugins/multiarray/include/lib.php';
$n = ma_n($_POST['n'] ?? 0);
$device = $_POST['device'] ?? ''; $name = $_POST['name'] ?? ''; $action = $_POST['action'] ?? '';
if (!ma_ready($n) || !preg_match('/^[A-Za-z0-9_*]*$/', $device.$name.$action)) { http_response_code(400); exit; }
switch ($device) {
case 'New':   $c = "cmdSpin{$action}=".urlencode($name); break;
case 'Clear': $c = 'clearStatistics=true'; break;
default:
  if (!$name) { $c = "cmdSpin{$device}All=Apply"; break; }
  if (substr($name,-1) != '*') { $c = "cmdSpin{$action}=".urlencode($name); break; }
  $c = "cmdSpin{$action}All=Apply&poolName=".urlencode(substr($name,0,-1));
}
ma_cmd($n, $c);
