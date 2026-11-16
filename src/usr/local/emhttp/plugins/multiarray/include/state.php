<?PHP
/* Pool-state check before Start for extra array N: POST n=N&pools=a,b. Same output as the stock Report.php cmd=state
 * (empty = no pool in an ERROR: state, else one line per pool), but read from array N's own disks.ini: the stock
 * one reads array 1's. N is cast to an integer 2..9 by ma_n(); no path comes from the request. */
require_once '/usr/local/emhttp/plugins/multiarray/include/lib.php';
$n = ma_n($_POST['n'] ?? 0);
$ini = ma_state($n).'/disks.ini';
$disks = is_file($ini) ? @parse_ini_file($ini, true) : false;
if (!is_array($disks)) {   // never a silent pass: without the state file the pools cannot be checked
  echo htmlspecialchars("Array $n: its disk state ($ini) could not be read, so its pools could not be checked. Check that array $n's instance is up: multiarray status");
  exit;
}
$err = [];
foreach (explode(',', (string)($_POST['pools'] ?? '')) as $pool) {
  $state = (string)($disks[$pool]['state'] ?? '');
  if ($pool !== '' && stripos($state, 'ERROR:') === 0) $err[] = htmlspecialchars($pool.' - '.str_ireplace('ERROR:', '', $state));
}
echo implode('<br>', $err);
