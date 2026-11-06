<?php
/* Local render harness (php -S, build host only; NOT the Unraid webGui). It mimics what pages rely on:
 * <html class="Theme--<theme>">, Unraid's stylesheets in DefaultPageLayout order, the global csrf_token and jQuery, and
 * the tabbed layout where every tab's page body renders into one DOM. Page bodies run unmodified.
 * Env: THEME, WEBROOT (dir holding plugins/<name> and webGui), PLUGIN, TABS ("Title=File,Title=File"), WEBGUI=6.12 for the
 * 6.12.15 stylesheets, ENDPOINT_RE (regex of include/*.php files to run), JQUERY (path of a jquery file to serve). */
$theme = getenv('THEME') ?: 'black';
$webroot = getenv('WEBROOT');
$plugin = getenv('PLUGIN');
$plug = "$webroot/plugins/$plugin";
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (preg_match('~^/plugins/' . preg_quote($plugin, '~') . '/include/([a-z0-9_-]+)\.php$~i', $uri, $m)) { require "$plug/include/{$m[1]}.php"; return true; }
if (strpos($uri, '/plugins/') === 0 || strpos($uri, '/webGui/') === 0) return false;
$docroot = $webroot;
$var = ['csrf_token' => 'TESTTOKEN'];
function run_page(string $f): void { global $docroot, $var; eval('?>' . explode("\n---\n", file_get_contents($f), 2)[1]); }
$legacy = getenv('WEBGUI') === '6.12';
$styles = $legacy ? ['default-fonts', 'default-cases', 'font-awesome', "default-$theme", "dynamix-$theme"]
                  : ['default-fonts', 'default-cases', 'font-awesome', 'default-color-palette', 'default-base', 'default-dynamix', "themes/$theme"];
$tabs = [];
foreach (explode(',', getenv('TABS')) as $t) { [$title, $file] = explode('=', $t, 2); $tabs[] = [$title, $file]; }
?><!DOCTYPE html>
<html lang="en" class="Theme--<?=htmlspecialchars($theme)?>">
<head><meta charset="utf-8"><title>harness</title>
<?php foreach ($styles as $c): ?><link rel="stylesheet" href="/webGui/styles/<?=$c?>.css">
<?php endforeach; ?>
<script src="/webGui/javascript/jquery.js"></script>
<script>var csrf_token = 'TESTTOKEN'; $.ajaxPrefilter(function (o) { if (o.type === 'POST') o.data = (o.data ? o.data + '&' : '') + 'csrf_token=' + csrf_token; });</script>
<style>body { padding: 16px; <?=$legacy ? '' : 'background: var(--background-color); color: var(--text-color);'?> }</style>
</head><body>
<div class="tabs">
<?php $sel = (int)($_GET['tab'] ?? 1); foreach ($tabs as $i => [$title, $f]): $n = $i + 1; ?>
<div class="tab" style="<?=$n === $sel ? '' : 'display:none'?>"><h2><?=htmlspecialchars($title)?></h2><div class="content"><?php run_page("$plug/$f.page"); ?></div></div>
<?php endforeach; ?>
</div></body></html>
