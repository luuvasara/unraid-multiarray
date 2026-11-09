<?PHP
/* Body of the MainN tabs: instance status bar. The array tables below it are the generated Main{N}Devices/Operation pages. */
require_once '/usr/local/emhttp/plugins/multiarray/include/lib.php';
$cfg  = parse_plugin_cfg('multiarray');
$csrf = $var['csrf_token'] ?? '';
$up   = ma_ready($arr);
?>
<div id="ma<?=$arr?>-bar" style="margin:0 0 1rem 0">
  <strong>Array <?=$arr?></strong> &mdash; management instance: <span id="ma<?=$arr?>-st"><?=$up?'running':'stopped'?></span>
  <input type="button" id="ma<?=$arr?>-start" value="Start instance" style="display:none" onclick="ma<?=$arr?>ctl('start')">
  <input type="button" id="ma<?=$arr?>-stop"  value="Stop instance"  style="display:none" onclick="ma<?=$arr?>ctl('stop')">
<?if (!$up):?>
  <br><small id="ma<?=$arr?>-note">The instance for array <?=$arr?> is not running, so its devices and controls are not available. It starts automatically with the main array.</small>
<?endif;?>
</div>
<script>
(function(){
  var n=<?=$arr?>, up=<?=$up?'true':'false'?>;
  var st=document.getElementById('ma'+n+'-st');
  var note0=(document.getElementById('ma'+n+'-note')||{}).textContent||'';
  window['ma'+n+'ctl']=function(a){
    $.post('/plugins/multiarray/include/ctl.php',{action:a,n:n,csrf_token:'<?=$csrf?>'}); st.textContent=a+'ing...'; };
  function poll(){
    $.getJSON('/plugins/multiarray/include/ctl.php?action=status',function(d){
      var i=d.filter(function(x){return x.n==n})[0]; if(!i) return;
      var run=i.running;
      if(i.ready!=up){ location.reload(); return; }
      var inc=i.incomplete||'', note=document.getElementById('ma'+n+'-note');
      st.textContent=run?('running, array '+(i.mdState||'?')):(inc?inc:(i.driver=='ok'?'stopped':'stopped (driver module for this kernel missing)'));
      /* why, and what to do: the stop ended the instance but could not release a disk (example: a shell whose folder is on the pool) */
      st.title=inc?('The last stop of array '+n+' could not finish: something still uses its disks, so its driver stays loaded and the array cannot start again. The notification names what to check (example: fuser -vm \'/mnt/disk1\'). Close it, then click Stop instance, which finishes the stop (same as: multiarray stop '+n+').'):'';
      if(note) note.textContent=!inc?note0:'Its devices and controls are not available until that stop finishes. Stop instance finishes it once nothing uses its disks any more (hover over the status for how to find what does).';
      document.getElementById('ma'+n+'-start').style.display=(run||inc)?'none':'';
      document.getElementById('ma'+n+'-stop').style.display=(run||inc)?'':'none';
    });
  }
  poll(); setInterval(poll,5000);
})();
</script>
