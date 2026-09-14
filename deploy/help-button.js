/**
 * help-button.js — az Infinity "?" gombjának viselkedése
 *
 * Két mód:
 *   - Beágyazott fiók (drawer): jobbról becsúszó iframe, az "embed" oldalt tölti be
 *   - Teljes oldal: új fülön nyílik meg
 * A drawerben mindig ott a "Teljes oldal" link is — a felhasználó bármikor átválthat.
 *
 * NYELV HOZZÁRENDELÉSE INFINITY-TÍPUSONKÉNT
 * ------------------------------------------
 * A súgó statikus oldal HÁROM KÜLÖN, egymástól teljesen független nyelvi
 * mappában él: /hu/, /en/, /de/ — mindegyik önmagában is teljes site
 * (saját kereső-index, saját képek, saját minden fejezet).
 *
 * Ez azt jelenti: egy adott Infinity-példány (ügyfél) annyi nyelvet lát,
 * amennyit a HELP_LANG beállítás mond neki — nem kell mindhárom nyelvet
 * letöltenie vagy ismernie. A beállítás Infinity-oldalon, szerver-rendelt
 * változóként (pl. a cég/telephely nyelvi beállításából) kerül ide:
 *
 *   <script>
 *     window.INFINITY_HELP_CONFIG = {
 *       baseUrl: "https://help.infinityhu.eu",
 *       lang: "<?= Yii::$app->language ?? 'hu' ?>"    // hu | en | de
 *     };
 *   </script>
 *   <script src="/js/help-button.js"></script>
 *
 * Ha egy ügyfél csak magyarul dolgozik, a lang mindig "hu" lesz nála — a
 * súgó szerveren ettől függetlenül mind a három mappa elérhető marad,
 * csak az adott Infinity-példány sosem hivatkozik a másik kettőre.
 */
(function () {
  "use strict";
  var CFG = window.INFINITY_HELP_CONFIG || {};
  var BASE = (CFG.baseUrl || "https://help.infinityhu.eu").replace(/\/$/, "");
  var LANG = ["hu", "en", "de"].indexOf(CFG.lang) >= 0 ? CFG.lang : "hu";

  var LABELS = {
    hu: { title: "Súgó", full: "Megnyitás teljes oldalon", close: "Bezárás" },
    en: { title: "Help", full: "Open full page", close: "Close" },
    de: { title: "Hilfe", full: "Volle Seite öffnen", close: "Schließen" }
  }[LANG];

  var drawer = null, iframe = null;

  function ensureDrawer() {
    if (drawer) { return; }
    drawer = document.createElement("div");
    drawer.id = "infinity-help-drawer";
    drawer.innerHTML =
      '<div class="ihd__mask"></div>' +
      '<div class="ihd__panel">' +
        '<div class="ihd__bar">' +
          '<b>' + LABELS.title + '</b>' +
          '<a class="ihd__full" target="_blank" rel="noopener">' + LABELS.full + '</a>' +
          '<button class="ihd__close" type="button" aria-label="' + LABELS.close + '">&times;</button>' +
        '</div>' +
        '<iframe class="ihd__frame" title="' + LABELS.title + '"></iframe>' +
      '</div>';
    document.body.appendChild(drawer);
    iframe = drawer.querySelector(".ihd__frame");

    var css = document.createElement("style");
    css.textContent =
      "#infinity-help-drawer{position:fixed;inset:0;z-index:99999;display:none;font-family:Inter,-apple-system,'Segoe UI',sans-serif}" +
      "#infinity-help-drawer.on{display:block}" +
      "#infinity-help-drawer .ihd__mask{position:absolute;inset:0;background:rgba(15,23,42,.32)}" +
      "#infinity-help-drawer .ihd__panel{position:absolute;top:0;right:0;bottom:0;width:min(480px,100vw);" +
      "background:#fff;box-shadow:-8px 0 28px rgba(0,0,0,.16);display:flex;flex-direction:column;" +
      "transform:translateX(16px);opacity:0;transition:transform .18s ease,opacity .18s ease}" +
      "#infinity-help-drawer.on .ihd__panel{transform:translateX(0);opacity:1}" +
      "#infinity-help-drawer .ihd__bar{display:flex;align-items:center;gap:10px;padding:11px 8px 11px 16px;" +
      "border-bottom:1px solid #E8E7E3;background:#FAFAF9}" +
      "#infinity-help-drawer .ihd__bar b{font-size:13px;color:#1E293B;flex:1}" +
      "#infinity-help-drawer .ihd__full{font-size:12px;color:#6B7280;text-decoration:none;white-space:nowrap}" +
      "#infinity-help-drawer .ihd__full:hover{color:#1B3A6B}" +
      "#infinity-help-drawer .ihd__close{border:0;background:transparent;font-size:20px;line-height:1;" +
      "color:#9CA3AF;cursor:pointer;padding:2px 8px}" +
      "#infinity-help-drawer .ihd__close:hover{color:#1E293B}" +
      "#infinity-help-drawer .ihd__frame{flex:1;border:0;width:100%}";
    document.head.appendChild(css);

    drawer.querySelector(".ihd__mask").addEventListener("click", closeDrawer);
    drawer.querySelector(".ihd__close").addEventListener("click", closeDrawer);
    document.addEventListener("keydown", function (e) {
      if (e.key === "Escape" && drawer.classList.contains("on")) { closeDrawer(); }
    });
  }

  function openDrawer(slug) {
    ensureDrawer();
    iframe.src = BASE + "/" + LANG + "/embed/" + slug + ".html";
    drawer.querySelector(".ihd__full").href = BASE + "/" + LANG + "/" + slug + ".html";
    drawer.classList.add("on");
  }
  function closeDrawer() {
    if (drawer) { drawer.classList.remove("on"); }
  }
  function openFullPage(slug) {
    window.open(BASE + "/" + LANG + "/" + slug + ".html", "_blank", "noopener");
  }

  /**
   * Publikus API — ezt hívja az Infinity oldal a "?" gombra kattintva.
   *   window.InfinityHelp.open("5-4-kintlevoseg-kezeles")            -> drawer
   *   window.InfinityHelp.open("5-4-kintlevoseg-kezeles", "full")    -> uj fulon
   *   window.InfinityHelp.close()
   *
   * A slug-ot a screenmap.json (route -> slug) adja route alapjan; ezt az
   * Infinity oldal PHP resze forditja le (lasd screenmap_lookup.php pelda).
   */
  window.InfinityHelp = {
    open: function (slug, mode) {
      if (!slug) { return; }
      if (mode === "full") { openFullPage(slug); } else { openDrawer(slug); }
    },
    close: closeDrawer,
    lang: LANG,
    baseUrl: BASE
  };
}());
