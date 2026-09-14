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

    // ---------- kep / video feltoltes kozvetlenul a szerkesztobol ----------
    function csrfOf() {
      var i = (form && form.querySelector('[name=csrf]')) || document.querySelector('[name=csrf]');
      return i ? i.value : '';
    }

    function insertAtCursor(html) {
      area.focus();
      document.execCommand('insertHTML', false, html);
      syncToSource();
      var st = $('#ed-state');
      if (st) { st.dataset.dirty = '1'; st.textContent = 'Nem mentett változások — Ctrl+S vagy „Vázlat mentése”.'; }
    }

    function uploadFile(file) {
      if (!file) { return Promise.resolve(); }
      var fd = new FormData();
      fd.append('a', 'media.inline');
      fd.append('fmt', 'json');
      fd.append('csrf', csrfOf());
      fd.append('file', file);

      var mark = 'up-' + Math.random().toString(36).slice(2);
      insertAtCursor('<p id="' + mark + '" class="uploading">Feltöltés: ' +
        file.name.replace(/[<>&]/g, '') + ' …</p>');

      return fetch('admin.php', { method: 'POST', body: fd })
        .then(function (r) { return r.json(); })
        .then(function (d) {
          var ph = document.getElementById(mark);
          if (!d.ok) { throw new Error(d.error || 'Ismeretlen hiba'); }
          if (ph) {
            ph.outerHTML = d.html;
          } else {
            insertAtCursor(d.html);
          }
          syncToSource();
          toast(d.existed ? 'Ez a fájl már fent volt, újra felhasználtam.' : 'Feltöltve: ' + d.name);
        })
        .catch(function (e) {
          var ph = document.getElementById(mark);
          if (ph) { ph.remove(); }
          syncToSource();
          toast('Nem sikerült: ' + e.message, 'err');
        });
    }

    function pickAndUpload(accept) {
      var inp = document.createElement('input');
      inp.type = 'file';
      inp.accept = accept;
      inp.multiple = true;
      inp.style.display = 'none';
      document.body.appendChild(inp);
      inp.addEventListener('change', function () {
        var files = Array.prototype.slice.call(inp.files || []);
        files.reduce(function (chain, f) {
          return chain.then(function () { return uploadFile(f); });
        }, Promise.resolve()).then(function () { inp.remove(); });
      });
      inp.click();
    }

    var upImg = $('.ed-toolbar [data-act="upload-image"]');
    if (upImg) { upImg.addEventListener('click', function () { pickAndUpload('image/*'); }); }
    var upVid = $('.ed-toolbar [data-act="upload-video"]');
    if (upVid) { upVid.addEventListener('click', function () { pickAndUpload('video/mp4,video/webm,video/quicktime'); }); }

    // fogd-és-vidd a szerkesztore
    ['dragenter', 'dragover'].forEach(function (ev) {
      area.addEventListener(ev, function (e) {
        if (!e.dataTransfer || Array.prototype.indexOf.call(e.dataTransfer.types || [], 'Files') < 0) { return; }
        e.preventDefault();
        area.classList.add('ed--drop');
      });
    });
    ['dragleave', 'dragend'].forEach(function (ev) {
      area.addEventListener(ev, function () { area.classList.remove('ed--drop'); });
    });
    area.addEventListener('drop', function (e) {
      var files = e.dataTransfer && e.dataTransfer.files;
      if (!files || !files.length) { return; }
      e.preventDefault();
      area.classList.remove('ed--drop');
      Array.prototype.slice.call(files).reduce(function (chain, f) {
        return chain.then(function () { return uploadFile(f); });
      }, Promise.resolve());
    });

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
      // kep a vagolapon (pl. kepernyokep) -> feltoltjuk
      var items = e.clipboardData && e.clipboardData.items;
      if (items) {
        for (var i = 0; i < items.length; i++) {
          if (items[i].kind === 'file' && /^image\//.test(items[i].type)) {
            e.preventDefault();
            uploadFile(items[i].getAsFile());
            return;
          }
        }
      }
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


  /* ---------------------------------------------------------- tömeges kijelölés */
  function wireBulk() {
    var picker = $('#picker'), bar = $('#bulkbar');
    if (!picker || !bar) { return; }

    var btn = $('#pick-select'), cancel = $('#bulk-cancel'), countEl = $('#bulk-count');

    function boxes() { return $$('.pick-one', picker); }
    function selected() { return boxes().filter(function (b) { return b.checked; }); }

    function refresh() {
      var n = selected().length;
      if (countEl) { countEl.textContent = String(n); }
      bar.hidden = !picker.classList.contains('picker--select') || n === 0;
      // a modul-fejléc pipája a csoportja állapotát tükrözze
      $$('.picker__m', picker).forEach(function (h) {
        var group = h.nextElementSibling;
        var all = group ? $$('.pick-one', group) : [];
        var on = all.filter(function (b) { return b.checked; });
        var chk = $('.pick-mod-all', h);
        if (!chk) { return; }
        chk.checked = all.length > 0 && on.length === all.length;
        chk.indeterminate = on.length > 0 && on.length < all.length;
      });
    }

    function setMode(on) {
      picker.classList.toggle('picker--select', on);
      if (btn) { btn.classList.toggle('on', on); }
      $$('input[type=checkbox]', picker).forEach(function (c) { c.tabIndex = on ? 0 : -1; });
      if (!on) { boxes().forEach(function (b) { b.checked = false; }); }
      refresh();
    }

    if (btn) {
      btn.addEventListener('click', function () {
        setMode(!picker.classList.contains('picker--select'));
      });
    }
    if (cancel) { cancel.addEventListener('click', function () { setMode(false); }); }

    picker.addEventListener('change', function (e) {
      if (e.target.classList.contains('pick-one')) { refresh(); return; }
      if (e.target.classList.contains('pick-mod-all')) {
        var group = e.target.closest('.picker__m').nextElementSibling;
        if (group) {
          $$('.pick-one', group).forEach(function (b) { b.checked = e.target.checked; });
        }
        refresh();
      }
    });

    // Shift-kattintás: tartomány kijelölése
    var lastIdx = -1;
    picker.addEventListener('click', function (e) {
      if (!e.target.classList.contains('pick-one')) { return; }
      var all = boxes(), idx = all.indexOf(e.target);
      if (e.shiftKey && lastIdx >= 0) {
        var a = Math.min(lastIdx, idx), b2 = Math.max(lastIdx, idx);
        for (var i = a; i <= b2; i++) { all[i].checked = e.target.checked; }
        refresh();
      }
      lastIdx = idx;
    });

    // a kijelölt azonosítók beküldése + a művelet gombjának rögzítése
    bar.addEventListener('submit', function (e) {
      var op = (document.activeElement && document.activeElement.getAttribute('data-op')) || '';
      if (!op) { e.preventDefault(); return; }
      var conf = document.activeElement.getAttribute('data-confirm');
      if (conf && !window.confirm(conf)) { e.preventDefault(); return; }
      if (op === 'move' && !$('#bulk-module').value) {
        e.preventDefault();
        toast('Válaszd ki, melyik modulba kerüljenek.', 'err');
        return;
      }
      $('#bulk-op').value = op;
      $$('input[name="ids[]"]', bar).forEach(function (n) { n.remove(); });
      selected().forEach(function (b) {
        var i = document.createElement('input');
        i.type = 'hidden'; i.name = 'ids[]'; i.value = b.value;
        bar.appendChild(i);
      });
    });

    refresh();
  }

  /* ---------------------------------------------------------- sorrend húzással */
  function post(action, fields) {
    var fd = new FormData();
    fd.append('a', action);
    fd.append('fmt', 'json');
    var c = document.querySelector('[name=csrf]');
    fd.append('csrf', c ? c.value : '');
    Object.keys(fields).forEach(function (k) {
      var v = fields[k];
      if (Array.isArray(v)) { v.forEach(function (one) { fd.append(k + '[]', one); }); }
      else { fd.append(k, v); }
    });
    return fetch('admin.php', { method: 'POST', body: fd }).then(function (r) { return r.json(); });
  }

  /** Közös húzás-kezelő: a `container` közvetlen gyerekei mozgathatók. */
  function makeSortable(container, itemSel, gripSel, onDrop) {
    if (!container) { return; }
    var dragged = null;

    $$(itemSel, container).forEach(function (item) { prepare(item); });

    function prepare(item) {
      var grip = gripSel ? item.querySelector(gripSel) : item;
      if (!grip) { return; }
      grip.setAttribute('draggable', 'true');
      grip.addEventListener('dragstart', function (e) {
        dragged = item;
        item.classList.add('dragging');
        e.dataTransfer.effectAllowed = 'move';
        try { e.dataTransfer.setData('text/plain', item.dataset.id || ''); } catch (x) {}
      });
      grip.addEventListener('dragend', function () {
        if (dragged) { dragged.classList.remove('dragging'); }
        $$('.drop-before,.drop-after', container.ownerDocument).forEach(function (n) {
          n.classList.remove('drop-before', 'drop-after');
        });
        dragged = null;
      });
    }

    function nearest(list, y) {
      var best = null, bestDist = Infinity, after = false;
      list.forEach(function (el) {
        if (el === dragged) { return; }
        var r = el.getBoundingClientRect();
        var mid = r.top + r.height / 2;
        var d = Math.abs(y - mid);
        if (d < bestDist) { bestDist = d; best = el; after = y > mid; }
      });
      return { el: best, after: after };
    }

    container.addEventListener('dragover', function (e) {
      if (!dragged) { return; }
      e.preventDefault();
      e.dataTransfer.dropEffect = 'move';
      var list = $$(itemSel, container);
      var t = nearest(list, e.clientY);
      $$('.drop-before,.drop-after', container).forEach(function (n) {
        n.classList.remove('drop-before', 'drop-after');
      });
      if (t.el) { t.el.classList.add(t.after ? 'drop-after' : 'drop-before'); }
    });

    container.addEventListener('drop', function (e) {
      if (!dragged) { return; }
      e.preventDefault();
      var list = $$(itemSel, container);
      var t = nearest(list, e.clientY);
      if (t.el && t.el !== dragged) {
        if (t.after) { t.el.after(dragged); } else { t.el.before(dragged); }
      }
      $$('.drop-before,.drop-after', container).forEach(function (n) {
        n.classList.remove('drop-before', 'drop-after');
      });
      onDrop(dragged, container);
    });
  }

  function wireSortable() {
    // 1. fejezetek a bal oldali listában (modulon belül és modulok között is)
    var picker = $('#picker');
    if (picker) {
      var sortBtn = $('#pick-sort'), hint = $('#pick-hint');
      if (sortBtn) {
        sortBtn.addEventListener('click', function () {
          var on = picker.classList.toggle('picker--sort');
          sortBtn.classList.toggle('on', on);
          if (hint) {
            hint.hidden = !on;
            hint.textContent = 'Húzd a ⠿ fogantyút a sorrend átrendezéséhez. Másik modul alá is húzhatod. A mentés automatikus.';
          }
        });
      }
      $$('.picker__group', picker).forEach(function (group) {
        makeSortable(group, '.picker__row', '.picker__grip', function (item, cont) {
          var ids = $$('.picker__row', cont).map(function (r) { return r.dataset.id; });
          post('articles.reorder', { order: ids, module_id: cont.dataset.module })
            .then(function (d) {
              if (d.ok) { toast('Sorrend mentve (' + d.count + ' fejezet).'); }
              else { toast('Nem sikerült: ' + (d.error || ''), 'err'); }
            })
            .catch(function () { toast('A sorrend mentése nem sikerült.', 'err'); });
        });
      });
    }

    // 2. modulok táblázata
    var modBody = $('#mod-sort');
    if (modBody) {
      makeSortable(modBody, 'tr', '.picker__grip', function (item, cont) {
        var ids = $$('tr', cont).map(function (r) { return r.dataset.id; });
        post('modules.reorder', { order: ids })
          .then(function (d) {
            if (d.ok) { toast('Modulsorrend mentve.'); }
            else { toast('Nem sikerült: ' + (d.error || ''), 'err'); }
          })
          .catch(function () { toast('A sorrend mentése nem sikerült.', 'err'); });
      });
    }
  }

  /* ---------------------------------------------------------- parancspaletta (Ctrl+K) */
  function wirePalette() {
    var pal = document.createElement('div');
    pal.className = 'palette';
    pal.innerHTML =
      '<div class="palette__box" role="dialog" aria-modal="true" aria-label="Ugrás / keresés">' +
        '<input class="palette__in" id="pal-in" placeholder="Ugrás fejezetre, fülre…  (↑ ↓ a lépkedéshez, Enter a megnyitáshoz)" autocomplete="off">' +
        '<div class="palette__list" id="pal-list"></div>' +
        '<div class="palette__foot"><kbd>↑</kbd><kbd>↓</kbd> lépkedés · <kbd>Enter</kbd> megnyitás · <kbd>Esc</kbd> bezár</div>' +
      '</div>';
    document.body.appendChild(pal);

    var input = $('#pal-in', pal), list = $('#pal-list', pal);
    var items = [], sel = 0, tm = null, lastQ = null;

    function open() {
      pal.classList.add('on');
      input.value = '';
      input.focus();
      lastQ = null;
      load('');
    }
    function close() { pal.classList.remove('on'); }

    function render() {
      if (!items.length) {
        list.innerHTML = '<div class="palette__empty">Nincs találat.</div>';
        return;
      }
      var group = null, html = '';
      items.forEach(function (it, i) {
        if (it.group !== group) {
          group = it.group;
          html += '<div class="palette__g">' + esc(group) + '</div>';
        }
        html += '<a class="palette__i' + (i === sel ? ' sel' : '') + '" href="' + esc(it.url) +
                '" data-i="' + i + '"><b>' + esc(it.label) + '</b><span>' + esc(it.sub || '') + '</span></a>';
      });
      list.innerHTML = html;
      var cur = list.querySelector('.palette__i.sel');
      if (cur) { cur.scrollIntoView({ block: 'nearest' }); }
    }

    function load(q) {
      if (q === lastQ) { return; }
      lastQ = q;
      fetch('admin.php?a=palette&q=' + encodeURIComponent(q))
        .then(function (r) { return r.json(); })
        .then(function (d) { items = d.items || []; sel = 0; render(); })
        .catch(function () { items = []; render(); });
    }

    input.addEventListener('input', function () {
      clearTimeout(tm);
      var q = input.value.trim();
      tm = setTimeout(function () { load(q); }, 120);
    });

    input.addEventListener('keydown', function (e) {
      if (e.key === 'ArrowDown') { e.preventDefault(); sel = Math.min(items.length - 1, sel + 1); render(); }
      else if (e.key === 'ArrowUp') { e.preventDefault(); sel = Math.max(0, sel - 1); render(); }
      else if (e.key === 'Enter') {
        e.preventDefault();
        if (items[sel]) { window.location.href = items[sel].url; }
      } else if (e.key === 'Escape') { close(); }
    });

    list.addEventListener('mousemove', function (e) {
      var a = e.target.closest('.palette__i');
      if (a) { sel = Number(a.dataset.i); render(); }
    });
    pal.addEventListener('click', function (e) { if (e.target === pal) { close(); } });

    document.addEventListener('keydown', function (e) {
      if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') {
        e.preventDefault();
        pal.classList.contains('on') ? close() : open();
      } else if (e.key === 'Escape' && pal.classList.contains('on')) {
        close();
      }
    });

    var opener = $('#palette-open');
    if (opener) { opener.addEventListener('click', open); }
    var keys = $('#keys-open');
    if (keys) {
      keys.addEventListener('click', function () {
        var m = $('#modal-keys');
        if (m) { m.classList.add('on'); }
      });
    }
  }

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  /* ---------------------------------------------------------- billentyűzetes navigáció */
  function wireKeys() {
    document.addEventListener('keydown', function (e) {
      var inField = /^(INPUT|TEXTAREA|SELECT)$/.test(e.target.tagName) || e.target.isContentEditable;
      var filter = $('#pick-filter');

      // ↑ ↓ a fejezetlistán (a szűrőmezőből is)
      if ((e.key === 'ArrowDown' || e.key === 'ArrowUp') && (e.target === filter || !inField)) {
        var links = $$('#pick-list .picker__a').filter(function (a) {
          return a.offsetParent !== null;
        });
        if (!links.length) { return; }
        e.preventDefault();
        var cur = links.indexOf(document.activeElement);
        if (cur < 0) { cur = links.findIndex(function (a) { return a.classList.contains('on'); }); }
        var next = e.key === 'ArrowDown' ? cur + 1 : cur - 1;
        next = Math.max(0, Math.min(links.length - 1, next));
        links[next].focus();
        links[next].scrollIntoView({ block: 'nearest' });
        return;
      }

      if (inField) { return; }

      // egybetűs gyorsugrások
      var go = { a: 'articles', m: 'modules', i: 'import', t: 'translate',
                 e: 'export', k: 'media', u: 'users', b: 'settings', d: '' };
      if (!e.ctrlKey && !e.metaKey && !e.altKey && Object.prototype.hasOwnProperty.call(go, e.key)) {
        var p = go[e.key];
        window.location.href = 'admin.php' + (p ? '?p=' + p : '');
        return;
      }
      if (e.key === '/') {
        e.preventDefault();
        if (filter) { filter.focus(); filter.select(); }
      }
      if (e.key === '?') { var m = $('#modal-keys'); if (m) { m.classList.add('on'); } }
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
    wireBulk();
    wireSortable();
    wirePalette();
    wireKeys();
  }

  if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', init); }
  else { init(); }
})();
