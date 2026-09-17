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

  /* --- a bal lista moduljainak ki-/összecsukása (állapot megjegyezve) --- */
  function wirePickerFold() {
    var list = document.querySelector('#pick-list');
    if (!list) { return; }

    var KEY = 'help.admin.folded';
    var folded = {};
    try { folded = JSON.parse(LSget(KEY, '{}')) || {}; } catch (e) { folded = {}; }

    function apply(id, on) {
      var head = list.querySelector('.picker__m[data-module="' + id + '"]');
      var group = list.querySelector('.picker__group[data-module="' + id + '"]');
      if (head) { head.classList.toggle('folded', on); }
      if (group) { group.hidden = on; }
    }

    Array.prototype.forEach.call(list.querySelectorAll('.picker__m'), function (h) {
      var id = h.getAttribute('data-module');
      // a nyitott fejezet modulja mindig latszik, barmit mond a mentett allapot
      var hasActive = !!list.querySelector('.picker__group[data-module="' + id + '"] .picker__a.on');
      if (folded[id] && !hasActive) { apply(id, true); }
    });

    list.addEventListener('click', function (e) {
      var b = e.target.closest('[data-fold]');
      if (!b) { return; }
      var id = b.getAttribute('data-fold');
      var head = b.closest('.picker__m');
      var on = !head.classList.contains('folded');
      apply(id, on);
      if (on) { folded[id] = 1; } else { delete folded[id]; }
      LSset(KEY, JSON.stringify(folded));
    });

    // "mindent összecsuk / mindent kinyit" a lista feletti gombbal
    var allBtn = document.querySelector('#pick-foldall');
    if (allBtn) {
      allBtn.addEventListener('click', function () {
        var heads = Array.prototype.slice.call(list.querySelectorAll('.picker__m'));
        var anyOpen = heads.some(function (h) { return !h.classList.contains('folded'); });
        heads.forEach(function (h) {
          var id = h.getAttribute('data-module');
          apply(id, anyOpen);
          if (anyOpen) { folded[id] = 1; } else { delete folded[id]; }
        });
        LSset(KEY, JSON.stringify(folded));
        allBtn.classList.toggle('on', anyOpen);
      });
    }
  }

  /* ---------------------------------------------------------- megerősítés
     A böngésző window.confirm() ablakát több környezet letiltja, és olyankor
     a művelet némán elmarad. Ezért minden megerősítés a saját ablakunkkal
     megy: <form data-confirm="..."> vagy askConfirm(szöveg, callback). */
  function askConfirm(text, onYes, title) {
    var modal = document.querySelector('#modal-confirm');
    if (!modal) { if (window.confirm(text)) { onYes(); } return; }

    modal.querySelector('#confirm-text').textContent = text;
    modal.querySelector('#confirm-title').textContent = title || 'Megerősítés';
    var ok = modal.querySelector('#confirm-ok');

    // friss gomb, hogy ne maradjon rajta korabbi esemenykezelo
    var fresh = ok.cloneNode(true);
    ok.parentNode.replaceChild(fresh, ok);
    fresh.addEventListener('click', function () {
      modal.classList.remove('on');
      onYes();
    });
    modal.classList.add('on');
  }

  function wireConfirmForms() {
    document.addEventListener('submit', function (e) {
      var f = e.target;
      if (!f || !f.getAttribute) { return; }
      var msg = f.getAttribute('data-confirm');
      if (!msg || f.dataset.confirmed === '1') { return; }
      e.preventDefault();
      askConfirm(msg, function () {
        f.dataset.confirmed = '1';
        if (f.requestSubmit) { f.requestSubmit(); } else { f.submit(); }
      });
    }, true);
  }

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

  /* --- Új fejezet: a Fejezetszám mező a kiválasztott modul következő számát ajánlja --- */
  function wireNewArticleChapter() {
    var sel = $('#new-module'), inp = $('#new-chapter');
    if (!sel || !inp) { return; }

    function syncPlaceholder() {
      var opt = sel.options[sel.selectedIndex];
      inp.placeholder = (opt && opt.getAttribute('data-next')) || '';
    }
    sel.addEventListener('change', syncPlaceholder);

    // A modul fejlécén levő "+" gomb: megnyitja az Új fejezet ablakot,
    // ERRE a modulra állítva, hogy ne kelljen a legördülőben keresgélni.
    // A "+" gombok NEM nyitnak ablakot: rogton letrehozzak a fejezetet a
    // kovetkezo szabad szammal, es a szerkesztoben nyitjak meg, ahol a cim
    // az elso mezo. Igy egy kattintassal lehet irni kezdeni.
    $$('[data-new-in]').forEach(function (b) {
      b.addEventListener('click', function (e) {
        e.stopPropagation();
        e.preventDefault();

        var moduleId = b.getAttribute('data-new-in');
        var no = b.getAttribute('data-new-no');
        if (!no) {
          var opt = sel.querySelector('option[value="' + moduleId + '"]');
          no = (opt && opt.getAttribute('data-next')) || '';
        }

        b.disabled = true;
        var f = document.createElement('form');
        f.method = 'post';
        f.action = 'admin.php';
        var csrf = document.querySelector('[name=csrf]');
        var langEl = document.querySelector('#bulkbar [name=lang]');
        [['csrf', csrf ? csrf.value : ''], ['a', 'article.create'],
         ['lang', langEl ? langEl.value : 'hu'], ['module_id', moduleId],
         ['chapter_no', no], ['title', ''], ['sort_order', '']]
          .forEach(function (kv) {
            var i = document.createElement('input');
            i.type = 'hidden'; i.name = kv[0]; i.value = kv[1];
            f.appendChild(i);
          });
        document.body.appendChild(f);
        f.submit();
      });
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
    /* ---------- címsorszintek + automatikus számozás ----------
       A szám a címsor elején álló <span class="hno"> elemben él (ugyanaz a
       jelölés, amit a Word-import is használ), így a mentéskor futó
       help_anchorize() változatlanul ki tudja belőle olvasni a fejezetszámot. */

    var HEADS = 'h2,h3,h4,h5,h6';
    var levelSel = $('#ed-level');
    var autoNum  = $('#ed-autonum');

    function chapterPrefix() { return (ed.getAttribute('data-chapter') || '').trim(); }

    /** A címsor elejére került kézi számot betesszük a hno spanba. */
    function adoptInlineNumbers() {
      area.querySelectorAll(HEADS).forEach(function (h) {
        if (h.querySelector('.hno')) { return; }
        var first = h.firstChild;
        if (!first || first.nodeType !== 3) { return; }
        var m = /^\s*(\d+(?:\.\d+)*)\.?\s+/.exec(first.nodeValue || '');
        if (!m) { return; }
        first.nodeValue = first.nodeValue.slice(m[0].length);
        var sp = document.createElement('span');
        sp.className = 'hno';
        sp.textContent = m[1];
        h.insertBefore(sp, h.firstChild);
      });
    }

    /**
     * Újraszámozza a címsorokat a cikk fejezetszáma alá (pl. 5.4 -> 5.4.1,
     * 5.4.1.1 ...). A kurzort nem mozdítja: csak a meglévő span szövegét
     * írja át, illetve hiányzó spant szúr be a címsor elejére.
     */
    function renumber() {
      var prefix = chapterPrefix();
      var counters = [0, 0, 0, 0, 0];
      var changed = false;

      area.querySelectorAll(HEADS).forEach(function (h) {
        var depth = parseInt(h.tagName.substring(1), 10) - 1;   // h2 -> 1 ... h6 -> 5
        if (depth < 1 || depth > 5) { return; }

        counters[depth - 1]++;
        for (var i = depth; i < 5; i++) { counters[i] = 0; }

        var parts = counters.slice(0, depth);
        var num = (prefix ? prefix + '.' : '') + parts.join('.');

        var sp = h.querySelector('.hno');
        if (!sp) {
          sp = document.createElement('span');
          sp.className = 'hno';
          sp.textContent = num;
          h.insertBefore(sp, h.firstChild);
          changed = true;
        } else if (sp.textContent !== num) {
          sp.textContent = num;
          changed = true;
        }
        sp.setAttribute('contenteditable', 'false');
      });

      if (changed) { syncToSource(); }
      return changed;
    }

    /** A kurzor alatti blokk szintje a legördülőben. */
    function refreshLevel() {
      if (!levelSel) { return; }
      var sel = window.getSelection();
      if (!sel || !sel.anchorNode) { return; }
      var n = sel.anchorNode.nodeType === 1 ? sel.anchorNode : sel.anchorNode.parentNode;
      var tag = 'p';
      while (n && n !== area) {
        var t = (n.tagName || '').toLowerCase();
        if (/^h[1-6]$/.test(t) || t === 'p' || t === 'li') { tag = t === 'li' ? 'p' : t; break; }
        n = n.parentNode;
      }
      levelSel.value = /^h[2-6]$/.test(tag) ? tag : 'p';
    }

    if (levelSel) {
      levelSel.addEventListener('change', function () {
        area.focus();
        document.execCommand('formatBlock', false, levelSel.value);
        // bekezdéssé alakításkor a régi sorszám már nem érvényes
        if (levelSel.value === 'p') {
          var sel = window.getSelection();
          var n = sel && sel.anchorNode ? (sel.anchorNode.nodeType === 1 ? sel.anchorNode : sel.anchorNode.parentNode) : null;
          while (n && n !== area) {
            if ((n.tagName || '').toLowerCase() === 'p') {
              var old = n.querySelector('.hno');
              if (old) { old.remove(); }
              break;
            }
            n = n.parentNode;
          }
        }
        if (!autoNum || autoNum.checked) { renumber(); }
        syncToSource();
        markDirty();
      });
    }

    /* ---------- a fejezet címe: azonnal látszik, magától mentődik ---------- */
    var titleIn = $('#ed-title-in');
    if (titleIn) {
      var echo = $('#ed-title-echo');
      var rowLink = document.querySelector('.picker__a.on span');
      var titleTimer = null;

      titleIn.addEventListener('input', function () {
        var v = titleIn.value.trim();
        // azonnali visszajelzés a fejlécben és a bal oldali listában
        if (echo) { echo.textContent = v || 'Névtelen fejezet'; }
        if (rowLink) { rowLink.textContent = v || 'Névtelen fejezet'; }
        document.title = (v || 'Névtelen fejezet') + ' — Infinity Súgó admin';
        markDirty();

        // és rövid szünet után el is mentjük, hogy ne vesszen el
        clearTimeout(titleTimer);
        titleTimer = setTimeout(saveDraftQuietly, 900);
      });
      titleIn.addEventListener('blur', function () {
        clearTimeout(titleTimer);
        saveDraftQuietly();
      });
      // Enter ne kuldje be az urlapot, csak mentsen
      titleIn.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') { e.preventDefault(); titleIn.blur(); }
      });
    }

    /** Vazlat mentese hattérben, oldalujratoltes nelkul. */
    function saveDraftQuietly() {
      if (!form) { return; }
      syncToSource();
      var fd = new FormData(form);
      fd.append('fmt', 'json');
      fetch('admin.php', { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (j) {
          var st = $('#ed-state');
          if (!st) { return; }
          if (j && j.ok) {
            delete st.dataset.dirty;
            st.textContent = 'Mentve ' + (j.saved_at || '') + ' — a vázlat csak a szerkesztőben látszik.';
          } else if (j && j.error) {
            toast(j.error, 'err');
          }
        })
        .catch(function () { /* halozati hiba eseten marad a kezi mentes */ });
    }

    var renumBtn = $('#ed-renumber');
    if (renumBtn) {
      renumBtn.addEventListener('click', function () {
        adoptInlineNumbers();
        renumber();
        markDirty();
        toast('A címsorok újraszámozva.', 'ok');
      });
    }

    // induláskor a meglévő tartalmat is rendbe tesszük (szám a spanba),
    // de a számokat nem írjuk át, amíg a szerkesztő hozzá nem nyúl
    adoptInlineNumbers();
    area.querySelectorAll('.hno').forEach(function (n) { n.setAttribute('contenteditable', 'false'); });

    var numTimer = null;
    area.addEventListener('input', function () {
      if (!autoNum || !autoNum.checked) { return; }
      clearTimeout(numTimer);
      numTimer = setTimeout(renumber, 350);
    });
    area.addEventListener('keyup', refreshLevel);
    area.addEventListener('mouseup', refreshLevel);

    /* ---------- betűszín és kiemelés ---------- */
    var pop = $('#pop-color'), popBtn = $('.ed-pop__b[data-pop="color"]');
    if (pop && popBtn) {
      popBtn.addEventListener('click', function (e) {
        e.stopPropagation();
        pop.classList.toggle('open');
      });
      document.addEventListener('click', function () { pop.classList.remove('open'); });
      pop.addEventListener('click', function (e) { e.stopPropagation(); });

      function applyColor(prop, value) {
        area.focus();
        var sel = window.getSelection();
        if (!sel || sel.isCollapsed) {
          toast('Előbb jelöld ki a szöveget, amit színezni szeretnél.', 'warn');
          return;
        }
        // execCommand foreColor <font> elemet gyártana, azt a mentés kidobná
        var range = sel.getRangeAt(0);
        var span = document.createElement('span');
        span.style[prop] = value;
        try {
          range.surroundContents(span);
        } catch (err) {
          // több elemre átnyúló kijelölés: darabonként
          var frag = range.extractContents();
          span.appendChild(frag);
          range.insertNode(span);
        }
        sel.removeAllRanges();
        syncToSource();
        markDirty();
      }

      pop.querySelectorAll('[data-color]').forEach(function (b) {
        b.addEventListener('click', function () {
          var c = b.getAttribute('data-color');
          applyColor('color', c);
          var bar = $('#ed-color-bar');
          if (bar) { bar.style.background = c; }
          pop.classList.remove('open');
        });
      });
      pop.querySelectorAll('[data-mark]').forEach(function (b) {
        b.addEventListener('click', function () {
          var c = b.getAttribute('data-mark');
          if (c === 'none') {
            area.focus();
            document.execCommand('removeFormat', false, undefined);
            syncToSource();
          } else {
            applyColor('backgroundColor', c);
          }
          pop.classList.remove('open');
        });
      });
      var custom = $('#ed-color-custom');
      if (custom) {
        custom.addEventListener('change', function () {
          applyColor('color', custom.value);
          var bar = $('#ed-color-bar');
          if (bar) { bar.style.background = custom.value; }
          pop.classList.remove('open');
        });
      }
    }

    /* ---------- szöveg igazítása ---------- */
    var ALIGN = { left: 'justifyLeft', center: 'justifyCenter', right: 'justifyRight', justify: 'justifyFull' };
    $$('.ed-toolbar [data-align]').forEach(function (b) {
      b.addEventListener('click', function () {
        area.focus();
        document.execCommand(ALIGN[b.getAttribute('data-align')], false, undefined);
        syncToSource();
        markDirty();
      });
    });

    /* ---------- előugró ablakok közös kezelése ---------- */
    function wirePop(name) {
      var btn = $('.ed-pop__b[data-pop="' + name + '"]');
      var menu = $('#pop-' + name);
      if (!btn || !menu) { return null; }
      btn.addEventListener('click', function (e) {
        e.stopPropagation();
        $$('.ed-pop__m.open').forEach(function (m) { if (m !== menu) { m.classList.remove('open'); } });
        menu.classList.toggle('open');
      });
      menu.addEventListener('click', function (e) { e.stopPropagation(); });
      document.addEventListener('click', function () { menu.classList.remove('open'); });
      return menu;
    }

    /* ---------- új táblázat: rácsválasztó ---------- */
    var popTable = wirePop('table');
    if (popTable) {
      var GRID_R = 8, GRID_C = 8;
      var pickR = 3, pickC = 3;
      var grid = $('#tblgrid'), lbl = $('#tblgrid-lbl');

      for (var r = 1; r <= GRID_R; r++) {
        for (var c = 1; c <= GRID_C; c++) {
          var cell = document.createElement('i');
          cell.dataset.r = String(r);
          cell.dataset.c = String(c);
          grid.appendChild(cell);
        }
      }
      function paintGrid() {
        $$('i', grid).forEach(function (n) {
          n.classList.toggle('on', +n.dataset.r <= pickR && +n.dataset.c <= pickC);
        });
        lbl.textContent = pickR + ' × ' + pickC;
      }
      grid.addEventListener('mouseover', function (e) {
        if (e.target.tagName !== 'I') { return; }
        pickR = +e.target.dataset.r; pickC = +e.target.dataset.c;
        paintGrid();
      });
      grid.addEventListener('click', function (e) {
        if (e.target.tagName !== 'I') { return; }
        pickR = +e.target.dataset.r; pickC = +e.target.dataset.c;
        paintGrid();
      });
      paintGrid();

      $('#tbl-insert').addEventListener('click', function () {
        var withHead = $('#tbl-new-head').checked;
        var rows = withHead ? Math.max(1, pickR - 1) : pickR;
        var html = '<table class="erp-table tbl--grid">';
        if (withHead) {
          html += '<thead><tr>';
          for (var c = 0; c < pickC; c++) { html += '<th>Fejléc ' + (c + 1) + '</th>'; }
          html += '</tr></thead>';
        }
        html += '<tbody>';
        for (var i = 0; i < rows; i++) {
          html += '<tr>';
          for (var c2 = 0; c2 < pickC; c2++) { html += '<td>&nbsp;</td>'; }
          html += '</tr>';
        }
        html += '</tbody></table><p><br></p>';
        area.focus();
        document.execCommand('insertHTML', false, html);
        popTable.classList.remove('open');

        // a kurzor kerüljön rögtön az első cellába, hogy a táblázat-eszközök
        // sávja azonnal megjelenjen és lehessen gépelni
        var fresh = area.querySelectorAll('table');
        var last = fresh[fresh.length - 1];
        if (last) {
          var cell = last.querySelector('th, td');
          if (cell) {
            var rg = document.createRange();
            rg.selectNodeContents(cell);
            rg.collapse(true);
            var sl = window.getSelection();
            sl.removeAllRanges();
            sl.addRange(rg);
          }
        }
        syncToSource();
        markDirty();
        refreshTableBar();
      });
    }

    /* ---------- táblázat-eszközök (a kurzor alatti táblára hatnak) ---------- */
    var tblBar = $('#ed-tbl');

    function currentCell() {
      var sel = window.getSelection();
      if (!sel || !sel.anchorNode) { return null; }
      var n = sel.anchorNode.nodeType === 1 ? sel.anchorNode : sel.anchorNode.parentNode;
      while (n && n !== area) {
        var t = (n.tagName || '').toLowerCase();
        if (t === 'td' || t === 'th') { return n; }
        n = n.parentNode;
      }
      return null;
    }
    function currentTable() {
      var c = currentCell();
      return c ? c.closest('table') : null;
    }

    var BORDERS = ['tbl--grid', 'tbl--rows', 'tbl--frame', 'tbl--plain'];

    function refreshTableBar() {
      if (!tblBar) { return; }
      var t = currentTable();
      tblBar.hidden = !t;
      if (!t) { return; }
      var b = BORDERS.find(function (k) { return t.classList.contains(k); }) || 'tbl--grid';
      var selB = $('#tbl-border');
      if (selB) { selB.value = b.replace('tbl--', ''); }
      var z = $('#tbl-zebra');
      if (z) { z.checked = t.classList.contains('tbl--zebra'); }
    }
    area.addEventListener('keyup', refreshTableBar);
    area.addEventListener('mouseup', refreshTableBar);
    refreshTableBar();

    /** A fejlécsor cellái: a <thead> sorai, vagy a Wordből jött tr.header. */
    function headerCells(t) {
      var cells = [].slice.call(t.querySelectorAll('thead th, thead td, tr.header th, tr.header td'));
      if (!cells.length) {
        var first = t.rows[0];
        if (first) { cells = [].slice.call(first.cells); }
      }
      return cells;
    }

    function afterTableChange() { syncToSource(); markDirty(); refreshTableBar(); }

    if (tblBar) {
      $$('[data-tbl]', tblBar).forEach(function (b) {
        b.addEventListener('click', function () {
          var t = currentTable(), cell = currentCell();
          if (!t || !cell) { return; }
          var row = cell.parentNode;
          var idx = cell.cellIndex;
          var op = b.getAttribute('data-tbl');

          function newRowLike(ref) {
            var tr = document.createElement('tr');
            for (var i = 0; i < ref.cells.length; i++) {
              var td = document.createElement('td');
              td.innerHTML = '&nbsp;';
              tr.appendChild(td);
            }
            return tr;
          }

          if (op === 'row-above') { row.parentNode.insertBefore(newRowLike(row), row); }
          else if (op === 'row-below') { row.parentNode.insertBefore(newRowLike(row), row.nextSibling); }
          else if (op === 'row-del') {
            if (t.rows.length <= 1) { toast('Az utolsó sort nem törlöm — töröld inkább a táblázatot.', 'warn'); return; }
            row.parentNode.removeChild(row);
          }
          else if (op === 'col-left' || op === 'col-right') {
            var at = op === 'col-left' ? idx : idx + 1;
            [].slice.call(t.rows).forEach(function (tr) {
              var isHead = tr.cells[0] && tr.cells[0].tagName === 'TH';
              var c = document.createElement(isHead ? 'th' : 'td');
              c.innerHTML = isHead ? 'Fejléc' : '&nbsp;';
              tr.insertBefore(c, tr.cells[at] || null);
            });
          }
          else if (op === 'col-del') {
            if (t.rows[0] && t.rows[0].cells.length <= 1) { toast('Az utolsó oszlopot nem törlöm.', 'warn'); return; }
            [].slice.call(t.rows).forEach(function (tr) { if (tr.cells[idx]) { tr.deleteCell(idx); } });
          }
          else if (op === 'delete') {
            askConfirm('Biztosan törlöd az egész táblázatot?', function () {
              t.parentNode.removeChild(t);
              tblBar.hidden = true;
              afterTableChange();
            });
            return;
          }
          afterTableChange();
        });
      });

      var borderSel = $('#tbl-border');
      if (borderSel) {
        borderSel.addEventListener('change', function () {
          var t = currentTable();
          if (!t) { return; }
          BORDERS.forEach(function (k) { t.classList.remove(k); });
          t.classList.add('tbl--' + borderSel.value);
          afterTableChange();
        });
      }
      var zebra = $('#tbl-zebra');
      if (zebra) {
        zebra.addEventListener('change', function () {
          var t = currentTable();
          if (!t) { return; }
          t.classList.toggle('tbl--zebra', zebra.checked);
          afterTableChange();
        });
      }

      var popHead = wirePop('tblhead');
      if (popHead) {
        function paintHeader(prop, value) {
          var t = currentTable();
          if (!t) { toast('Állj bele a táblázatba, aminek a fejlécét színezni szeretnéd.', 'warn'); return; }
          headerCells(t).forEach(function (c) {
            if (value === 'none') { c.style.removeProperty(prop); }
            else { c.style[prop === 'background-color' ? 'backgroundColor' : 'color'] = value; }
          });
          afterTableChange();
        }
        $$('[data-hbg]', popHead).forEach(function (b) {
          b.addEventListener('click', function () { paintHeader('background-color', b.getAttribute('data-hbg')); });
        });
        $$('[data-hfg]', popHead).forEach(function (b) {
          b.addEventListener('click', function () { paintHeader('color', b.getAttribute('data-hfg')); });
        });
        var hbgC = $('#tbl-hbg-custom'), hfgC = $('#tbl-hfg-custom');
        if (hbgC) { hbgC.addEventListener('change', function () { paintHeader('background-color', hbgC.value); }); }
        if (hfgC) { hfgC.addEventListener('change', function () { paintHeader('color', hfgC.value); }); }
      }
    }

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

    /**
     * Tipp / Figyelem / Fontos doboz beszúrása.
     *
     * Nem execCommand('insertHTML')-lel, mert az a kurzornál nyitja ki a
     * bekezdést, és a doboz élére szánt <strong> címke kint ragad a fölötte
     * levő bekezdésben. Helyette megkeressük az aktuális BLOKKOT, és a doboz
     * a testvéreként kerül be.
     */
    [['callout-tip', 'tip', 'Tipp'], ['callout-warn', 'warn', 'Figyelem'],
     ['callout-crit', 'crit', 'Fontos']].forEach(function (c) {
      var b = $('.ed-toolbar [data-act="' + c[0] + '"]');
      if (!b) { return; }
      b.addEventListener('click', function () {
        area.focus();
        var sel = window.getSelection();
        var text = String(sel || '').trim();

        // az a legfelső elem a szerkesztőn belül, amiben a kurzor áll
        var block = null;
        if (sel && sel.anchorNode) {
          var n = sel.anchorNode.nodeType === 1 ? sel.anchorNode : sel.anchorNode.parentNode;
          while (n && n.parentNode !== area) { n = n.parentNode; }
          if (n && n.parentNode === area) { block = n; }
        }

        var box = document.createElement('div');
        box.className = 'call ' + c[1];
        var title = document.createElement('strong');
        title.textContent = c[2];
        var body = document.createElement('p');
        body.textContent = text || '…';
        box.appendChild(title);
        box.appendChild(body);

        if (block) { block.parentNode.insertBefore(box, block.nextSibling); }
        else { area.appendChild(box); }

        // a doboz szövegét rögtön lehessen gépelni
        var rg = document.createRange();
        rg.selectNodeContents(body);
        sel.removeAllRanges();
        sel.addRange(rg);

        syncToSource();
        markDirty();
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
          if (['href', 'src', 'alt', 'title', 'colspan', 'rowspan', 'style', 'class'].indexOf(a.name) < 0) { n.removeAttribute(a.name); }
        });
      });
      document.execCommand('insertHTML', false, tmp.innerHTML);
      syncToSource();
    });

    function markDirty() {
      var st = $('#ed-state');
      if (st && !st.dataset.dirty) { st.dataset.dirty = '1'; st.textContent = 'Nem mentett változások — Ctrl+S vagy „Vázlat mentése”.'; }
    }
    area.addEventListener('input', markDirty);

    // kép/videó méretezése kattintásra
    wireMediaResize(ed, area, syncToSource, markDirty);

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


  /* ---------------------------------------------------------- kép/videó méretezése */
  function wireMediaResize(ed, area, syncToSource, markDirty) {
    if (!ed || !area) { return; }

    // a böngésző saját, esetleges fogantyúi helyett a sajátunkat használjuk
    try { document.execCommand('enableObjectResizing', false, 'false'); } catch (e) {}

    var sel = null;         // a kijelölt <img> vagy <video>
    var locked = true;      // arány tartása

    var box = document.createElement('div');
    box.className = 'mres';
    box.innerHTML =
      '<span class="mres__h mres__h--e"  data-dir="e"  title="Szélesség"></span>' +
      '<span class="mres__h mres__h--s"  data-dir="s"  title="Magasság"></span>' +
      '<span class="mres__h mres__h--se" data-dir="se" title="Méret"></span>';
    ed.appendChild(box);

    var bar = document.createElement('div');
    bar.className = 'mres__bar';
    bar.innerHTML =
      '<button type="button" class="mres__lock on" data-act="lock" title="Arány tartása – kattints a kikapcsoláshoz">🔗 Arány</button>' +
      '<label>Sz <input type="number" class="mres__in" data-f="w" min="16" max="4000" step="1"></label>' +
      '<label>Ma <input type="number" class="mres__in" data-f="h" min="16" max="4000" step="1"></label>' +
      '<span class="mres__sep"></span>' +
      '<button type="button" data-pct="25">25%</button>' +
      '<button type="button" data-pct="50">50%</button>' +
      '<button type="button" data-pct="75">75%</button>' +
      '<button type="button" data-pct="100">100%</button>' +
      '<span class="mres__sep"></span>' +
      '<button type="button" data-act="reset" title="Beállított méret törlése">Eredeti</button>' +
      '<button type="button" data-act="close" title="Kijelölés vége">✕</button>';
    ed.appendChild(bar);

    var inW = bar.querySelector('[data-f="w"]');
    var inH = bar.querySelector('[data-f="h"]');
    var lockBtn = bar.querySelector('[data-act="lock"]');

    function ratioOf(el) {
      var nw = el.naturalWidth || el.videoWidth || 0;
      var nh = el.naturalHeight || el.videoHeight || 0;
      if (nw > 0 && nh > 0) { return nw / nh; }
      var r = el.getBoundingClientRect();
      return r.height > 0 ? r.width / r.height : 16 / 9;
    }

    function place() {
      if (!sel) { return; }
      var edR = ed.getBoundingClientRect();
      var r = sel.getBoundingClientRect();
      box.style.left   = (r.left - edR.left) + 'px';
      box.style.top    = (r.top - edR.top) + 'px';
      box.style.width  = r.width + 'px';
      box.style.height = r.height + 'px';

      // a sáv a kép fölé kerül, ha elfér, különben alá
      var above = (r.top - edR.top) > 46;
      bar.style.left = (r.left - edR.left) + 'px';
      bar.style.top  = (above ? (r.top - edR.top - 42) : (r.top - edR.top + r.height + 8)) + 'px';
    }

    function syncInputs() {
      if (!sel) { return; }
      var r = sel.getBoundingClientRect();
      inW.value = Math.round(r.width);
      inH.value = Math.round(r.height);
    }

    function select(el) {
      sel = el;
      box.classList.add('on');
      bar.classList.add('on');
      area.classList.add('ed--hasmedia');
      place();
      syncInputs();
    }

    function deselect() {
      sel = null;
      box.classList.remove('on');
      bar.classList.remove('on');
      area.classList.remove('ed--hasmedia');
    }

    function applySize(w, h) {
      if (!sel) { return; }
      if (w) { sel.style.width = Math.max(16, Math.round(w)) + 'px'; }
      if (h) { sel.style.height = Math.max(16, Math.round(h)) + 'px'; }
      else if (locked) { sel.style.height = 'auto'; }
      // a videó kerete is kövesse a méretet
      if (sel.tagName === 'VIDEO' && sel.parentElement && sel.parentElement.classList.contains('vid')) {
        sel.parentElement.style.width = 'auto';
      }
      place();
      syncInputs();
      syncToSource();
      markDirty();
    }

    area.addEventListener('click', function (e) {
      var el = e.target.closest('img, video');
      if (el && area.contains(el)) { select(el); }
      else { deselect(); }
    });

    // gépelés vagy görgetés közben kövesse a helyét
    ['scroll', 'resize'].forEach(function (ev) {
      window.addEventListener(ev, function () { if (sel) { place(); } }, { passive: true });
    });
    area.addEventListener('input', function () { if (sel) { place(); } });
    area.addEventListener('keydown', function (e) { if (e.key === 'Escape') { deselect(); } });

    // ---- fogantyúk húzása ----
    var drag = null;
    box.addEventListener('pointerdown', function (e) {
      var h = e.target.closest('.mres__h');
      if (!h || !sel) { return; }
      e.preventDefault();
      var r = sel.getBoundingClientRect();
      drag = { dir: h.dataset.dir, x: e.clientX, y: e.clientY, w: r.width, h: r.height, ratio: ratioOf(sel) };
      h.setPointerCapture(e.pointerId);
      box.classList.add('dragging');
    });
    box.addEventListener('pointermove', function (e) {
      if (!drag || !sel) { return; }
      var dx = e.clientX - drag.x, dy = e.clientY - drag.y;
      var w = drag.w, h = drag.h;

      if (drag.dir === 'e')  { w = drag.w + dx; if (locked) { h = w / drag.ratio; } }
      if (drag.dir === 's')  { h = drag.h + dy; if (locked) { w = h * drag.ratio; } }
      if (drag.dir === 'se') {
        w = drag.w + dx;
        h = locked ? w / drag.ratio : drag.h + dy;
      }
      sel.style.width  = Math.max(16, Math.round(w)) + 'px';
      sel.style.height = Math.max(16, Math.round(h)) + 'px';
      place();
      syncInputs();
    });
    ['pointerup', 'pointercancel'].forEach(function (ev) {
      box.addEventListener(ev, function () {
        if (!drag) { return; }
        drag = null;
        box.classList.remove('dragging');
        if (locked && sel) { sel.style.height = 'auto'; place(); syncInputs(); }
        syncToSource();
        markDirty();
      });
    });

    // ---- számmezők ----
    [inW, inH].forEach(function (inp) {
      inp.addEventListener('change', function () {
        if (!sel) { return; }
        var v = Number(inp.value);
        if (!(v > 0)) { syncInputs(); return; }
        var ratio = ratioOf(sel);
        if (inp === inW) { applySize(v, locked ? null : Number(inH.value)); }
        else {
          // magasság: arány-tartással a szélesség is követi
          if (locked) { applySize(v * ratio, null); }
          else { sel.style.height = Math.round(v) + 'px'; place(); syncInputs(); syncToSource(); markDirty(); }
        }
      });
    });

    // ---- sáv gombjai ----
    bar.addEventListener('click', function (e) {
      var b = e.target.closest('button');
      if (!b || !sel) { return; }
      e.preventDefault();

      if (b.dataset.act === 'lock') {
        locked = !locked;
        lockBtn.classList.toggle('on', locked);
        lockBtn.innerHTML = locked ? '🔗 Arány' : '⛓ Szabad';
        lockBtn.title = locked
          ? 'Arány tartása – kattints a kikapcsoláshoz'
          : 'Szabad méretezés – a kép torzulhat. Kattints az arány visszakapcsolásához.';
        if (!locked) {
          // rögzítjük a mostani magasságot, hogy legyen mit szabadon állítani
          var r = sel.getBoundingClientRect();
          sel.style.height = Math.round(r.height) + 'px';
          sel.style.objectFit = 'fill';
          syncToSource();
        } else {
          sel.style.height = 'auto';
          sel.style.objectFit = '';
          applySize(null, null);
        }
        syncInputs();
        return;
      }

      if (b.dataset.pct) {
        // százalékos szélesség: reszponzív marad a nyilvános oldalon is
        sel.style.width = b.dataset.pct + '%';
        sel.style.height = 'auto';
        sel.style.objectFit = '';
        locked = true;
        lockBtn.classList.add('on');
        lockBtn.innerHTML = '🔗 Arány';
        setTimeout(function () { place(); syncInputs(); }, 30);
        syncToSource();
        markDirty();
        return;
      }

      if (b.dataset.act === 'reset') {
        sel.style.width = '';
        sel.style.height = '';
        sel.style.objectFit = '';
        sel.removeAttribute('width');
        sel.removeAttribute('height');
        setTimeout(function () { place(); syncInputs(); }, 30);
        syncToSource();
        markDirty();
        return;
      }

      if (b.dataset.act === 'close') { deselect(); }
    });

    document.addEventListener('click', function (e) {
      if (!sel) { return; }
      if (!area.contains(e.target) && !bar.contains(e.target) && !box.contains(e.target)) { deselect(); }
    });
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
        if (form.dataset.confirmed === '1') { return; }
        e.preventDefault();
        askConfirm(n + ' fejezet átvétele. Folytatod?', function () {
          form.dataset.confirmed = '1';
          if (form.requestSubmit) { form.requestSubmit(); } else { form.submit(); }
        }, 'Word-import átvétele');
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
      if (area && area.textContent.trim() !== '' && btn.dataset.confirmed !== '1') {
        askConfirm('A jelenlegi fordítás felülíródik a gépi nyersfordítással. Folytatod?', function () {
          btn.dataset.confirmed = '1';
          btn.click();
          delete btn.dataset.confirmed;
        }, 'Gépi nyersfordítás');
        return;
      }

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

    // Melyik gombbal küldték be? Az e.submitter a szabványos válasz erre;
    // a document.activeElement NEM megbízható (Safariban a gomb kattintásra
    // nem kap fókuszt, így a művelet üres maradt és a törlés némán elmaradt).
    var lastOpBtn = null;
    bar.addEventListener('mousedown', function (e) {
      var b = e.target.closest('[data-op]');
      if (b) { lastOpBtn = b; }
    });
    bar.addEventListener('keydown', function (e) {
      if (e.key !== 'Enter' && e.key !== ' ') { return; }
      var b = e.target.closest('[data-op]');
      if (b) { lastOpBtn = b; }
    });

    // a kijelölt azonosítók beküldése + a művelet gombjának rögzítése
    bar.addEventListener('submit', function (e) {
      var btnEl = e.submitter
               || lastOpBtn
               || (document.activeElement && document.activeElement.closest
                   ? document.activeElement.closest('[data-op]') : null);
      var op = (btnEl && btnEl.getAttribute('data-op')) || '';
      if (!op) {
        e.preventDefault();
        toast('Nem sikerült megállapítani a műveletet — próbáld újra a gombbal.', 'err');
        return;
      }

      // A törlés megerősítése a saját modálisunkkal megy, nem window.confirm()-mal:
      // azt több böngésző letiltja, és akkor a törlés némán elmaradt.
      if (op === 'delete' && !bar.dataset.delOk) {
        e.preventDefault();
        var n = selected().length;
        var c = $('#bulk-del-count');
        if (c) { c.textContent = String(n); }
        var dm = $('#modal-bulk-delete');
        if (dm) { dm.classList.add('on'); }
        return;
      }
      delete bar.dataset.delOk;

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

    var delOk = $('#bulk-del-ok');
    if (delOk) {
      delOk.addEventListener('click', function () {
        var dm = $('#modal-bulk-delete');
        if (dm) { dm.classList.remove('on'); }
        bar.dataset.delOk = '1';
        lastOpBtn = bar.querySelector('[data-op="delete"]');
        if (bar.requestSubmit) { bar.requestSubmit(lastOpBtn); }
        else { bar.submit(); }
      });
    }

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

    wireConfirmForms();
    wirePickerFold();
    wireModals();
    wireToggles();
    wirePicker();
    wireNewArticleChapter();
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
