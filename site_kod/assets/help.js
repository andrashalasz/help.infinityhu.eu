(function () {
  var IDX = null, box = document.getElementById('q'), res = document.getElementById('res');
  if (!box) { return; }
  function norm(s) {
    s = (s || '').toLowerCase();
    var m = { 'á':'a','é':'e','í':'i','ó':'o','ö':'o','ő':'o','ú':'u','ü':'u','ű':'u',
              'ä':'a','ß':'ss','â':'a','ê':'e','ô':'o','û':'u','ç':'c','ñ':'n' }, o = '';
    for (var i = 0; i < s.length; i++) { o += (m[s[i]] || s[i]); }
    return o;
  }
  function load(cb) {
    if (IDX) { cb(); return; }
    var x = new XMLHttpRequest();
    x.open('GET', BASE + '/api/index.' + LANG + '.json', true);
    x.onload = function () { IDX = JSON.parse(x.responseText).index; cb(); };
    x.send();
  }
  function run() {
    var v = box.value.trim();
    if (v.length < 2) { res.className = 'res'; return; }
    load(function () {
      /* Szokoz = ES, vesszo = VAGY, ekezet-fuggetlen. */
      var groups = norm(v).split(',').map(function (g) {
        return g.trim().split(/\s+/).filter(Boolean);
      }).filter(function (g) { return g.length; });
      var hits = IDX.filter(function (r) {
        return groups.some(function (g) {
          return g.every(function (w) { return r.txt.indexOf(w) >= 0; });
        });
      }).slice(0, 24);
      res.innerHTML = hits.length ? hits.map(function (r) {
        return '<a href="' + BASE + '/' + LANG + '/' + r.slug + '.html'
             + (r.anchor ? '#' + r.anchor : '') + '"><b>' + r.no + ' ' + r.t
             + '</b><span>' + r.m + '</span></a>';
      }).join('') : '<a><b>' + NORES + '</b></a>';
      res.className = 'res on';
    });
  }
  box.addEventListener('input', run);
  box.addEventListener('focus', function () { if (box.value.trim().length > 1) { run(); } });
  document.addEventListener('click', function (e) {
    if (!res.contains(e.target) && e.target !== box) { res.className = 'res'; }
  });
  document.addEventListener('keydown', function (e) {
    if ((e.ctrlKey || e.metaKey) && e.key === 'k') { e.preventDefault(); box.focus(); }
    if (e.key === 'Escape') { res.className = 'res'; box.blur(); }
  });
}());
