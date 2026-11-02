<?PHP
/* Forwarder: POST /plugins/multiarray/include/update.php?n=N  ->  instance N's emhttpd /update.htm.
 * Host GUI auth + csrf (local_prepend) already passed; the host csrf_token was stripped from $_POST; we append instance N's. */
require_once '/usr/local/emhttp/plugins/multiarray/include/lib.php';
$n = ma_n($_GET['n'] ?? 0);
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !ma_ready($n)) { http_response_code(409); echo 'instance not running'; exit; }
ignore_user_abort(true); set_time_limit(0);
$pairs = array_filter(explode('&', file_get_contents('php://input')), fn($p) => $p !== '' && strncmp($p, 'csrf_token=', 11) !== 0);
[$st, $body] = ma_send($n, implode('&', array_merge($pairs, ['csrf_token='.rawurlencode(ma_token($n))])));
http_response_code($st ?: 502);
header('Content-Type: text/html; charset=utf-8');
echo $body;
