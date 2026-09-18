# Mi újság — 2026-09-18

A 2026-09-15-i telepítőcsomag óta. A szerkesztőknek szól; a telepítés
menetét az `OLVASD_EL.md` írja le.

---

## Szerkesztő

**Visszavonás és Újra gomb** (Ctrl+Z, Ctrl+Shift+Z). Saját előzménytárral,
mert a szerkesztő sok mindent közvetlenül csinál (színezés, képbeszúrás,
táblázat), amit a böngésző visszavonása nem lát.

**60 betűszín** a Wordből ismerős 10 oszlopos rácsban — egy oszlopban egy
szín családja, világosabb és sötétebb árnyalatokkal. A kiemelő tollak
4-ről 16-ra.

**Az újraszínezés működik.** Eddig minden színezés egy új réteget tett a
szövegre, és a régi szín maradt látható.

**Automatikus H1–H5 számozás**, táblázatkezelés (fejléc- és cellaszínek,
keret), szövegigazítás, „Tipp / Figyelem / Fontos" dobozok.

---

## Fejezetlista

**Hierarchikus, összecsukható**, öt szinttel. A főfejezet a legnagyobb, az
alfejezetek egyre kisebbek.

**A főfejezet címe a leírását nyitja meg.** Eddig ott állt kétszer ugyanaz
a sor („5 Pénzügy" főfejezet + „5 Pénzügy" fejezet) — a második valójában a
főfejezet leírása. Aminek még nincs leírása, az jelzést kap.

**Szem ikon minden soron:** a fejezet vagy főfejezet elrejthető a nyilvános
oldalról. Kattintáskor megkérdezi, minden nyelven vagy csak az adotton.

**Törlés gomb minden soron**, rákérdezéssel. Egységes gombsorrend
mindenhol: **+ , ✎ , szem , ✕**.

**Húzással átvihető másik főfejezet alá**, és a számozás követi —
minden nyelven egyszerre, az alfejezetek szerkezetét megtartva.

**Új főfejezet egy kattintással**: létrejön minden nyelven, kap egy
leírás-fejezetet, és rögtön a szerkesztő nyílik meg rajta.

---

## Fordítás

**Két nyelv egymás mellett, teljes szerkesztővel** — hogy nyelvenkénti
képernyőképet is be lehessen szúrni. Te választod ki, melyik kettő.

**Claude gépi fordítás** a DeepL / LibreTranslate / Google mellé.

**„A fordítások maradjanak naprakészek" pipa** a közzétételnél: egy elütés
javítása eddig az összes fordítást elavulttá tette.

**Dinamikusan bővíthető nyelvek** — fejlesztő nélkül vehető fel új nyelv.

---

## Kezelőfelület

**Teljesen háromnyelvű** (magyar / angol / német): 683 felirat, üzenet és
súgószöveg. A saját nyelvedet a fejlécben állítod.

**Saját lap a felület szövegeinek**: területek szerint csoportosítva,
kereséssel, nyelvenkénti szűrővel és haladásjelzővel. Aki új szöveget lát
magyarul, egy gombbal lefordíttathatja.

**Állítható betűméret** (A− / A+ a fejlécben) — az admin ~13px-es alapra
épült, ami nagy felbontású képernyőn apróra sikerült.

**A fogaskerék menüt nyit**: Gépi fordítás, A kezelőfelület szövegei,
Nyelvek, Kiadások — mindegyik saját lap.

**Teljes szélességű, reszponzív** minden lap; telefonon is használható.

---

## Nyilvános súgó

**„Frissítések" → „Újdonságok"**, és a gomb a keresőmező mögé került.
Az URL nem változott, a régi hivatkozások épek.

**Tippek és figyelmeztetések a jobb oldali sávban**, az adott oldalról
összegyűjtve.

**Nyelvváltáskor az URL is a nyelvnek megfelelő.** A régi címekről
átirányítás visz az újakra.

**A súgó verziószáma a cím mellett**, `v.09.2026` formában.

**Mobilra optimalizált** — 375px széles képernyőn sincs vízszintes csúszás.

---

## Egyéb

- A Word/PDF exportban a beszúrt logó keret nélkül jelenik meg
- Az áttekintő csempéi kattinthatók: mindegyik a leszűrt listára visz
- A napló lapozható
- A „Képernyők" menüpont kivezetve (félbemaradt funkció volt)
- A „Modulok" fül beleolvadt a „Fejezetek"-be
