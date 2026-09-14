#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
build_site.py — statikus súgó oldal a help.infinityhu.eu domainre

  python3 build_site.py                        mindhárom nyelv
  python3 build_site.py --lang en --out site
  python3 build_site.py --base https://help.infinityhu.eu

Kimenet (site/):
  index.html                    nyelvváltó / átirányítás
  <lang>/index.html             kezdőlap fanézettel
  <lang>/<slug>.html            fejezetenként külön oldal (szerverről kiszolgált HTML)
  <lang>/kereso.html            kereső oldal
  assets/help.css, help.js
  media/                        képernyőképek
  api/index.<lang>.json         kereső index + fanézet (a drawer is ezt használja)
  api/article/<slug>.<lang>.json  egy fejezet tartalma JSON-ban (kontextus-súgóhoz)
  api/screenmap.json            route → fejezet hozzárendelés
  sitemap.xml, robots.txt, 404.html

Miért statikus:
  - a súgó tartalma naponta legfeljebb egyszer változik, olvasója viszont sok
  - nem kell PHP és adatbázis a help hoston, nincs mit megtámadni
  - akkor is elérhető, ha az Infinity épp áll
  - a keresés kliensoldalon fut a JSON indexből, nincs szerverterhelés
"""
import json, os, re, shutil, argparse, unicodedata, html as H

CSS = """*{box-sizing:border-box}
:root{--bg:#F7F7F5;--panel:#fff;--ink:#333B4D;--ink-strong:#1E293B;--muted:#6B7280;--faint:#9CA3AF;
--line:#E8E7E3;--line-soft:#F1F0EE;--navy:#1B3A6B;--amber:#F59E0B;--r:6px}
html{scroll-behavior:smooth}
body{margin:0;font-family:Inter,-apple-system,'Segoe UI',sans-serif;background:var(--bg);color:var(--ink);
font-size:15px;line-height:1.68;-webkit-font-smoothing:antialiased}
a{color:var(--navy)}
.top{position:sticky;top:0;z-index:20;background:rgba(255,255,255,.92);backdrop-filter:blur(8px);
border-bottom:1px solid var(--line)}
.top__in{max-width:1320px;margin:0 auto;padding:12px 24px;display:flex;align-items:center;gap:16px}
.brand{display:flex;align-items:center;gap:10px;text-decoration:none}
.brand img{height:26px;width:auto}
.brand b{font-size:16px;font-weight:700;color:var(--navy);letter-spacing:.02em}
.brand span{font-size:13px;color:var(--muted)}
.ver{font-size:12px;color:var(--faint)}
.langsw{display:flex;gap:2px;background:#FAFAF9;border:1px solid var(--line);border-radius:9999px;padding:2px}
.langsw a{font-size:12px;font-weight:600;color:var(--muted);padding:4px 12px;border-radius:9999px;text-decoration:none}
.langsw a.on{background:var(--navy);color:#fff}
.search{margin-left:auto;position:relative}
.search input{width:320px;height:36px;border:1px solid var(--line);border-radius:9999px;background:#FAFAF9;
padding:0 16px 0 38px;font-size:13px;font-family:inherit;color:var(--ink)}
.search input:focus{outline:none;border-color:var(--navy);background:#fff;box-shadow:0 0 0 3px rgba(27,58,107,.08)}
.search svg{position:absolute;left:13px;top:10px;width:15px;height:15px;stroke:var(--faint);fill:none;stroke-width:2}
.res{position:absolute;top:44px;right:0;width:520px;max-height:60vh;overflow:auto;background:#fff;
border:1px solid var(--line);border-radius:10px;box-shadow:0 14px 40px rgba(0,0,0,.10);display:none}
.res.on{display:block}
.res a{display:block;padding:11px 15px;border-bottom:1px solid var(--line-soft);text-decoration:none;color:inherit}
.res a:hover{background:#F5F7FA}
.res b{display:block;font-size:13.5px;color:var(--ink-strong)}
.res span{font-size:12px;color:var(--faint)}
.wrap{max-width:1320px;margin:0 auto;padding:26px 24px 80px;display:grid;grid-template-columns:262px 1fr 214px;gap:36px}
.side{position:sticky;top:76px;align-self:start;max-height:calc(100vh - 96px);overflow:auto;padding-right:6px}
.side__m{margin-bottom:14px}
.side__mt{display:flex;gap:8px;font-size:13px;font-weight:600;color:var(--ink-strong);padding:5px 0}
.side__mt i{color:var(--faint);font-style:normal;min-width:16px}
.side a{display:block;font-size:13px;color:var(--muted);text-decoration:none;padding:4px 0 4px 24px;border-left:2px solid transparent}
.side a:hover{color:var(--navy)}
.side a.on{color:var(--navy);font-weight:600;border-left-color:var(--navy);background:#F5F7FA}
.main{min-width:0;background:var(--panel);border:1px solid var(--line);border-radius:12px;padding:38px 46px 52px}
.bc{font-size:12px;color:var(--faint);margin-bottom:10px}
.bc a{color:var(--faint);text-decoration:none}
h1{font-size:29px;font-weight:700;color:var(--ink-strong);margin:0 0 6px;letter-spacing:-.01em}
.meta{font-size:12px;color:var(--faint);border-bottom:1px solid var(--line-soft);padding-bottom:14px;margin-bottom:26px}
.doc{max-width:760px}
.doc h2{font-size:20px;font-weight:600;color:var(--ink-strong);margin:36px 0 10px;scroll-margin-top:88px}
.doc h3{font-size:16.5px;font-weight:600;color:var(--ink-strong);margin:28px 0 8px;scroll-margin-top:88px}
.doc h2 .hno,.doc h3 .hno{color:var(--faint);font-weight:500;margin-right:8px}
.doc p{margin:0 0 14px}
.doc ul,.doc ol{margin:0 0 14px;padding-left:22px}
.doc li{margin-bottom:6px}
.doc img{max-width:100%;height:auto;border:1px solid var(--line);border-radius:6px;margin:6px 0}
.doc .call{background:#FAFAF9;border:1px solid var(--line);border-left:3px solid var(--navy);
border-radius:0 var(--r) var(--r) 0;padding:14px 18px;margin:18px 0;font-size:14px}
.doc .call.warn{background:#FFFDF5;border-color:#F3E7C4;border-left-color:var(--amber)}
.doc .call strong{display:block;font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.06em;
color:var(--muted);margin-bottom:4px}
.doc table{width:100%;border-collapse:collapse;margin:16px 0;font-size:13.5px}
.doc th{background:#FAFAF9;text-align:left;padding:9px 12px;font-size:11px;text-transform:uppercase;
letter-spacing:.05em;color:var(--muted);border-bottom:1px solid var(--line)}
.doc td{padding:9px 12px;border-bottom:1px solid var(--line-soft);vertical-align:top}
.doc figure{margin:16px 0}
.doc figcaption{font-size:12px;color:var(--faint);margin-top:5px}
.rail{position:sticky;top:76px;align-self:start;font-size:12.5px}
.rail__t{font-size:10px;font-weight:600;text-transform:uppercase;letter-spacing:.08em;color:var(--faint);margin-bottom:8px}
.rail a{display:block;color:var(--muted);text-decoration:none;padding:3px 0 3px 10px;border-left:2px solid var(--line)}
.rail a:hover{color:var(--navy);border-left-color:var(--navy)}
.nav{display:flex;gap:14px;margin-top:44px;padding-top:22px;border-top:1px solid var(--line-soft)}
.nav a{flex:1;text-decoration:none;border:1px solid var(--line);border-radius:8px;padding:12px 16px;background:#FAFAF9}
.nav a:hover{border-color:var(--navy);background:#F5F7FA}
.nav small{display:block;font-size:11px;color:var(--faint);margin-bottom:2px}
.nav b{font-size:13.5px;color:var(--ink-strong);font-weight:600}
.nav .r{text-align:right}
.hero{background:var(--panel);border:1px solid var(--line);border-radius:12px;padding:40px 46px}
.hero h1{font-size:32px}
.hero p{color:var(--muted);max-width:640px}
.grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(250px,1fr));gap:14px;margin-top:26px}
.card{border:1px solid var(--line);border-radius:10px;padding:16px 18px;text-decoration:none;background:#FAFAF9}
.card:hover{border-color:var(--navy);background:#F5F7FA}
.card b{display:block;font-size:14px;color:var(--ink-strong);margin-bottom:3px}
.card span{font-size:12px;color:var(--faint)}
.foot{max-width:1320px;margin:0 auto;padding:0 24px 60px;font-size:12px;color:var(--faint)}
@media(max-width:1180px){.wrap{grid-template-columns:240px 1fr}.rail{display:none}}
@media(max-width:820px){.wrap{grid-template-columns:1fr}.side{position:static;max-height:none}
.main{padding:26px 22px 36px}.search input{width:190px}}
@media print{.top,.side,.rail,.nav,.foot{display:none}.wrap{display:block;padding:0}
.main{border:0;padding:0}}
"""

JS = """(function () {
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
        return g.trim().split(/\\s+/).filter(Boolean);
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
"""

UI = {
    "hu": {"help": "Súgó", "search": "Keresés a súgóban…", "nores": "Nincs találat. Szóköz = ÉS, vessző = VAGY.",
           "onpage": "Ezen az oldalon", "prev": "Előző", "next": "Következő", "upd": "Frissítve",
           "lead": "Válassz modult, vagy keress rá arra, amit épp keresel. A keresés ékezet-független.",
           "foot": "Ez a súgó az Infinity ERP-hez készült.", "chapters": "fejezet"},
    "en": {"help": "Help", "search": "Search the help…", "nores": "No results. Space = AND, comma = OR.",
           "onpage": "On this page", "prev": "Previous", "next": "Next", "upd": "Updated",
           "lead": "Pick a module, or search for what you need. The search ignores accents.",
           "foot": "This help was made for Infinity ERP.", "chapters": "chapters"},
    "de": {"help": "Hilfe", "search": "Hilfe durchsuchen…", "nores": "Keine Treffer. Leerzeichen = UND, Komma = ODER.",
           "onpage": "Auf dieser Seite", "prev": "Zurück", "next": "Weiter", "upd": "Aktualisiert",
           "lead": "Wählen Sie ein Modul oder suchen Sie direkt. Die Suche ignoriert Diakritika.",
           "foot": "Diese Hilfe wurde für Infinity ERP erstellt.", "chapters": "Kapitel"},
}


def esc(s):
    return H.escape(str(s or ""), quote=True)


def plain(h):
    return re.sub(r"\s+", " ", re.sub(r"<[^>]+>", " ", h or "")).strip()


def norm(s):
    s = unicodedata.normalize("NFD", (s or "").lower())
    return "".join(c for c in s if unicodedata.category(c) != "Mn")


def body_html(a, base=""):
    """A kepek media/... hivatkozasa relativ; az /<lang>/ alkonyvtarbol nem
       talalna meg, ezert abszolutra irjuk at."""
    out = a["intro"] or ""
    for s in a["sections"]:
        tag = "h2" if s["lvl"] == 3 else "h3"
        out += '<%s id="%s"><span class="hno">%s</span>%s</%s>%s' % (
            tag, s["anchor"], s["no"], esc(s["title"]), tag, s["html"] or "")
    return out.replace('src="media/', 'src="%s/media/' % base)


def head(lang, title, desc, base, canon, alts, ver):
    h = ['<!DOCTYPE html><html lang="%s"><head><meta charset="utf-8">' % lang,
         '<meta name="viewport" content="width=device-width,initial-scale=1">',
         "<title>%s</title>" % esc(title),
         '<meta name="description" content="%s">' % esc(desc[:180]),
         '<link rel="canonical" href="%s">' % canon]
    for L, u in alts.items():
        h.append('<link rel="alternate" hreflang="%s" href="%s">' % (L, u))
    h.append('<link rel="preconnect" href="https://fonts.googleapis.com">')
    h.append('<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">')
    h.append('<link rel="stylesheet" href="%s/assets/help.css?v=%s">' % (base, ver))
    h.append("</head><body>")
    return "\n".join(h)


def topbar(lang, base, ver, nart, langs):
    u = UI[lang]
    sw = "".join('<a class="%s" href="%s/%s/">%s</a>' % ("on" if L == lang else "", base, L, L.upper())
                 for L in langs)
    return ('<div class="top"><div class="top__in">'
            '<a class="brand" href="%s/%s/"><img src="%s/assets/logo.png" alt="Infinity">'
            "<b>INFINITY</b><span>%s</span></a>"
            '<span class="ver">%s · %d %s</span>'
            '<div class="langsw">%s</div>'
            '<div class="search"><svg viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/>'
            '<path d="M20 20l-4-4"/></svg>'
            '<input id="q" placeholder="%s" autocomplete="off">'
            '<div class="res" id="res"></div></div>'
            "</div></div>") % (base, lang, base, u["help"], ver, nart, u["chapters"], sw, esc(u["search"]))


def sidebar(D, lang, base, cur):
    h = ['<nav class="side">']
    A = dict((a["slug"], a) for a in D["articles"])
    for m in D["modules"]:
        h.append('<div class="side__m"><div class="side__mt"><i>%s</i>%s</div>' % (m["no"], esc(m["title"])))
        for slug in m["articles"]:
            a = A.get(slug)
            if not a:
                continue
            h.append('<a class="%s" href="%s/%s/%s.html">%s %s</a>'
                     % ("on" if slug == cur else "", base, lang, slug, a["no"], esc(a["title"])))
        h.append("</div>")
    h.append("</nav>")
    return "".join(h)


def embed_head(lang, title, ver):
    return ("<!DOCTYPE html><html lang=\"%s\"><head><meta charset=\"utf-8\">"
            "<meta name=\"viewport\" content=\"width=device-width,initial-scale=1\">"
            "<meta name=\"robots\" content=\"noindex\">"
            "<title>%s</title>"
            "<style>%s</style></head><body>") % (lang, esc(title), EMBED_CSS)


EMBED_CSS = """*{box-sizing:border-box}
html{scroll-behavior:smooth}
body{margin:0;font-family:Inter,-apple-system,'Segoe UI',sans-serif;background:#fff;color:#333B4D;
font-size:14px;line-height:1.65;-webkit-font-smoothing:antialiased}
a{color:#1B3A6B}
.bar{position:sticky;top:0;display:flex;align-items:center;gap:10px;padding:10px 16px;
background:#FAFAF9;border-bottom:1px solid #E8E7E3;font-size:12px}
.bar b{font-size:13px;color:#1E293B;font-weight:600;flex:1;min-width:0;overflow:hidden;
text-overflow:ellipsis;white-space:nowrap}
.bar a{color:#6B7280;text-decoration:none;white-space:nowrap;display:flex;align-items:center;gap:4px}
.bar a:hover{color:#1B3A6B}
.bar svg{width:12px;height:12px;stroke:currentColor;fill:none;stroke-width:2}
.pad{padding:18px 20px 30px}
h1{font-size:19px;font-weight:700;color:#1E293B;margin:0 0 12px}
h2{font-size:16px;font-weight:600;color:#1E293B;margin:22px 0 8px;scroll-margin-top:50px}
h3{font-size:14.5px;font-weight:600;color:#1E293B;margin:18px 0 6px;scroll-margin-top:50px}
h2 .hno,h3 .hno{color:#9CA3AF;font-weight:500;margin-right:6px}
p{margin:0 0 11px}
ul,ol{margin:0 0 11px;padding-left:20px}
li{margin-bottom:4px}
img{max-width:100%;height:auto;border:1px solid #E8E7E3;border-radius:5px;margin:5px 0}
.call{background:#FAFAF9;border:1px solid #E8E7E3;border-left:3px solid #1B3A6B;border-radius:0 6px 6px 0;
padding:11px 14px;margin:14px 0;font-size:13px}
.call.warn{background:#FFFDF5;border-color:#F3E7C4;border-left-color:#F59E0B}
.call strong{display:block;font-size:10px;font-weight:600;text-transform:uppercase;letter-spacing:.05em;
color:#6B7280;margin-bottom:3px}
table{width:100%;border-collapse:collapse;margin:13px 0;font-size:12.5px}
th{background:#FAFAF9;text-align:left;padding:7px 10px;font-size:10px;text-transform:uppercase;
letter-spacing:.04em;color:#6B7280;border-bottom:1px solid #E8E7E3}
td{padding:7px 10px;border-bottom:1px solid #F1F0EE;vertical-align:top}
figcaption{font-size:11px;color:#9CA3AF;margin-top:4px}
.foot{padding:14px 20px;border-top:1px solid #F1F0EE;font-size:11px;color:#9CA3AF}
"""


def build_embed(D, lang, out, base, ver):
    """Minimal, iframe-be valo nezet: nincs sidebar, nincs kereso — csak a
       tartalom + egy vekony sav a teteje, ami mutatja hol vagyunk es
       linkel a teljes oldalra. Ezt nyitja meg a '?' gomb az Infinity-ben."""
    d = os.path.join(out, lang, "embed")
    os.makedirs(d, exist_ok=True)
    A = dict((a["slug"], a) for a in D["articles"])
    u = UI[lang]
    for a in D["articles"]:
        full_url = "%s/%s/%s.html" % (base, lang, a["slug"])
        page = (embed_head(lang, a["title"], ver)
                + '<div class="bar">'
                + '<b>%s %s</b>' % (a["no"], esc(a["title"]))
                + '<a href="%s" target="_blank" rel="noopener">%s'
                  '<svg viewBox="0 0 24 24"><path d="M7 17L17 7M9 7h8v8"/></svg></a>'
                  % (full_url, esc({"hu": "Teljes oldal", "en": "Full page", "de": "Volle Seite"}[lang]))
                + "</div>"
                + '<div class="pad">' + body_html(a, base) + "</div>"
                + '<div class="foot">%s %s · %s</div>' % (esc(u["upd"]), a["updated"], ver)
                + "</body></html>")
        open(os.path.join(d, a["slug"] + ".html"), "w", encoding="utf-8").write(page)
    return len(D["articles"])


def build_lang(D, lang, out, base, langs, media_src):
    u = UI[lang]
    ver = D["version"]
    d = os.path.join(out, lang)
    os.makedirs(d, exist_ok=True)
    A = dict((a["slug"], a) for a in D["articles"])
    order = []
    for m in D["modules"]:
        for slug in m["articles"]:
            if slug in A:
                order.append(slug)

    # --- kezdolap ---
    alts = dict((L, "%s/%s/" % (base, L)) for L in langs)
    cards = "".join(
        '<a class="card" href="%s/%s/%s.html"><b>%s %s</b><span>%d %s</span></a>'
        % (base, lang, m["articles"][0], m["no"], esc(m["title"]), len(m["articles"]), u["chapters"])
        for m in D["modules"] if m["articles"])
    idx = (head(lang, "Infinity " + u["help"], u["lead"], base, "%s/%s/" % (base, lang), alts, ver)
           + topbar(lang, base, ver, len(D["articles"]), langs)
           + '<div class="wrap" style="grid-template-columns:262px 1fr">'
           + sidebar(D, lang, base, "")
           + '<div class="hero"><h1>Infinity %s</h1><p>%s</p><div class="grid">%s</div></div></div>'
           % (u["help"], esc(u["lead"]), cards)
           + '<div class="foot">%s · %s</div>' % (esc(u["foot"]), ver)
           + '<script>var BASE="%s",LANG="%s",NORES=%s;</script>' % (base, lang, json.dumps(u["nores"]))
           + '<script src="%s/assets/help.js?v=%s"></script></body></html>' % (base, ver))
    open(os.path.join(d, "index.html"), "w", encoding="utf-8").write(idx)

    # --- fejezetoldalak ---
    for i, slug in enumerate(order):
        a = A[slug]
        rail = ""
        if a["sections"]:
            rail = ('<aside class="rail"><div class="rail__t">%s</div>' % esc(u["onpage"])
                    + "".join('<a href="#%s">%s %s</a>' % (s["anchor"], s["no"], esc(s["title"]))
                              for s in a["sections"] if s["lvl"] == 3)
                    + "</aside>")
        nav = '<div class="nav">'
        if i > 0:
            p = A[order[i - 1]]
            nav += '<a href="%s/%s/%s.html"><small>%s</small><b>%s %s</b></a>' % (
                base, lang, p["slug"], esc(u["prev"]), p["no"], esc(p["title"]))
        if i < len(order) - 1:
            n = A[order[i + 1]]
            nav += '<a class="r" href="%s/%s/%s.html"><small>%s</small><b>%s %s</b></a>' % (
                base, lang, n["slug"], esc(u["next"]), n["no"], esc(n["title"]))
        nav += "</div>"
        alts = dict((L, "%s/%s/%s.html" % (base, L, slug)) for L in langs)
        page = (head(lang, "%s %s — Infinity %s" % (a["no"], a["title"], u["help"]),
                     plain(a["intro"]), base, "%s/%s/%s.html" % (base, lang, slug), alts, ver)
                + topbar(lang, base, ver, len(D["articles"]), langs)
                + '<div class="wrap">' + sidebar(D, lang, base, slug)
                + '<article class="main"><div class="bc"><a href="%s/%s/">%s</a> › %s %s › %s</div>'
                % (base, lang, u["help"], a["module_no"], esc(a["module"]), a["no"])
                + "<h1>%s</h1>" % esc(a["title"])
                + '<div class="meta">%s %s · %s</div>' % (esc(u["upd"]), a["updated"], ver)
                + '<div class="doc">' + body_html(a, base) + "</div>" + nav + "</article>"
                + rail + "</div>"
                + '<div class="foot">%s · %s</div>' % (esc(u["foot"]), ver)
                + '<script>var BASE="%s",LANG="%s",NORES=%s;</script>' % (base, lang, json.dumps(u["nores"]))
                + '<script src="%s/assets/help.js?v=%s"></script></body></html>' % (base, ver))
        open(os.path.join(d, slug + ".html"), "w", encoding="utf-8").write(page)

    # --- API: kereso index + fanezet ---
    api = os.path.join(out, "api")
    os.makedirs(os.path.join(api, "article"), exist_ok=True)
    index = []
    for m in D["modules"]:
        for slug in m["articles"]:
            a = A.get(slug)
            if not a:
                continue
            full = plain(a["intro"]) + " " + " ".join(s["title"] + " " + plain(s["html"]) for s in a["sections"])
            index.append({"slug": slug, "no": a["no"], "t": a["title"], "m": m["no"] + " " + m["title"],
                          "anchor": "", "txt": norm(a["no"] + " " + a["title"] + " " + m["title"] + " " + full)[:3000]})
            for s in a["sections"]:
                index.append({"slug": slug, "no": s["no"], "t": s["title"], "m": a["no"] + " " + a["title"],
                              "anchor": s["anchor"],
                              "txt": norm(s["no"] + " " + s["title"] + " " + plain(s["html"]))[:2000]})
    json.dump({"version": ver, "lang": lang, "modules": D["modules"], "index": index},
              open(os.path.join(api, "index.%s.json" % lang), "w", encoding="utf-8"),
              ensure_ascii=False, separators=(",", ":"))
    # egy fejezet JSON-ban: ezt hivja az Infinity "?" gombja
    for slug, a in A.items():
        json.dump({"slug": slug, "lang": lang, "no": a["no"], "title": a["title"],
                   "module": a["module"], "module_no": a["module_no"], "updated": a["updated"],
                   "version": ver, "url": "%s/%s/%s.html" % (base, lang, slug),
                   "sections": [{"no": s["no"], "anchor": s["anchor"], "title": s["title"]} for s in a["sections"]],
                   "html": body_html(a, base)},
                  open(os.path.join(api, "article", "%s.%s.json" % (slug, lang)), "w", encoding="utf-8"),
                  ensure_ascii=False, separators=(",", ":"))
    return order


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--lang", default=None)
    ap.add_argument("--out", default="site")
    ap.add_argument("--base", default="")          # ures = relativ, gyoker alatt szolgalva
    ap.add_argument("--media", default="media")
    a = ap.parse_args()

    langs = [a.lang] if a.lang else ["hu", "en", "de"]
    out, base = a.out, a.base.rstrip("/")
    os.makedirs(os.path.join(out, "assets"), exist_ok=True)
    open(os.path.join(out, "assets", "help.css"), "w", encoding="utf-8").write(CSS)
    open(os.path.join(out, "assets", "help.js"), "w", encoding="utf-8").write(JS)
    if os.path.exists("logo.png"):
        shutil.copy("logo.png", os.path.join(out, "assets", "logo.png"))

    if os.path.isdir(a.media):
        dst = os.path.join(out, "media")
        os.makedirs(dst, exist_ok=True)
        n = 0
        for f in os.listdir(a.media):
            if not os.path.exists(os.path.join(dst, f)):
                shutil.copy(os.path.join(a.media, f), os.path.join(dst, f))
            n += 1
        print("media: %d kép" % n)

    total, ver = 0, ""
    urls = []
    for lang in langs:
        f = "help_articles_%s.json" % lang
        if not os.path.exists(f):
            print("kimarad (nincs %s)" % f)
            continue
        D = json.load(open(f, encoding="utf-8"))
        ver = D["version"]
        order = build_lang(D, lang, out, base, langs, a.media)
        n_embed = build_embed(D, lang, out, base, ver)
        total += len(order)
        urls.append("%s/%s/" % (base, lang))
        urls += ["%s/%s/%s.html" % (base, lang, s) for s in order]
        print("%s: %d oldal + %d beágyazható (embed) oldal" % (lang, len(order) + 1, n_embed))

    # --- gyokeroldal: nyelvfelismeres + atiranyitas ---
    root = ('<!DOCTYPE html><html lang="hu"><head><meta charset="utf-8">'
            '<title>Infinity Súgó</title><script>'
            'var l=(navigator.language||"hu").substring(0,2);'
            'if(["hu","en","de"].indexOf(l)<0){l="hu";}'
            'location.replace("' + (base or ".") + '/"+l+"/");'
            "</script></head><body>"
            '<p style="font:15px Inter,sans-serif;padding:40px">'
            '<a href="hu/">Magyar</a> · <a href="en/">English</a> · <a href="de/">Deutsch</a>'
            "</p></body></html>")
    open(os.path.join(out, "index.html"), "w", encoding="utf-8").write(root)

    open(os.path.join(out, "404.html"), "w", encoding="utf-8").write(
        '<!DOCTYPE html><html lang="hu"><head><meta charset="utf-8"><title>Nincs ilyen oldal</title>'
        '<link rel="stylesheet" href="' + (base or "") + '/assets/help.css"></head><body>'
        '<div class="wrap" style="grid-template-columns:1fr"><div class="hero">'
        "<h1>Nincs ilyen oldal</h1><p>Lehet, hogy a fejezet átkerült máshova. "
        'Nyisd meg a <a href="' + (base or "") + '/hu/">súgó kezdőlapját</a>, vagy keress rá a témára.</p>'
        "</div></div></body></html>")

    sm = ['<?xml version="1.0" encoding="UTF-8"?>',
          '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">']
    for u in urls:
        sm.append("  <url><loc>%s</loc></url>" % u)
    sm.append("</urlset>")
    open(os.path.join(out, "sitemap.xml"), "w", encoding="utf-8").write("\n".join(sm))
    open(os.path.join(out, "robots.txt"), "w", encoding="utf-8").write(
        "User-agent: *\nAllow: /\nSitemap: %s/sitemap.xml\n" % (base or ""))

    # --- screen map csontvaz a kontextus-sugohoz ---
    smp = os.path.join(out, "api", "screenmap.json")
    if not os.path.exists(smp):
        json.dump({"_note": "route -> fejezet slug. Az Infinity ? gombja ezt hasznalja.",
                   "invoice/index": "4-1-szamlak",
                   "partner/index": "4-5-partnerek",
                   "product/index": "6-2-arucikkek",
                   "warehouse/movement": "6-4-raktarkezeles",
                   "hr/employee": "10-8-munkavallalok-aktiv-dolgozo-kezeles"},
                  open(smp, "w", encoding="utf-8"), ensure_ascii=False, indent=1)

    print("Kész: %s/  (%d fejezetoldal, verzió %s)" % (out, total, ver))
    print("Feltöltés:  ./deploy.sh")


if __name__ == "__main__":
    main()
