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
  <br><small>The instance for array <?=$arr?> is not running, so its devices and controls are not available. It starts automatically with the main array.</small>
<?endif;?>
</div>
<script>
(function(){
  var n=<?=$arr?>, up=<?=$up?'true':'false'?>;
  var st=document.getElementById('ma'+n+'-st');
  window['ma'+n+'ctl']=function(a){
    $.post('/plugins/multiarray/include/ctl.php',{action:a,n:n,csrf_token:'<?=$csrf?>'}); st.textContent=a+'ing...'; };
  function poll(){
    $.getJSON('/plugins/multiarray/include/ctl.php?action=status',function(d){
      var i=d.filter(function(x){return x.n==n})[0]; if(!i) return;
      var run=i.running;
      if(i.ready!=up){ location.reload(); return; }
      st.textContent=run?('running, array '+(i.mdState||'?')):(i.driver=='ok'?'stopped':'stopped (driver module for this kernel missing)');
      document.getElementById('ma'+n+'-start').style.display=run?'none':'';
      document.getElementById('ma'+n+'-stop').style.display=run?'':'none';
    });
  }
  poll(); setInterval(poll,5000);
})();
</script>
