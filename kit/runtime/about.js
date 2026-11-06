/* About tab of the plugin kit: draws the version and the changes from the root's data-changes, as text only.
 * All tabs of an Unraid page share one DOM, so every About root is found and started once. */
(function () {
  'use strict';
  document.querySelectorAll('div.kit-about[data-changes]').forEach(function (root) {
    if (root.getAttribute('data-started')) return;
    root.setAttribute('data-started', '1');
    var changes = [];
    try { changes = JSON.parse(root.getAttribute('data-changes') || '[]'); } catch (e) { changes = []; }
    if (!Array.isArray(changes)) changes = [];
    function part(name) { return root.querySelector('[data-kit="' + name + '"]'); }
    function items(ul, list) {   // `text` in the notes becomes <code>; everything stays text
      (Array.isArray(list) ? list : []).forEach(function (t) {
        var li = document.createElement('li');
        String(t).split('`').forEach(function (p, i) {
          var n = document.createElement(i % 2 ? 'code' : 'span');
          n.textContent = p;
          li.appendChild(n);
        });
        ul.appendChild(li);
      });
    }
    var top = changes[0];
    part('ver').textContent = top ? 'Version ' + String(top.version) : 'Version not known';
    if (top) items(part('latest'), top.items);
    else {
      var li = document.createElement('li');
      li.textContent = 'The release notes are not available. Reinstall the plugin to restore them.';
      part('latest').appendChild(li);
    }
    if (changes.length > 1) {
      var box = part('oldbody');
      changes.slice(1).forEach(function (r) {
        var h = document.createElement('h4');
        h.className = 'kit-about-rel';
        h.textContent = String(r.version);
        var ul = document.createElement('ul');
        ul.className = 'kit-about-list';
        items(ul, r.items);
        box.appendChild(h);
        box.appendChild(ul);
      });
      part('old').hidden = false;
    }
  });
})();
