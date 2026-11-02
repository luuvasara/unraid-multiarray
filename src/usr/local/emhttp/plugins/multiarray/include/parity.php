<?PHP
/* copy of dynamix ParityControl.php for instance N: pause/resume bookkeeping in the per-instance stamps file */
require_once '/usr/local/emhttp/plugins/multiarray/include/lib.php';
$n = ma_n($_POST['n'] ?? 0);
$stamps = ma_vartmp($n).'/stamps.ini';
switch ($_POST['action'] ?? '') {
case 'pause':
  if (!file_exists($stamps)) file_put_contents($stamps, @parse_ini_file(ma_state($n).'/var.ini')['sbSynced']);
case 'resume':
  file_put_contents($stamps, ','.time(), FILE_APPEND);
  break;
}
