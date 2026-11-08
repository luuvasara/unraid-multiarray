<?php
/* Drive map endpoint, and the same from a shell.
 *
 * Web: POST, so Unraid's prologue (local_prepend.php) checks csrf_token before this runs.
 *   action=list              {"ok":true,"version":"...","drives":[record, ...]}
 *   action=lookup&name=ata7  {"ok":true,"name":"ata7","drive":record|null,"matches":n}
 *   action=scan&text=...     {"ok":true,"hits":[{"name":"ata7","drive":record}, ...]}
 * Shell: php drivemap.php list | lookup NAME | scan [TEXT, else standard input]   (JSON on standard output) */
require_once __DIR__ . '/DriveMap.php';

function drivemap_answer(string $action, array $in): array {
  $map = DriveMap::shared();
  switch ($action) {
    case 'list':
      return ['ok' => true, 'version' => DriveMap::VERSION, 'drives' => $map->drives()];
    case 'lookup':
      $name = substr((string)($in['name'] ?? ''), 0, 200);
      $all = $map->lookupAll($name);
      return ['ok' => true, 'name' => $name, 'drive' => count($all) === 1 ? $all[0] : null, 'matches' => count($all),
              'label' => count($all) === 1 ? DriveMap::label($all[0]) : ''];
    case 'scan':
      // one word is a name someone typed: every drive it can mean (nvme3 can be a slot and a controller); more is log text
      $text = trim(substr((string)($in['text'] ?? ''), 0, 8192));
      $hits = preg_match('/^\S+$/', $text) ? array_map(function ($d) use ($text) { return ['name' => $text, 'drive' => $d]; }, $map->lookupAll($text)) : $map->scan($text);
      if (!$hits && preg_match('/^\S+$/', $text)) $hits = $map->scan($text);
      return ['ok' => true, 'hits' => array_map(function ($h) { return $h + ['label' => DriveMap::label($h['drive'])]; }, $hits)];
  }
  return ['ok' => false, 'error' => 'Unknown action'];
}

if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
  $action = $argv[1] ?? 'list';
  $in = $action === 'lookup' ? ['name' => $argv[2] ?? ''] : ['text' => $argv[2] ?? stream_get_contents(STDIN)];
  echo json_encode(drivemap_answer($action, $in), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE), "\n";
  exit(0);
}
if (PHP_SAPI !== 'cli') {
  header('Content-Type: application/json');
  if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { http_response_code(405); echo '{"ok":false,"error":"POST only"}'; exit; }
  echo json_encode(drivemap_answer((string)($_POST['action'] ?? ''), $_POST), JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
}
