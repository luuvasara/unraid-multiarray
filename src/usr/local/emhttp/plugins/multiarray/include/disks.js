/* Disks tab of Settings > Multi Arrays: search, details, locate, enclosure cabling, found arrays and the import plan.
 * Answers come from include/disks-ajax.php and are drawn with textContent only. */
(function () {
  'use strict';
  var root = document.getElementById('ma-d');
  if (!root || root.dataset.ready) return;
  root.dataset.ready = '1';
  var url = root.dataset.endpoint;
  var csrf = root.dataset.csrf || (typeof csrf_token !== 'undefined' ? csrf_token : '');
  function q(sel) { return root.querySelector('[data-d="' + sel + '"]'); }
  function el(tag, text, cls) { var e = document.createElement(tag); if (text !== undefined && text !== null) e.textContent = text; if (cls) e.className = cls; return e; }
  function say(box, text, cls) { box.textContent = ''; if (text) box.appendChild(el('div', text, cls)); }
  function post(data) {
    var b = new URLSearchParams(); b.append('csrf_token', csrf);
    Object.keys(data).forEach(function (k) { [].concat(data[k]).forEach(function (v) { b.append(k, v); }); });
    return fetch(url, { method: 'POST', body: b, credentials: 'same-origin' }).then(function (r) {
      return r.json().catch(function () { throw new Error('HTTP ' + r.status); });
    });
  }
  function ask(title, text, yes) {   /* Unraid's own dialog where the page has it, else the browser's */
    if (typeof swal === 'function') swal({ title: title, text: text, type: 'warning', showCancelButton: true, confirmButtonText: 'Spin up and locate', cancelButtonText: 'Cancel' }, function (ok) { if (ok) yes(); });
    else if (window.confirm(title + '\n\n' + text)) yes();
  }

  /* details */
  root.querySelectorAll('.ma-d-more').forEach(function (b) {
    b.addEventListener('click', function () {
      var row = document.getElementById(b.getAttribute('aria-controls'));
      var open = b.getAttribute('aria-expanded') !== 'true';
      b.setAttribute('aria-expanded', open ? 'true' : 'false');
      if (row) row.hidden = !open;
    });
  });

  /* search */
  var findout = q('findout');
  function mark(keys) {
    root.querySelectorAll('.ma-d-hit').forEach(function (r) { r.classList.remove('ma-d-hit'); });
    root.querySelectorAll('[data-d-key]').forEach(function (r) { if (keys.indexOf(r.dataset.dKey) >= 0) r.classList.add('ma-d-hit'); });
  }
  q('form').addEventListener('submit', function (e) {
    e.preventDefault();
    var text = q('q').value.trim();
    if (!text) { say(findout, ''); mark([]); return; }
    say(findout, 'Looking...');
    post({ action: 'find', text: text }).then(function (j) {
      findout.textContent = '';
      if (!j.ok) { say(findout, j.error || 'No answer.', 'ma-bad'); return; }
      if (!j.hits.length) { say(findout, 'No disk of this server goes by a name in "' + text + '".'); mark([]); return; }
      j.hits.forEach(function (h) { findout.appendChild(el('div', h.name + '  →  ' + h.label)); });
      mark(j.hits.map(function (h) { return h.key; }));
      var first = root.querySelector('tr.ma-d-hit'); if (first) first.scrollIntoView({ block: 'center' });
    }).catch(function (err) { say(findout, 'No answer from the server: ' + err.message, 'ma-bad'); });
  });

  /* locate: one disk at a time, cancellable */
  var locout = q('locout'), timer = null;
  var buttons = root.querySelectorAll('.ma-d-loc');
  function locState(st) {
    buttons.forEach(function (b) {
      var dev = b.closest('tr').dataset.dDev;
      if (st.running && st.device === dev) { b.value = 'Stop (' + st.left + ' s)'; b.disabled = false; b.dataset.stop = '1'; }
      else { b.value = 'Locate'; b.disabled = !!st.running; delete b.dataset.stop; }
    });
    if (st.running) { say(locout, 'Reading ' + st.device + ': look for the disk whose activity light flashes. ' + st.left + ' s left.'); }
    else if (timer) { say(locout, 'Done.'); }
    if (st.running && !timer) timer = setInterval(poll, 1000);
    if (!st.running && timer) { clearInterval(timer); timer = null; }
  }
  function poll() { fetch(url + '?action=locate_status', { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(locState).catch(function () {}); }
  function locate(dev, spinup) {
    say(locout, 'Starting...');
    post({ action: 'locate', dev: dev, spinup: spinup ? 'yes' : '' }).then(function (j) {
      if (j.confirm) { say(locout, ''); ask('Spin up ' + dev + '?', j.error + ' Go on?', function () { locate(dev, true); }); return; }
      if (!j.ok) { say(locout, j.error, 'ma-bad'); return; }
      locState({ running: true, device: dev, left: j.seconds });
    }).catch(function (err) { say(locout, 'No answer from the server: ' + err.message, 'ma-bad'); });
  }
  buttons.forEach(function (b) {
    b.addEventListener('click', function () {
      var dev = b.closest('tr').dataset.dDev;
      if (b.dataset.stop) { b.disabled = true; post({ action: 'locate_stop' }).then(function () { locState({ running: false }); }); return; }
      locate(dev, false);
    });
  });
  poll();

  /* enclosure cabling */
  root.querySelectorAll('[data-d="cabling"]').forEach(function (f) {
    f.addEventListener('submit', function (e) {
      e.preventDefault();
      var out = f.querySelector('[data-d="cabout"]');
      var order = [].map.call(f.querySelectorAll('select'), function (s) { return s.value; });
      if (new Set(order).size !== order.length) { say(out, 'Each controller needs a different group of bays.', 'ma-bad'); return; }
      say(out, 'Saving...');
      post({ action: 'cabling', switch: f.dataset.switch, 'order[]': order }).then(function (j) {
        if (!j.ok) { say(out, j.error, 'ma-bad'); return; }
        say(out, 'Saved. Reloading...'); location.reload();
      }).catch(function (err) { say(out, 'No answer from the server: ' + err.message, 'ma-bad'); });
    });
  });

  /* found arrays */
  var detout = q('detout'), found = q('found');
  function roleOptions(can) {
    var o = [['', 'leave out']];
    if (can !== 'data') o.push(['parity', 'Parity'], ['parity2', 'Parity 2']);
    if (can !== 'parity') for (var i = 1; i <= 28; i++) o.push(['disk' + i, 'Disk ' + i]);
    return o;
  }
  function list(box, items, cls) { var ul = el('ul'); items.forEach(function (t) { ul.appendChild(el('li', t, cls)); }); box.appendChild(ul); }
  function evidence(d) {   /* the per-disk evidence as a native disclosure */
    var det = el('details'), sum = el('summary', 'Evidence');
    det.appendChild(sum); list(det, d.evidence || []); return det;
  }
  function drawFound(j) {
    found.textContent = '';
    var n = j.checked + ' free disk' + (j.checked === 1 ? '' : 's');
    if (!j.arrays.length) say(detout, j.checked ? 'No array found on the ' + n + '. Nothing was changed.' : 'There are no free disks to look at.');
    else say(detout, j.arrays.length + ' possible array' + (j.arrays.length > 1 ? 's' : '') + ' on the ' + n + '. Nothing was changed. All of this is evidence: you decide which disk goes where and confirm it.');
    j.arrays.forEach(function (a) {
      var box = el('div', null, 'ma-d-found-box'); found.appendChild(box);
      box.appendChild(el('h3', a.title));
      box.appendChild(el('p', 'Evidence that ties these disks together:'));
      list(box, a.evidence);
      var t = el('table', null, 'ma-d-found'), th = el('thead'), tr = el('tr');
      ['Disk', 'Size', 'Model', 'Serial', 'What is on it', 'Slot in the plan'].forEach(function (h) { var c = el('th', h); c.scope = 'col'; tr.appendChild(c); });
      th.appendChild(tr); t.appendChild(th);
      var tb = el('tbody'); t.appendChild(tb);
      a.disks.forEach(function (d, i) {
        var r = el('tr');
        [d.device, d.size_text, d.model, d.serial].forEach(function (v) { r.appendChild(el('td', v)); });
        var w = el('td', d.text + (d.ssd ? ' (SSD: not recommended as an array disk)' : '')); w.appendChild(evidence(d)); r.appendChild(w);
        var c = el('td'), s = el('select'); s.id = a.id + '-r' + i; s.dataset.dev = d.device;
        s.setAttribute('aria-label', 'Slot for ' + d.device);
        roleOptions(d.can).forEach(function (o) { var op = el('option', o[1]); op.value = o[0]; if (o[0] === d.role) op.selected = true; s.appendChild(op); });
        c.appendChild(s); r.appendChild(c); tb.appendChild(r);
      });
      box.appendChild(t);
      box.appendChild(el('p', 'What the slot order means:'));
      list(box, a.notes);
      var pc = el('input'); pc.type = 'button'; pc.value = 'Check parity against the data disks';
      var pout = el('div', null, 'ma-d-msg'); pout.setAttribute('role', 'status'); pout.setAttribute('aria-live', 'polite');
      box.appendChild(el('p', 'Optional evidence: reads the disks at up to 10 places that hold data and checks that the disk set as Parity equals the XOR of the disks set as data disks. Reads only. With dual parity only parity 1 is checked.'));
      box.appendChild(pc); box.appendChild(pout);
      pc.addEventListener('click', function () { parityCheck(box, pout, pc); });
      var go = el('input'); go.type = 'button'; go.value = 'Prepare import';
      var out = el('div', null, 'ma-d-msg'); out.setAttribute('role', 'status'); out.setAttribute('aria-live', 'polite');
      box.appendChild(el('p', 'Prepare import makes an import plan from the slots chosen above, for the Export and import tab. Nothing is assigned until you confirm it there.'));
      box.appendChild(go); box.appendChild(out);
      go.addEventListener('click', function () { prepare(box, out); });
    });
    if (j.other.length) {
      var ob = el('div', null, 'ma-d-found-box'); found.appendChild(ob);
      ob.appendChild(el('h3', 'Not part of a found array'));
      var ul = el('ul'); ob.appendChild(ul);
      j.other.forEach(function (d) { var li = el('li', d.device + ' (' + d.size_text + (d.model ? ', ' + d.model : '') + '): ' + d.text + '. Never offered for import.'); li.appendChild(evidence(d)); ul.appendChild(li); });
    }
  }
  function job(onDone, out, btn) {   /* poll the background check until it is done */
    var t = setInterval(function () {
      fetch(url + '?action=job', { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (j) {
        if (j.state === 'running') { if (j.total) say(out, 'Reading ' + (j.device || '') + ' (' + Math.min(j.done + 1, j.total) + ' of ' + j.total + ')...'); return; }
        clearInterval(t); if (btn) btn.disabled = false;
        if (j.state === 'done') onDone(j.result); else say(out, j.error || 'The check failed.', 'ma-bad');
      }).catch(function () {});
    }, 1500);
  }
  function parityCheck(box, out, btn) {
    var par = null, data = [];
    box.querySelectorAll('select[data-dev]').forEach(function (s) { if (s.value === 'parity') par = s.dataset.dev; else if (/^disk/.test(s.value)) data.push(s.dataset.dev); });
    if (!par || !data.length) { say(out, 'Set one disk as Parity and at least one as a data disk first.', 'ma-bad'); return; }
    btn.disabled = true; say(out, 'Starting...');
    post({ action: 'parity', parity: par, 'data[]': data }).then(function (j) {
      if (!j.ok) { btn.disabled = false; say(out, j.error, 'ma-bad'); return; }
      job(function (r) {
        out.textContent = '';
        if (!r.ok) { say(out, r.error, 'ma-bad'); return; }
        out.appendChild(el('div', r.text, r.compared && r.matched === r.compared ? 'ma-good' : (r.compared ? 'ma-bad' : '')));
        if (r.rows.length) list(out, r.rows.map(function (x) { return 'at ' + x.offset_gib + ' GiB (data on ' + (x.with_data.length ? x.with_data.join(', ') : 'no data disk') + '): ' + (x.match ? 'match' : 'no match'); }));
        out.appendChild(el('div', 'This is evidence, not a decision: you confirm the mapping.'));
      }, out, btn);
    }).catch(function (err) { btn.disabled = false; say(out, 'No answer from the server: ' + err.message, 'ma-bad'); });
  }
  function prepare(box, out) {
    var roles = {};
    box.querySelectorAll('select[data-dev]').forEach(function (s) { roles['roles[' + s.dataset.dev + ']'] = s.value; });
    say(out, 'Making the plan...');
    post(Object.assign({ action: 'prepare' }, roles)).then(function (j) {
      out.textContent = '';
      if (!j.ok) { out.appendChild(el('div', j.error, 'ma-bad')); list(out, j.reasons || [], 'ma-bad'); return; }
      var json = JSON.stringify(j.data, null, 2);
      out.appendChild(el('div', 'Plan ready: ' + j.data.slots.map(function (s) { return s.role + ' = ' + s.seen_as.device; }).join(', ') + '. Nothing has been changed.', 'ma-good'));
      list(out, j.warnings || []);
      var p = el('p'), cont = el('input'), dl = el('a', 'Download the plan');
      cont.type = 'button'; cont.value = 'Continue on the Export and import tab';
      dl.href = URL.createObjectURL(new Blob([json], { type: 'application/json' })); dl.download = 'multiarray-detected-plan.json';
      p.appendChild(cont); p.appendChild(document.createTextNode(' ')); p.appendChild(dl); out.appendChild(p);
      cont.addEventListener('click', function () {
        if (typeof window.maXportDetected === 'function') {
          window.maXportDetected(json);
          var tab = [].filter.call(document.querySelectorAll('.tabs-container button, button, label, a'), function (x) { return x.textContent.replace(/\s+/g, ' ').trim() === 'Export and import'; })[0];
          if (tab) tab.click();
          var box2 = document.getElementById('ma-x-detected'); if (box2) box2.scrollIntoView({ block: 'start' });
        } else say(out, 'Open the Export and import tab and choose the downloaded plan as the file.', 'ma-bad');
      });
    }).catch(function (err) { say(out, 'No answer from the server: ' + err.message, 'ma-bad'); });
  }
  q('detect').addEventListener('click', function () {
    var b = this; b.disabled = true; found.textContent = '';
    say(detout, 'Starting...');
    post({ action: 'detect' }).then(function (j) {
      if (!j.ok) { b.disabled = false; say(detout, j.error || 'Detection failed.', 'ma-bad'); return; }
      job(drawFound, detout, b);
    }).catch(function (err) { b.disabled = false; say(detout, 'No answer from the server: ' + err.message, 'ma-bad'); });
  });
})();
