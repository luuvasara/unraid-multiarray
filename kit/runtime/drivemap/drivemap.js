/* Drives tab: the "All names" buttons and the search box. Answers come from kit/drivemap.php and are drawn with
 * textContent only. Unraid's pages define csrf_token; the prologue checks it on every POST. */
(function () {
  'use strict';
  document.querySelectorAll('.dm[data-endpoint]').forEach(function (root) {
    if (root.dataset.dmReady) return;
    root.dataset.dmReady = '1';
    var endpoint = root.dataset.endpoint;
    var form = root.querySelector('[data-dm="form"]');
    var q = root.querySelector('[data-dm="q"]');
    var out = root.querySelector('[data-dm="result"]');

    root.querySelectorAll('.dm-more').forEach(function (b) {
      b.addEventListener('click', function () {
        var row = document.getElementById(b.getAttribute('aria-controls'));
        var open = b.getAttribute('aria-expanded') !== 'true';
        b.setAttribute('aria-expanded', open ? 'true' : 'false');
        if (row) row.hidden = !open;
      });
    });

    function line(text, cls) {
      var p = document.createElement('p');
      if (cls) p.className = cls;
      p.textContent = text;
      out.appendChild(p);
      return p;
    }
    function mark(keys) {
      root.querySelectorAll('tr.dm-hit').forEach(function (r) { r.classList.remove('dm-hit'); });
      root.querySelectorAll('tr[data-dm-key]').forEach(function (r) { if (keys.indexOf(r.dataset.dmKey) >= 0) r.classList.add('dm-hit'); });
    }

    form.addEventListener('submit', function (e) {
      e.preventDefault();
      var text = q.value.trim();
      out.textContent = '';
      if (!text) return;
      line('Looking...', 'dm-wait');
      var body = new URLSearchParams({ action: 'scan', text: text, csrf_token: (typeof csrf_token !== 'undefined' ? csrf_token : '') });
      fetch(endpoint, { method: 'POST', body: body, credentials: 'same-origin' })
        .then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
        .then(function (j) {
          out.textContent = '';
          if (!j.ok) { line(j.error || 'The drive map did not answer.', 'dm-error'); return; }
          if (!j.hits.length) { line('No drive of this server goes by a name in "' + text + '".', 'dm-none'); mark([]); return; }
          j.hits.forEach(function (h) { line(h.name + '  →  ' + h.label, 'dm-found'); });
          mark(j.hits.map(function (h) { return h.drive.key; }));
        })
        .catch(function (err) { out.textContent = ''; line('The drive map did not answer: ' + err.message, 'dm-error'); });
    });
  });
})();
