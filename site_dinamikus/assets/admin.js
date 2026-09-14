/* ============================================================
   Infinity Súgó — admin felület viselkedése
     · téma-váltás (az olvasói oldallal közös beállítás)
     · WYSIWYG szerkesztő + HTML forrás nézet
     · modálisok, szűrők, Word-import összehasonlító
     · gépi nyersfordítás hívása
   ============================================================ */
(function () {
  'use strict';

  var $ = function (s, c) { return (c || document).querySelector(s); };
  var $$ = function (s, c) { return Array.prototype.slice.call((c || document).querySelectorAll(s)); };
  var root = document.documentElement;

  function LSget(k, d) { try { var v = localStorage.getItem(k); return v === null ? d : v; } catch (e) { return d; } }
  function LSset(k, v) { try { localStorage.setItem(k, v); } catch (e) {} }

  /* ---------------------------------------------------------- téma */
  var ICON = {
    sun: '<svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="4.2"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/></svg>',
    moon: '<svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20.5 14.5A8.6 8.6 0 0 1 9.5 3.5a8.6 8.6 0 1 0 11 11Z"/></svg>'
  };
  function isDark() {
    var t = LSget('help.theme', 'auto');
    return t === 'dark' || (t === 'auto' && window.matchMedia('(prefers-color-scheme: dark)').matches);
  }
  function applyTheme(mode) {
    if (mode === 'auto') { root.removeAttribute('data-theme'); } else { root.setAttribute('data-theme', mode); }
    LSset('help.theme', mode);
    var b = $('#theme-toggle');
    if (b) { b.innerHTML = isDark() ? ICON.sun : ICON.moon; b.title = isDark() ? 'Világos téma' : 'Sötét téma'; }
  }

  /* ---------------------------------------------------------- üzenetbuborék */
  var toastEl, toastTm;
  function toast(msg, kind) {
    if (!toastEl) { toastEl = document.createElement('div'); toastEl.className = 'toast'; document.body.appendChild(toastEl); }
    toastEl.textContent = msg;
    toastEl.style.background = kind === 'err' ? '#bb0000' : '';
    toastEl.classList.add('on');
    clearTimeout(toastTm);
    toastTm = setTimeout(function () { toastEl.classList.remove('on'); }, 3000);
  }

  /* ---------------------------------------------------------- modálisok */
  function wireModals() {
    $$('[data-modal]').forEach(function (b) {
      b.addEventListener('click', function () {
        var m = document.getElementById('modal-' + b.getAttribute('data-modal'));
        if (m) { m.classList.add('on'); var f = m.querySelector('input,select,textarea'); if (f) { f.focus(); } }
      });
    });
    $$('.modal').forEach(function (m) {
      m.addEventListener('click', function (e) { if (e.target === m) { m.classList.remove('on'); } });
      $$('[data-close]', m).forEach(function (b) {
        b.addEventListener('click', function () { m.classList.remove('on'); });
      });
    });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') { $$('.modal.on').forEach(function (m) { m.classList.remove('on'); }); }
    });
  }

  /* ---------------------------------------------------------- kinyitható blokkok */
  function wireToggles() {
    $$('[data-toggle]').forEach(function (b) {
      b.addEventListener('click', function () {
        var t = $(b.getAttribute('data-toggle'));
        if (t) { t.hidden = !t.hidden; }
      });
    });
  }

  /* ---------------------------------------------------------- bal oldali lista szűrése */
  function wirePicker() {
    var f = $('#pick-filter'), list = $('#pick-list');
    if (!f || !list) { return; }
    function norm(s) { return (s || '').toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, ''); }
    f.addEventListener('input', function () {
      var q = norm(f.value.trim());
      var lastHeader = null, headerHasHit = false;
      $$('#pick-list > *').forEach(function (el) {
        if (el.classList.contains('picker__m')) {
          if (lastHeader) { lastHeader.style.display = headerHasHit ? '' : 'none'; }
          lastHeader = el; headerHasHit = false;
          return;
        }
        var hit = !q || norm(el.textContent).indexOf(q) >= 0;
        el.style.display = hit ? '' : 'none';
        if (hit) { headerHasHit = true; }
      });
      if (lastHeader) { lastHeader.style.display = headerHasHit ? '' : 'none'; }
    });
    f.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') { f.value = ''; f.dispatchEvent(new Event('input')); }
    });
  }

  /* ---------------------------------------------------------- szerkesztő */
  function wireEditor() {
    var ed = $('#ed'), area = $('#ed-area'), src = $('#ed-src');
    if (!ed || !area || !src) { return; }

    // a HTML forrás mindig a szerkesztő aktuális tartalma legyen beküldéskor
    function syncToSource() { src.value = area.innerHTML; }
    function syncToArea() { area.innerHTML = src.value; }
    syncToSource();

    var form = area.closest('form');
    if (form) {
      form.addEventListener('submit', function () {
        if (!ed.classList.contains('ed--source')) { syncToSource(); }
      });
    }

    var srcBtn = $('#ed-source');
    if (srcBtn) {
      srcBtn.addEventListener('click', function () {
        if (ed.classList.contains('ed--source')) {
          syncToArea();
          ed.classList.remove('ed--source');
          srcBtn.classList.remove('on');
        } else {
          syncToSource();
          src.value = prettyHtml(src.value);
          ed.classList.add('ed--source');
          srcBtn.classList.add('on');
        }
      });
    }

    $$('.ed-toolbar [data-cmd]').forEach(function (b) {
      b.addEventListener('click', function () {
        area.focus();
        document.execCommand(b.getAttribute('data-cmd'), false, undefined);
        syncToSource();
      });
    });
    $$('.ed-toolbar [data-block]').forEach(function (b) {
      b.addEventListener('click', function () {
        area.focus();
        document.execCommand('formatBlock', false, b.getAttribute('data-block'));
        syncToSource();
      });
    });

    var linkBtn = $('.ed-toolbar [data-act="link"]');
    if (linkBtn) {
      linkBtn.addEventListener('click', function () {
        var url = window.prompt('Hivatkozás címe (URL):', 'https://');
        if (!url) { return; }
        if (!/^(https?:|mailto:|tel:|\/)/i.test(url)) { toast('Csak http(s), mailto, tel vagy / kezdetű cím adható meg.', 'err'); return; }
        area.focus();
        document.execCommand('createLink', false, url);
        syncToSource();
      });
    }

    var imgBtn = $('.ed-toolbar [data-act="image"]');
    if (imgBtn) {
      imgBtn.addEventListener('click', function () {
        var name = window.prompt('Kép fájlneve a /media mappából (pl. img_7a90df0c0c19.png):', 'img_');
        if (!name) { return; }
        name = name.replace(/^\/?media\//, '').trim();
        if (!/^[A-Za-z0-9._-]+\.(png|jpe?g|gif|webp)$/i.test(name)) { toast('Érvénytelen fájlnév.', 'err'); return; }
        area.focus();
        document.execCommand('insertHTML', false, '<p><img src="/media/' + name + '" class="shot" alt=""></p>');
        syncToSource();
      });
    }

    [['callout-tip', 'tip', 'Tipp'], ['callout-warn', 'warn', 'Figyelem']].forEach(function (c) {
      var b = $('.ed-toolbar [data-act="' + c[0] + '"]');
      if (!b) { return; }
      b.addEventListener('click', function () {
        area.focus();
        var sel = String(window.getSelection());
        document.execCommand('insertHTML', false,
          '<div class="call ' + c[1] + '"><strong>' + c[2] + '</strong><p>' + (sel || '…') + '</p></div><p><br></p>');
        syncToSource();
      });
    });

    area.addEventListener('keydown', function (e) {
      if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 's') {
        e.preventDefault();
        var b = $('#btn-save') || (form && form.querySelector('[type=submit]'));
        if (b) { b.click(); }
      }
    });

    // csak sima szöveg (illetve tiszta HTML) kerüljön be beillesztéskor
    area.addEventListener('paste', function (e) {
      var html = e.clipboardData && e.clipboardData.getData('text/html');
      if (!html) { return; }
      e.preventDefault();
      var tmp = document.createElement('div');
      tmp.innerHTML = html;
      tmp.querySelectorAll('script,style,meta,link').forEach(function (n) { n.remove(); });
      tmp.querySelectorAll('*').forEach(function (n) {
        Array.prototype.slice.call(n.attributes).forEach(function (a) {
          if (['href', 'src', 'alt', 'title', 'colspan', 'rowspan'].indexOf(a.name) < 0) { n.removeAttribute(a.name); }
        });
      });
      document.execCommand('insertHTML', false, tmp.innerHTML);
      syncToSource();
    });

    area.addEventListener('input', function () {
      var st = $('#ed-state');
      if (st && !st.dataset.dirty) { st.dataset.dirty = '1'; st.textContent = 'Nem mentett változások — Ctrl+S vagy „Vázlat mentése”.'; }
    });

    // figyelmeztetés mentetlen tartalomra
    window.addEventListener('beforeunload', function (e) {
      var st = $('#ed-state');
      if (st && st.dataset.dirty) { e.preventDefault(); e.returnValue = ''; }
    });
    if (form) { form.addEventListener('submit', function () { var st = $('#ed-state'); if (st) { delete st.dataset.dirty; } }); }
  }

  /** Egyszerű, blokkonkénti sortörés a HTML forrás nézethez. */
  function prettyHtml(html) {
    return String(html)
      .replace(/></g, '>\n<')
      .replace(/\n(<\/(?:strong|em|u|s|a|code|span|b|i)>)/g, '$1')
      .replace(/(<(?:strong|em|u|s|a|code|span|b|i)[^>]*>)\n/g, '$1')
      .trim();
  }

  /* ---------------------------------------------------------- Word-import */
  function wireImport() {
    $$('[data-imp-toggle]').forEach(function (b) {
      b.addEventListener('click', function (e) {
        e.preventDefault();
        e.stopPropagation();
        b.closest('.imp-item').classList.toggle('open');
      });
    });
    var all = $('#imp-all'), none = $('#imp-none');
    if (all) {
      all.addEventListener('click', function () {
        $$('.imp-item').forEach(function (it) {
          var cb = it.querySelector('input[type=checkbox]');
          if (cb) { cb.checked = it.getAttribute('data-state') !== 'same'; }
        });
      });
    }
    if (none) {
      none.addEventListener('click', function () {
        $$('.imp-item input[type=checkbox]').forEach(function (cb) { cb.checked = false; });
      });
    }
    var form = $('#imp-form');
    if (form) {
      form.addEventListener('submit', function (e) {
        var n = $$('.imp-item input[type=checkbox]:checked').length;
        if (n === 0) { e.preventDefault(); toast('Nem jelöltél ki egyetlen fejezetet sem.', 'err'); return; }
        if (!window.confirm(n + ' fejezet átvétele. Folytatod?')) { e.preventDefault(); }
      });
    }
  }

  /* ---------------------------------------------------------- gépi nyersfordítás */
  function wireTranslate() {
    var btn = $('#tr-machine');
    if (!btn) { return; }
    btn.addEventListener('click', function () {
      var form = $('#tr-form'), area = $('#ed-area'), src = $('#ed-src'), title = $('#tr-title');
      if (!form) { return; }
      if (area && area.textContent.trim() !== '' &&
          !window.confirm('A jelenlegi fordítás felülíródik a gépi nyersfordítással. Folytatod?')) { return; }

      var body = new FormData();
      body.append('a', 'translate.machine');
      body.append('fmt', 'json');
      body.append('csrf', form.querySelector('[name=csrf]').value);
      body.append('src_id', form.querySelector('[name=src_id]').value);
      body.append('to', form.querySelector('[name=to]').value);

      var old = btn.textContent;
      btn.disabled = true;
      btn.textContent = 'Fordítás folyamatban…';

      fetch('admin.php', { method: 'POST', body: body })
        .then(function (r) { return r.json(); })
        .then(function (d) {
          if (!d.ok) { throw new Error(d.error || 'Ismeretlen hiba.'); }
          if (area) { area.innerHTML = d.html; }
          if (src) { src.value = d.html; }
          if (title && d.title) { title.value = d.title; }
          var how = $('#tr-how'); if (how) { how.value = 'machine'; }
          toast('Kész — ' + d.provider + '. Nézd át, javítsd, majd mentsd.');
        })
        .catch(function (e) { toast('Nem sikerült: ' + e.message, 'err'); })
        .finally(function () { btn.disabled = false; btn.textContent = old; });
    });
  }

  /* ---------------------------------------------------------- indulás */
  function init() {
    applyTheme(LSget('help.theme', 'auto'));
    var tb = $('#theme-toggle');
    if (tb) { tb.addEventListener('click', function () { applyTheme(isDark() ? 'light' : 'dark'); }); }

    wireModals();
    wireToggles();
    wirePicker();
    wireEditor();
    wireImport();
    wireTranslate();
  }

  if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', init); }
  else { init(); }
})();
