<?php
/* The release notes: the .plg's <CHANGES> as plain text, put into the package by the build.
 * [['version' => '2026.11.05a', 'items' => ['...', ...]], ...], newest first; empty when the file is missing. */
function kit_changes(string $f): array {
  $out = [];
  foreach (@file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $l) {
    if (strncmp($l, '###', 3) === 0) $out[] = ['version' => trim(substr($l, 3)), 'items' => []];
    elseif ($out && strncmp($l, '- ', 2) === 0) $out[count($out) - 1]['items'][] = substr($l, 2);
  }
  return $out;
}
