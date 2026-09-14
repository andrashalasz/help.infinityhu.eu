/* ============================================================
   Infinity Súgó — olvasói felület viselkedése
     · téma (világos / sötét / rendszer)
     · állítható betűméret
     · képnagyító (lightbox) zoommal és lapozással
     · azonnali keresés billentyűzettel
     · navigáció-szűrő, tartalomjegyzék-követés, olvasási csík
   Nincs külső függősége.
   ============================================================ */
(function () {
  'use strict';

  var root = document.documentElement;
  var LS = {
    get: function (k, d) { try { var v = localStorage.getItem(k); return v === null ? d : v; } catch (e) { return d; } },
    set: function (k, v) { try { localStorage.setItem(k, v); } catch (e) {} }
  };
  var T = (window.HELP_I18N || {});
  var $ = function (s, c) { return (c || document).querySelector(s); };
  var $$ = function (s, c) { return Array.prototype.slice.call((c || document).querySelectorAll(s)); };

  /* ---------------------------------------------------------- toast */
  var toastEl = null, toastTm;
  function toast(msg) {
    if (!toastEl) { toastEl = document.createElement('div'); toastEl.className = 'toast'; document.body.appendChild(toastEl); }
    toastEl.textContent = msg;
    toastEl.classList.add('on');
    clearTimeout(toastTm);
    toastTm = setTimeout(function () { toastEl.classList.remove('on'); }, 2200);
  }
  window.helpToast = toast;

  /* ---------------------------------------------------------- 1. téma */
  var THEME_KEY = 'help.theme';           // light | dark | auto
  function applyTheme(mode) {
    if (mode === 'auto') { root.removeAttribute('data-theme'); }
    else { root.setAttribute('data-theme', mode); }
    LS.set(THEME_KEY, mode);
    $$('[data-theme-set]').forEach(function (b) {
      b.setAttribute('aria-pressed', String(b.getAttribute('data-theme-set') === mode));
    });
    var tb = $('#theme-toggle');
    if (tb) {
      var dark = mode === 'dark' || (mode === 'auto' && window.matchMedia('(prefers-color-scheme: dark)').matches);
      tb.innerHTML = dark ? ICON.sun : ICON.moon;
      tb.title = dark ? (T.themeLight || 'Világos téma') : (T.themeDark || 'Sötét téma');
    }
  }
  function currentTheme() { return LS.get(THEME_KEY, 'auto'); }

  /* ---------------------------------------------------------- 2. betűméret */
  var FS_KEY = 'help.fs', FS_MIN = 0.85, FS_MAX = 1.6, FS_STEP = 0.05;
  function clampFs(v) { return Math.min(FS_MAX, Math.max(FS_MIN, Math.round(v * 100) / 100)); }
  function applyFs(v) {
    v = clampFs(v);
    root.style.setProperty('--fs', String(v));
    LS.set(FS_KEY, String(v));
    var out = $('#fs-val'); if (out) { out.textContent = Math.round(v * 100) + '%'; }
    var rng = $('#fs-range'); if (rng && Number(rng.value) !== v) { rng.value = String(v); }
    return v;
  }
  function currentFs() { return clampFs(parseFloat(LS.get(FS_KEY, '1')) || 1); }
  function bumpFs(d) { var v = applyFs(currentFs() + d); toast((T.fontSize || 'Betűméret') + ': ' + Math.round(v * 100) + '%'); }

  /* ---------------------------------------------------------- ikonok */
  var ICON = {
    sun: '<svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="4.2"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/></svg>',
    moon: '<svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20.5 14.5A8.6 8.6 0 0 1 9.5 3.5a8.6 8.6 0 1 0 11 11Z"/></svg>'
  };

  /* ---------------------------------------------------------- 3. beállítás-panelek */
  function wirePopover(btnSel, popSel) {
    var btn = $(btnSel), pop = $(popSel);
    if (!btn || !pop) { return; }
    btn.addEventListener('click', function (e) {
      e.stopPropagation();
      var open = pop.classList.contains('on');
      $$('.pop, .fs-pop').forEach(function (p) { p.classList.remove('on'); });
      if (!open) { pop.classList.add('on'); }
      btn.setAttribute('aria-expanded', String(!open));
    });
    pop.addEventListener('click', function (e) { e.stopPropagation(); });
  }
  document.addEventListener('click', function () {
    $$('.pop, .fs-pop').forEach(function (p) { p.classList.remove('on'); });
    $$('[aria-expanded]').forEach(function (b) { b.setAttribute('aria-expanded', 'false'); });
  });

  /* ---------------------------------------------------------- 4. képnagyító */
  var LB = {
    el: null, img: null, cap: null, zoomOut: null, list: [], i: 0,
    scale: 1, tx: 0, ty: 0, drag: null
  };

  function buildLightbox() {
    var d = document.createElement('div');
    d.className = 'lb';
    d.setAttribute('role', 'dialog');
    d.setAttribute('aria-modal', 'true');
    d.innerHTML =
      '<div class="lb__bar">' +
        '<span class="lb__cap" id="lb-cap"></span>' +
        '<button class="lb__b" id="lb-close" title="' + esc(T.close || 'Bezárás') + ' (Esc)" aria-label="' + esc(T.close || 'Bezárás') + '">' +
          '<svg width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M6 6l12 12M18 6L6 18"/></svg>' +
        '</button>' +
      '</div>' +
      '<div class="lb__stage" id="lb-stage"><img class="lb__img" id="lb-img" alt=""></div>' +
      '<div class="lb__tools">' +
        '<button class="lb__b" id="lb-prev" title="' + esc(T.prev || 'Előző') + '">&#8249;</button>' +
        '<button class="lb__b" id="lb-zout" title="' + esc(T.zoomOut || 'Kicsinyítés') + '">&minus;</button>' +
        '<span class="lb__z" id="lb-zoom">100%</span>' +
        '<button class="lb__b" id="lb-zin" title="' + esc(T.zoomIn || 'Nagyítás') + '">+</button>' +
        '<button class="lb__b" id="lb-fit" title="' + esc(T.fit || 'Illesztés') + '">1:1</button>' +
        '<button class="lb__b" id="lb-next" title="' + esc(T.next || 'Következő') + '">&#8250;</button>' +
      '</div>';
    document.body.appendChild(d);
    LB.el = d; LB.img = $('#lb-img', d); LB.cap = $('#lb-cap', d);

    $('#lb-close', d).addEventListener('click', closeLb);
    d.addEventListener('click', function (e) { if (e.target === d || e.target.id === 'lb-stage') { closeLb(); } });
    $('#lb-prev', d).addEventListener('click', function () { step(-1); });
    $('#lb-next', d).addEventListener('click', function () { step(1); });
    $('#lb-zin', d).addEventListener('click', function () { zoom(1.35); });
    $('#lb-zout', d).addEventListener('click', function () { zoom(1 / 1.35); });
    $('#lb-fit', d).addEventListener('click', function () { setTransform(1, 0, 0); });

    LB.img.addEventListener('click', function (e) {
      e.stopPropagation();
      if (LB.scale > 1.02) { setTransform(1, 0, 0); } else { zoom(2); }
    });
    LB.img.addEventListener('wheel', function (e) {
      e.preventDefault();
      zoom(e.deltaY < 0 ? 1.18 : 1 / 1.18);
    }, { passive: false });

    // húzás nagyított állapotban
    LB.img.addEventListener('pointerdown', function (e) {
      if (LB.scale <= 1.02) { return; }
      e.preventDefault();
      LB.drag = { x: e.clientX, y: e.clientY, tx: LB.tx, ty: LB.ty };
      LB.img.classList.add('dragging');
      LB.img.setPointerCapture(e.pointerId);
    });
    LB.img.addEventListener('pointermove', function (e) {
      if (!LB.drag) { return; }
      setTransform(LB.scale, LB.drag.tx + (e.clientX - LB.drag.x), LB.drag.ty + (e.clientY - LB.drag.y));
    });
    ['pointerup', 'pointercancel'].forEach(function (ev) {
      LB.img.addEventListener(ev, function () { LB.drag = null; LB.img.classList.remove('dragging'); });
    });
  }

  function setTransform(s, x, y) {
    LB.scale = Math.min(6, Math.max(1, s));
    if (LB.scale <= 1.02) { LB.scale = 1; x = 0; y = 0; }
    LB.tx = x; LB.ty = y;
    LB.img.style.transform = 'translate(' + x + 'px,' + y + 'px) scale(' + LB.scale + ')';
    LB.img.classList.toggle('zoomed', LB.scale > 1.02);
    var z = $('#lb-zoom'); if (z) { z.textContent = Math.round(LB.scale * 100) + '%'; }
    var zo = $('#lb-zout'); if (zo) { zo.disabled = LB.scale <= 1; }
  }
  function zoom(f) { setTransform(LB.scale * f, LB.tx * f, LB.ty * f); }

  function openLb(i) {
    if (!LB.el) { buildLightbox(); }
    LB.i = i;
    var src = LB.list[i];
    LB.img.src = src.src;
    LB.img.alt = src.alt || '';
    LB.cap.textContent = (src.alt || src.name || '') + (LB.list.length > 1 ? '   ·   ' + (i + 1) + ' / ' + LB.list.length : '');
    setTransform(1, 0, 0);
    LB.el.classList.add('on');
    document.body.style.overflow = 'hidden';
    $('#lb-prev').disabled = LB.list.length < 2;
    $('#lb-next').disabled = LB.list.length < 2;
    $('#lb-close').focus();
  }
  function closeLb() {
    if (!LB.el) { return; }
    LB.el.classList.remove('on');
    document.body.style.overflow = '';
  }
  function step(d) {
    if (LB.list.length < 2) { return; }
    openLb((LB.i + d + LB.list.length) % LB.list.length);
  }

  function collectImages() {
    var imgs = $$('.body img');
    LB.list = imgs.map(function (im) {
      return { src: im.currentSrc || im.src, alt: im.getAttribute('alt') || '', name: (im.getAttribute('src') || '').split('/').pop() };
    });
    imgs.forEach(function (im, idx) {
      im.setAttribute('tabindex', '0');
      im.setAttribute('role', 'button');
      if (!im.getAttribute('alt')) { im.setAttribute('alt', T.image || 'Képernyőkép'); }
      im.title = T.clickToZoom || 'Kattints a nagyításhoz';
      im.addEventListener('click', function () { openLb(idx); });
      im.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); openLb(idx); }
      });
    });
  }

  /* ---------------------------------------------------------- 5. keresés */
  function wireSearch() {
    var box = $('#gsearch'), res = $('#gsearch-res');
    if (!box || !res) { return; }
    var tm, sel = -1, items = [];

    function close() { res.classList.remove('on'); sel = -1; }
    function move(d) {
      if (!items.length) { return; }
      sel = (sel + d + items.length) % items.length;
      items.forEach(function (a, i) { a.classList.toggle('sel', i === sel); });
      if (items[sel]) { items[sel].scrollIntoView({ block: 'nearest' }); }
    }

    box.addEventListener('input', function () {
      clearTimeout(tm);
      var q = box.value.trim();
      if (q.length < 2) { close(); return; }
      tm = setTimeout(function () {
        fetch('/search?lang=' + encodeURIComponent(window.HELP_LANG || 'hu') + '&q=' + encodeURIComponent(q))
          .then(function (r) { return r.json(); })
          .then(function (data) {
            var list = data.items || [];
            res.innerHTML = list.length
              ? list.map(function (r) {
                  return '<a class="gsearch__item" href="/' + (window.HELP_LANG || 'hu') + '/' + encodeURIComponent(r.slug) + '">' +
                         '<b>' + esc(r.chapter_no + ' ' + r.title) + '</b>' +
                         '<i>' + esc(r.module || '') + '</i>' +
                         (r.snippet ? '<s>' + r.snippet + '</s>' : '') + '</a>';
                }).join('')
              : '<div class="gsearch__empty">' + esc(T.noResults || 'Nincs találat.') + '</div>';
            items = $$('.gsearch__item', res);
            sel = -1;
            res.classList.add('on');
          })
          .catch(function () { close(); });
      }, 140);
    });

    box.addEventListener('keydown', function (e) {
      if (e.key === 'ArrowDown') { e.preventDefault(); move(1); }
      else if (e.key === 'ArrowUp') { e.preventDefault(); move(-1); }
      else if (e.key === 'Enter' && sel >= 0 && items[sel]) { e.preventDefault(); items[sel].click(); }
      else if (e.key === 'Escape') { box.value = ''; close(); box.blur(); }
    });
    box.addEventListener('focus', function () { if (items.length && box.value.trim().length >= 2) { res.classList.add('on'); } });
    document.addEventListener('click', function (e) { if (!res.contains(e.target) && e.target !== box) { close(); } });
  }

  /* ---------------------------------------------------------- 6. bal navigáció */
  function wireNav() {
    var nav = $('#nav'), filter = $('#nav-filter'), burger = $('#burger'), scrim = $('#scrim');

    if (burger && nav && scrim) {
      burger.addEventListener('click', function () {
        var open = nav.classList.toggle('open');
        scrim.classList.toggle('on', open);
        burger.setAttribute('aria-expanded', String(open));
      });
      scrim.addEventListener('click', function () {
        nav.classList.remove('open'); scrim.classList.remove('on');
        burger.setAttribute('aria-expanded', 'false');
      });
    }

    // modulok nyitása/zárása — az állapot megmarad
    var OPEN_KEY = 'help.nav.closed';
    var closed = {};
    try { closed = JSON.parse(LS.get(OPEN_KEY, '{}')) || {}; } catch (e) { closed = {}; }
    $$('.nav__mod').forEach(function (mod) {
      var id = mod.getAttribute('data-mod');
      var hasActive = !!$('.nav__a.on', mod);
      if (closed[id] && !hasActive) { mod.classList.add('closed'); }
      var bt = $('.nav__mt', mod);
      if (!bt) { return; }
      bt.addEventListener('click', function () {
        var isClosed = mod.classList.toggle('closed');
        closed[id] = isClosed;
        bt.setAttribute('aria-expanded', String(!isClosed));
        LS.set(OPEN_KEY, JSON.stringify(closed));
      });
    });

    // az aktív elem legyen látható
    var active = $('.nav__a.on');
    if (active) {
      var body = $('.nav__body');
      if (body) {
        var top = active.offsetTop - body.clientHeight / 2;
        body.scrollTop = Math.max(0, top);
      }
    }

    if (filter) {
      var cnt = $('#nav-count');
      filter.addEventListener('input', function () {
        var q = norm(filter.value.trim());
        var shown = 0;
        $$('.nav__mod').forEach(function (mod) {
          var any = false;
          $$('.nav__a', mod).forEach(function (a) {
            var hit = !q || norm(a.textContent).indexOf(q) >= 0;
            a.style.display = hit ? '' : 'none';
            if (hit) { any = true; shown++; }
          });
          var modHit = !q || norm($('.nav__mt', mod).textContent).indexOf(q) >= 0;
          if (modHit && q) { $$('.nav__a', mod).forEach(function (a) { a.style.display = ''; shown++; }); any = true; }
          mod.style.display = (any || modHit) ? '' : 'none';
          if (q) { mod.classList.remove('closed'); }
        });
        if (cnt) { cnt.textContent = q ? (shown + ' ' + (T.hits || 'találat')) : ''; }
      });
      filter.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') { filter.value = ''; filter.dispatchEvent(new Event('input')); }
      });
    }
  }
  function norm(s) {
    return (s || '').toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '');
  }

  /* ---------------------------------------------------------- 7. tartalomjegyzék-követés + olvasási csík */
  function wireScroll() {
    var bar = $('#progress');
    var links = $$('.rail__a[data-anchor]');
    var targets = links.map(function (a) { return document.getElementById(a.getAttribute('data-anchor')); });
    var ticking = false;

    function update() {
      ticking = false;
      if (bar) {
        var h = document.documentElement.scrollHeight - window.innerHeight;
        bar.style.width = (h > 0 ? Math.min(100, (window.scrollY / h) * 100) : 0) + '%';
      }
      if (!links.length) { return; }
      var y = window.scrollY + 120, best = -1;
      targets.forEach(function (t, i) { if (t && t.offsetTop <= y) { best = i; } });
      links.forEach(function (a, i) { a.classList.toggle('on', i === best); });
    }
    window.addEventListener('scroll', function () {
      if (!ticking) { ticking = true; requestAnimationFrame(update); }
    }, { passive: true });
    window.addEventListener('resize', update);
    update();
  }

  /* ---------------------------------------------------------- 8. címsor-horgonyok */
  function wireAnchors() {
    $$('.body h2[id], .body h3[id], .body h4[id]').forEach(function (h) {
      var a = document.createElement('a');
      a.className = 'anchor-link';
      a.href = '#' + h.id;
      a.setAttribute('aria-label', T.copyLink || 'Hivatkozás másolása');
      a.innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M10 13a5 5 0 0 0 7.5.5l3-3a5 5 0 0 0-7-7l-1.7 1.7"/><path d="M14 11a5 5 0 0 0-7.5-.5l-3 3a5 5 0 0 0 7 7l1.7-1.7"/></svg>';
      a.addEventListener('click', function (e) {
        e.preventDefault();
        var url = location.origin + location.pathname + '#' + h.id;
        history.replaceState(null, '', '#' + h.id);
        h.scrollIntoView();
        copy(url);
      });
      h.appendChild(a);
    });
    // széles táblázatok görgethetővé tétele
    $$('.body table').forEach(function (t) {
      if (t.parentElement && t.parentElement.classList.contains('tablewrap')) { return; }
      var w = document.createElement('div');
      w.className = 'tablewrap';
      t.parentNode.insertBefore(w, t);
      w.appendChild(t);
    });
  }

  function copy(text) {
    var done = function () { toast(T.linkCopied || 'Hivatkozás a vágólapra másolva'); };
    if (navigator.clipboard && window.isSecureContext) {
      navigator.clipboard.writeText(text).then(done, function () { fallback(text, done); });
    } else { fallback(text, done); }
  }
  function fallback(text, done) {
    var ta = document.createElement('textarea');
    ta.value = text; ta.setAttribute('readonly', ''); ta.style.position = 'fixed'; ta.style.opacity = '0';
    document.body.appendChild(ta); ta.select();
    try { document.execCommand('copy'); done(); } catch (e) {}
    document.body.removeChild(ta);
  }

  /* ---------------------------------------------------------- 9. billentyűparancsok */
  function wireKeys() {
    document.addEventListener('keydown', function (e) {
      var inField = /^(INPUT|TEXTAREA|SELECT)$/.test(e.target.tagName) || e.target.isContentEditable;

      if (LB.el && LB.el.classList.contains('on')) {
        if (e.key === 'Escape') { closeLb(); }
        else if (e.key === 'ArrowRight') { step(1); }
        else if (e.key === 'ArrowLeft') { step(-1); }
        else if (e.key === '+' || e.key === '=') { zoom(1.35); }
        else if (e.key === '-') { zoom(1 / 1.35); }
        else if (e.key === '0') { setTransform(1, 0, 0); }
        return;
      }

      if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') {
        e.preventDefault(); var b = $('#gsearch'); if (b) { b.focus(); b.select(); }
        return;
      }
      if (inField) { return; }

      if (e.key === '/') { e.preventDefault(); var s = $('#gsearch'); if (s) { s.focus(); } }
      else if (e.key === '+' || e.key === '=') { e.preventDefault(); bumpFs(FS_STEP); }
      else if (e.key === '-' || e.key === '_') { e.preventDefault(); bumpFs(-FS_STEP); }
      else if (e.key === '0') { e.preventDefault(); applyFs(1); toast((T.fontSize || 'Betűméret') + ': 100%'); }
      else if (e.key.toLowerCase() === 'd' && !e.ctrlKey && !e.metaKey && !e.altKey) {
        var cur = currentTheme();
        var dark = cur === 'dark' || (cur === 'auto' && window.matchMedia('(prefers-color-scheme: dark)').matches);
        applyTheme(dark ? 'light' : 'dark');
      }
      else if (e.key === '?') { var p = $('#kbd-pop'); if (p) { $$('.pop').forEach(function (x) { x.classList.remove('on'); }); p.classList.add('on'); } }
      else if (e.key === 'Escape') { $$('.pop, .fs-pop').forEach(function (p) { p.classList.remove('on'); }); }
    });
  }

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  /* ---------------------------------------------------------- indulás */
  function init() {
    applyTheme(currentTheme());
    applyFs(currentFs());

    var tb = $('#theme-toggle');
    if (tb) {
      tb.addEventListener('click', function (e) {
        e.stopPropagation();
        var cur = currentTheme();
        var dark = cur === 'dark' || (cur === 'auto' && window.matchMedia('(prefers-color-scheme: dark)').matches);
        applyTheme(dark ? 'light' : 'dark');
      });
    }
    $$('[data-theme-set]').forEach(function (b) {
      b.addEventListener('click', function () { applyTheme(b.getAttribute('data-theme-set')); });
    });
    window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', function () {
      if (currentTheme() === 'auto') { applyTheme('auto'); }
    });

    wirePopover('#fs-btn', '#fs-pop');
    wirePopover('#kbd-btn', '#kbd-pop');
    var rng = $('#fs-range');
    if (rng) {
      rng.min = String(FS_MIN); rng.max = String(FS_MAX); rng.step = String(FS_STEP);
      rng.value = String(currentFs());
      rng.addEventListener('input', function () { applyFs(parseFloat(rng.value)); });
    }
    var inc = $('#fs-inc'), dec = $('#fs-dec'), rst = $('#fs-reset');
    if (inc) { inc.addEventListener('click', function () { bumpFs(FS_STEP); }); }
    if (dec) { dec.addEventListener('click', function () { bumpFs(-FS_STEP); }); }
    if (rst) { rst.addEventListener('click', function () { applyFs(1); }); }

    var cp = $('#act-copy');
    if (cp) { cp.addEventListener('click', function () { copy(location.href); }); }
    var pr = $('#act-print');
    if (pr) { pr.addEventListener('click', function () { window.print(); }); }

    collectImages();
    wireSearch();
    wireNav();
    wireScroll();
    wireAnchors();
    wireKeys();
  }

  if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', init); }
  else { init(); }
})();
