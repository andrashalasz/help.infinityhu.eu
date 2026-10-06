/**
 * Kepernyokep-keszito a sugohoz.
 *
 * Miert van ez: a sugo fejezeteiben szereplo kepek elavulnak, ahogy az ERP
 * felulete valtozik. Kezzel ujrafotozni mindet napokba telik, ezert senki nem
 * csinalja meg - a sugo pedig lassan hazudni kezd. Ez a szkript egy paranccsal
 * ujragyartja az osszeset, mindig ugyanabban a meretben es kivagasban.
 *
 * A kepek listaja adat, nem kod: uj kephez egy sort kell felvenni a KEPEK
 * tombbe. A `lepesek` fuggveny kapja a lapot, es azt csinal vele, amit kell
 * (szuro, kattintas, panel megnyitasa), mielott a kep elkeszul.
 *
 * Futtatas (a repo gyokerebol):
 *   ./tools/kepernyokepek/futtat.sh
 *
 * Kornyezeti valtozok:
 *   ERP_URL   - a rendszer cime        (alap: https://release.infinityhu.eu)
 *   ERP_USER  - felhasznalonev
 *   ERP_PASS  - jelszo
 *   KIMENET   - hova mentse a PNG-ket  (alap: /kimenet)
 *   CSAK      - csak az ilyen nevu kepeket gyartja ujra (reszlet is eleg)
 *
 * A kepek neve a sugoban a TARTALOM hash-ebol szuletik, ezert ha egy kepet
 * ujragyartunk, a neve is megvaltozik - a fejezetben levo hivatkozast is
 * frissiteni kell. Emiatt alapbol ne futtasd ujra az osszeset, csak azt,
 * amelyikre tenyleg szukseg van:
 *
 *   CSAK=javaslat ./tools/kepernyokepek/futtat.sh
 */
import { chromium } from 'playwright';
import { mkdir } from 'node:fs/promises';

const ALAP    = process.env.ERP_URL  || 'https://release.infinityhu.eu';
const USER    = process.env.ERP_USER || '';
const PASS    = process.env.ERP_PASS || '';
const KIMENET = process.env.KIMENET  || '/kimenet';
const CSAK    = (process.env.CSAK || '').trim();
// Mar meglevo kepeket NE gyartsunk ujra: a nevuk a tartalom hash-ebol jon,
// es a fejezetek mar arra a nevre hivatkoznak. Ujragyartasnal a hash valtozna,
// es a hivatkozas eltorne. KIVEVE=egyenlegek,kintlevoseg
const KIVEVE  = (process.env.KIVEVE || '').split(',').map(x=>x.trim()).filter(Boolean);

// Nev-cserek a kepeken: "amit keresunk=amire csereljuk", pontosvesszovel elvalasztva.
// Pl. CSERE="Anvalor Kft=Minta Kft.;Ignath Gyorgy=Minta Felhasznalo"
// Ezek VALODI nevek lehetnek a tesztrendszerben - a sugo viszont nyilvanos.
const CSERE = (process.env.CSERE || '').split(';').map(x => x.split('=')).
  filter(a => a.length === 2).map(([a, b]) => [a.trim(), b.trim()]);

// 1440x900 a tipikus laptop-nezet. A deviceScaleFactor 2 azert kell, hogy a
// kep retina kijelzon se legyen moses - a sugoban felevig elni fog.
const NEZET = { width: 1440, height: 900 };
const ELESSEG = 2;

const varj = (ms) => new Promise(r => setTimeout(r, ms));

/**
 * A fotartalom. Minden keresest erre szukitunk, kulonben a bal oldali menu
 * szovegeire talal ra (az "Eljaras" peldaul a "Hatterelejarasok" menupontra).
 */
const fo = (page) => page.locator('main, .content, .page, [role=main]').last();

/** Varakozas felugro ablakra (javaslat, kamatbekero). */
async function varjMODALRA(page, ms = 12000) {
  await page.locator('.modal, [role=dialog], .drawer, .popup').first()
    .waitFor({ state: 'visible', timeout: ms }).catch(() => {});
  await varj(1200);
}

/**
 * Titkok kitakarasa a kep elott.
 *
 * A sugo nyilvanos. Egy API kulcs, token vagy jelszo, ami egy kepernyokepen
 * rajta marad, barki szamara olvashato lesz, aki megnyitja a fejezetet - es
 * onnan mar nem lehet visszavonni. Ezert MINDEN kep elott kitakarjuk azokat a
 * mezoket, amelyek neve, cimkeje vagy tartalma titokra utal.
 *
 * Inkabb takarjunk ki tul sokat, mint keveset: egy folosleges pontsor
 * zavaro, egy kiszivargott kulcs viszont biztonsagi incidens.
 */
async function takarj(page) {
  await page.evaluate(() => {
    const GYANUS = /(api[\s_-]?key|api[\s_-]?kulcs|kulcs|token|secret|titk|jelsz|password|webhook)/i;
    // hosszu hexa vagy base64-szeru ertek: jellemzoen kulcs
    const KULCSSZERU = /^[A-Za-z0-9_\-+=/]{24,}$/;

    const kitakar = (el, hossz) => {
      const pont = '\u2022'.repeat(Math.min(Math.max(hossz, 12), 40));
      if ('value' in el) { el.value = pont; } else { el.textContent = pont; }
    };

    document.querySelectorAll('input, textarea').forEach(el => {
      const cimke = (el.name || '') + ' ' + (el.id || '') + ' ' + (el.placeholder || '') + ' ' +
                    (el.closest('div')?.parentElement?.textContent || '').slice(0, 80);
      const ertek = (el.value || '').trim();
      if (el.type === 'password' || GYANUS.test(cimke) || KULCSSZERU.test(ertek)) {
        if (ertek) { kitakar(el, ertek.length); }
      }
    });

    // tablacellakban is elofordulhat kiirva
    document.querySelectorAll('td, .mono, code').forEach(el => {
      const t = (el.textContent || '').trim();
      if (KULCSSZERU.test(t) && t.length >= 28) { kitakar(el, t.length); }
    });
  }).catch(() => {});

  // Nev-cserek: ceg- es szemelynevek semlegesitese a szovegcsomopontokban.
  if (CSERE.length) {
    await page.evaluate((parok) => {
      const seta = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT);
      const erintett = [];
      while (seta.nextNode()) { erintett.push(seta.currentNode); }
      erintett.forEach(n => {
        let t = n.nodeValue;
        parok.forEach(([mit, mire]) => {
          if (t.includes(mit)) { t = t.split(mit).join(mire); }
        });
        if (t !== n.nodeValue) { n.nodeValue = t; }
      });

      // A beviteli mezok erteke NEM szovegcsomopont - azokat kulon kell cserelni,
      // kulonben a nev ott marad a kepen (pl. a "Nev" mezoben a valodi bolt neve).
      document.querySelectorAll('input, textarea, option').forEach(el => {
        let v = ('value' in el ? el.value : el.textContent) || '';
        let eredeti = v;
        parok.forEach(([mit, mire]) => {
          if (v.includes(mit)) { v = v.split(mit).join(mire); }
        });
        if (v !== eredeti) {
          if ('value' in el) { el.value = v; } else { el.textContent = v; }
        }
      });
    }, CSERE).catch(() => {});
  }
}

/** A lista betoltodeset nem elegszik meg a networkidle - a tablazat utolag rajzol. */
async function varjALISTARA(page) {
  await page.waitForLoadState('networkidle').catch(() => {});
  await page.waitForSelector('table', { timeout: 15000 }).catch(() => {});
  await varj(900);
}

const KEPEK = [
  // ---------- Vevoi / Szallitoi egyenlegek ----------
  {
    nev: 'egyenlegek-01-attekintes',
    mit: 'A kepernyo a negy csempevel es a listaval',
    url: '/finance/buyer-seller-balance/index',
    async lepesek(page) { await varjALISTARA(page); },
  },
  {
    nev: 'egyenlegek-02-korositas',
    mit: 'Korositas nezet az ot savval',
    url: '/finance/buyer-seller-balance/index?view=korositas&nonzero=1&valuta=mind',
    async lepesek(page) { await varjALISTARA(page); },
  },
  {
    nev: 'egyenlegek-03-sor-kinyitva',
    mit: 'Kinyitott partnersor a nyitott bizonylatokkal',
    url: '/finance/buyer-seller-balance/index',
    async lepesek(page) {
      await varjALISTARA(page);
      // az elso partnersor nyila
      const nyil = page.locator('table tbody tr td').filter({ hasText: '▶' }).first();
      if (await nyil.count()) { await nyil.click(); await varj(1200); }
    },
  },
  {
    nev: 'egyenlegek-04-gyors-nezet',
    mit: 'A partner gyors nezete oldalt',
    url: '/finance/buyer-seller-balance/index',
    async lepesek(page) {
      await varjALISTARA(page);
      const szem = page.locator('table tbody tr button, table tbody tr a').first();
      if (await szem.count()) { await szem.click(); await varj(1500); }
    },
  },
  {
    nev: 'egyenlegek-05-folyoszamla-kivonat',
    mit: 'Folyoszamla-kivonat, Teljes forgalom nezet',
    url: '/finance/buyer-seller-balance/index',
    async lepesek(page) {
      await varjALISTARA(page);
      const doksi = page.locator('table tbody tr').first().locator('button, a').nth(1);
      if (await doksi.count()) { await doksi.click(); await varj(2000); }
      const teljes = page.getByText('Teljes forgalom', { exact: false }).first();
      if (await teljes.count()) { await teljes.click(); await varj(1200); }
    },
  },

  // ---------- Kintlevoseg kezeles ----------
  {
    nev: 'kintlevoseg-01-nyitott-tartozasok',
    mit: 'A kepernyo a negy fullel',
    url: '/finance/notification-missing-payment/index',
    async lepesek(page) { await varjALISTARA(page); },
  },
  {
    nev: 'kintlevoseg-02-javaslat-ablak',
    mit: 'A javaslat ablak kikuldes elott',
    url: '/finance/notification-missing-payment/index',
        async lepesek(page) {
      await varjALISTARA(page);
      // A "Kovetkezo lepes kuldese" gomb csak akkor jelenik meg, ha a
      // kijelolesben van ESEDEKES tetel - ezert nem talalomra pipalunk,
      // hanem a rendszer sajat "Esedekesek kijelolese" gombjat hasznaljuk.
      const kijelol = page.getByRole('button', { name: /Esed\u00e9kesek kijel\u00f6l\u00e9se/i }).first();
      if (await kijelol.count()) { await kijelol.click(); await varj(1500); }

      const gomb = page.getByRole('button', { name: /K\u00f6vetkez\u0151 l\u00e9p\u00e9s/i }).first();
      if (await gomb.count()) {
        await gomb.click();
        await varjMODALRA(page);
        console.log('      (kattintas utan: ' + page.url() + ')');
      } else {
        console.log('      (nincs "Kovetkezo lepes" gomb - a kijelolt sorok kozt nincs esedekes)');
      }
      await varj(1500);
    },
  },
  {
    nev: 'kintlevoseg-03-kamatbekero',
    mit: 'Kamatbekero keszitese a lejart szamlakbol',
    url: '/finance/notification-missing-payment/index',
    async lepesek(page) {
      await varjALISTARA(page);
      const pipak = page.locator('table tbody input[type=checkbox]');
      const n = Math.min(2, await pipak.count());
      for (let i = 0; i < n; i++) { await pipak.nth(i).check().catch(() => {}); }
      await varj(400);
      // csak megnyitjuk a keszito ablakot - nem mentunk benne semmit
      const gomb = page.getByRole('button', { name: /Kamatbekérő/i }).first();
      if (await gomb.count()) { await gomb.click(); await varjMODALRA(page); }
    },
  },
  {
    nev: 'kintlevoseg-04-eljaras',
    mit: 'Az Eljaras ful a lepesekkel',
    url: '/finance/notification-missing-payment/index',
    async lepesek(page) {
      await varjALISTARA(page);
      const ful = fo(page).getByText('Eljárás', { exact: true }).first();
      if (await ful.count()) { await ful.click(); await varj(2500); }
      await page.waitForLoadState('networkidle').catch(() => {});
      await varj(800);
    },
  },
  // ---------- E-mail sablonok (16.2.1) ----------
  { nev:'email-01-lista', mit:'A sablonok listaja kartyas nezetben',
    url:'/core/email-template/index',
    async lepesek(p){ await varjALISTARA(p); } },
  { nev:'email-02-szerkeszto', mit:'A sablon szerkesztoje',
    url:'/core/email-template/index',
    async lepesek(p){ await varjALISTARA(p);
      const e=fo(p).getByRole('button',{name:/Szerkeszt/i}).first();
      if(await e.count()){ await e.click(); await varj(2500); } } },

  // ---------- Jogosultsagkezelo (16.2.2) ----------
  { nev:'jogosultsag-01-oldal', mit:'A jogosultsagok es kategoriak oldal',
    url:'/core/action/index',
    async lepesek(p){ await varjALISTARA(p);
      // a tabla ures, amig nincs kivalasztva kategoria - valasszuk az elsot
      const k = fo(p).locator('aside li, .kategoria li, ul li').filter({hasText:/\S/}).first();
      if (await k.count()) { await k.click().catch(()=>{}); await varj(2000); }
      await varj(800); } },

  // ---------- Szerepkorok (16.2.3) ----------
  { nev:'szerepkor-01-lista', mit:'A szerepkorok listaja',
    url:'/core/role/index',
    async lepesek(p){ await varjALISTARA(p); } },

  // ---------- Sablonkezelo (16.5) ----------
  { nev:'sablonkezelo-01-lista', mit:'A sablonok listaja',
    url:'/documents/accounting-document-template/index',
    async lepesek(p){ await varjALISTARA(p); } },

  // ---------- UNAS (18.1) ----------
  { nev:'unas-01-dashboard', mit:'Az UNAS vezerlopult a negy csempevel',
    url:'/stock/unas-api-key/dashboard',
    async lepesek(p){ await varjALISTARA(p); } },

  // A szekciok osszecsukva indulnak - mindegyiket kinyitjuk a sajat kepehez.
  { nev:'unas-02-api-kapcsolat', mit:'Az API kapcsolat beallitasa',
    url:'/stock/unas-api-key/dashboard',
    async lepesek(p){ await varjALISTARA(p);
      const x=fo(p).getByText('UNAS api kulcs',{exact:false}).first();
      if(await x.count()){ await x.click().catch(()=>{}); await varj(1800); } } },

  { nev:'unas-03-termek-szures', mit:'Automatikus termek szinkronizacio - szuresek',
    url:'/stock/unas-api-key/dashboard',
    async lepesek(p){ await varjALISTARA(p);
      const x=fo(p).getByText('Automatikus term',{exact:false}).first();
      if(await x.count()){ await x.click().catch(()=>{}); await varj(1800); } } },

  { nev:'unas-04-ar-nyelv', mit:'Termek nyelvek es ar lekepezes',
    url:'/stock/unas-api-key/dashboard',
    async lepesek(p){ await varjALISTARA(p);
      const x=fo(p).getByText('Term\u00e9k nyelvek',{exact:false}).first();
      if(await x.count()){ await x.click().catch(()=>{}); await varj(1800); } } },

  { nev:'unas-05-aktivitas-naplo', mit:'Az aktivitas naplo',
    url:'/stock/unas-api-key/dashboard',
    async lepesek(p){ await varjALISTARA(p);
      const x=fo(p).getByText('Aktivit\u00e1s napl\u00f3',{exact:false}).first();
      if(await x.count()){ await x.click().catch(()=>{}); await varj(2200); } } },

  // ---------- Arucikkek (6.2) ----------
  { nev:'arucikk-01-lista', mit:'Az arucikk lista',
    url:'/stock/product/index',
    async lepesek(p){ await varjALISTARA(p); } },

  // ---------- Partnerek (4.5) ----------
  { nev:'partner-01-lista', mit:'A partnerek listaja',
    url:'/partner/partner/index',
    async lepesek(p){ await varjALISTARA(p); } },

  // ---------- Dokumentum lista (7.2) ----------
  { nev:'iktatas-01-lista', mit:'A dokumentum lista a csempekkel',
    url:'/documents/document/index',
    async lepesek(p){ await varjALISTARA(p); } },

  // ---------- A fooldal (3.12) ----------
  { nev:'fooldal-01-attekintes', mit:'A fooldal a sajat teendokkel',
    url:'/',
    async lepesek(p){ await varjALISTARA(p); } },

];

async function belep(page) {
  await page.goto(ALAP + '/site/login', { waitUntil: 'domcontentloaded' });
  if (!/login/i.test(page.url())) { return; }   // mar bent vagyunk

  // A mezok neve rendszerenkent elterhet, ezert tobb mintat probalunk vegig.
  const userMezo = page.locator('input[name="LoginForm[username]"], input[name*="username" i], input[name*="login" i], input[type=text]').first();
  const passMezo = page.locator('input[name="LoginForm[password]"], input[type=password]').first();
  await userMezo.fill(USER);
  await passMezo.fill(PASS);

  const gomb = page.locator('button[name="login-button"], button[type=submit], input[type=submit]').first();
  await Promise.all([
    page.waitForLoadState('networkidle').catch(() => {}),
    gomb.click(),
  ]);
  await varj(2000);

  if (/login/i.test(page.url())) {
    // Mentunk egy kepet, kulonben talalgatas, mi allitotta meg a belepest
    await page.screenshot({ path: KIMENET + '/_belepes-hiba.png' }).catch(() => {});
    const uzenet = await page.locator('.alert, .error, .help-block, .invalid-feedback').first()
      .textContent().catch(() => '');
    throw new Error('A belepes nem sikerult. A lap uzenete: ' + (uzenet || '(nincs)') +
                    '  — kep: _belepes-hiba.png');
  }
}

const main = async () => {
  if (!USER || !PASS) { throw new Error('ERP_USER es ERP_PASS kornyezeti valtozo kell.'); }
  await mkdir(KIMENET, { recursive: true });

  const bongeszo = await chromium.launch();
  const ctx = await bongeszo.newContext({
    viewport: NEZET,
    deviceScaleFactor: ELESSEG,
    locale: 'hu-HU',
  });
  const page = await ctx.newPage();

  await belep(page);
  console.log('Belepve. Kepek keszitese ' + NEZET.width + 'x' + NEZET.height + ', ' + ELESSEG + 'x elesseggel.\n');

  let lista = CSAK ? KEPEK.filter(k => k.nev.includes(CSAK)) : KEPEK;
  if (KIVEVE.length) { lista = lista.filter(k => !KIVEVE.some(x => k.nev.includes(x))); }
  if (CSAK) { console.log('Csak ezek: ' + lista.map(k => k.nev).join(', ') + '\n'); }

  let ok = 0, hiba = 0;
  for (const k of lista) {
    const cel = KIMENET + '/' + k.nev + '.png';
    try {
      await page.goto(ALAP + k.url, { waitUntil: 'domcontentloaded' });
      await k.lepesek(page);
      await takarj(page);          // titkok kitakarasa a kep elott
      await varj(300);
      await page.screenshot({ path: cel });
      console.log('  ok    ' + k.nev + '.png   (' + k.mit + ')');
      ok++;
    } catch (e) {
      console.log('  HIBA  ' + k.nev + ' -> ' + e.message);
      hiba++;
    }
  }

  await bongeszo.close();
  console.log('\n' + ok + ' kep elkeszult, ' + hiba + ' hibara futott.');
  if (hiba) { process.exitCode = 1; }
};

main().catch(e => { console.error(e); process.exit(1); });
