# Új fejezetek – előkészítés alatt

Itt a súgóba még **be nem tett** fejezetek szövege áll, a súgó saját
HTML-formátumában (`call tip` / `call warn` dobozok, `table.erp-table`,
`img.shot`).

Ez **átmeneti tároló**. Amint egy fejezet bekerül a súgóba, innen törölhető –
onnantól az adatbázis az egyetlen forrás.

## Miért HTML és nem .docx

A fejezetek eredetileg Word-útmutatóként érkeztek, és a tervezett út a súgó
**Word import** funkciója volt. Helyette közvetlenül HTML-t készítünk, mert:

- a képhivatkozások már most a végleges néven szerepelnek, így az import után
  nem kell fejezetenként kézzel beszúrkálni őket;
- a Word-útmutatók több ponton **mást írnak, mint amit a rendszer mutat** (lásd
  lentebb), ezeket a konverzió közben javítani kellett;
- a beillesztés ugyanazzal a módszerrel megy, ami a főfejezet-leírásoknál már
  hibátlanul működött.

Az átalakítót lásd: a munkamappában `docx2html.py` (a félkövér kiemeléseket
megtartja, a Heading3/4/5-ből h2/h3/h4 lesz horgonnyal).

## Állapot

| fájl | kép | megjegyzés |
|---|---|---|
| `3.12-fooldal.html` | 3 | |
| `4.5-partnerek.html` | 2 | |
| `5.2-kintlevoseg-kezeles.html` | 4 | |
| `5.2.1-vevoi-szallitoi-egyenlegek.html` | 5 | |
| `6.2-arucikkek.html` | 2 | |
| `7.2-dokumentum-listaja.html` | 1 | |
| `7.4-uj-dokumentum-iktatasa.html` | 1 | |
| `16.2.1-email-sablonok.html` | 4 | |
| `16.2.3-szerepkorok.html` | 3 | |
| `16.5-sablonkezelo.html` | 1 | |

Minden képhivatkozáshoz **létezik a fájl** a `tools/kepernyokepek/kimenet/`
mappában, a végleges néven. A HTML épsége és a házi konvenciók gépileg
ellenőrizve.

## Amit a Word-útmutatókban javítani kellett

Ezek a rendszer és a dokumentáció közti eltérések – a fejlesztőknek is
érdemes tudniuk róluk:

| hol | az útmutató azt írja | a valóság |
|---|---|---|
| 7.2, 7.4 | „Adminisztráció → Dokumentumlista" | **Iktatás → Dokumentum lista** (nincs „Adminisztráció" menü) |
| 7.2, 7.4 | „+ Új iktatás gomb, jobb felső sarokban" | **+ Létrehozás**, a cím mellett balra |
| 16.2.1 | a szerkesztő négy szekciós | **három** (Alapadatok, Címzettek, Csatolmány) |
| 16.2.1 | külön „Mikor menjen ki" beállítás | nincs: a kiváltót a **Típus** mező adja, nem szerkeszthető |
| 16.2.1 | „Időzítve" kiváltó | sehol nem jelenik meg |
| 16.2.1 | „+" gomb új nyelvhez | nincs ilyen gomb |

A Word-fájlok címlapi maradéka („Infinity ERP", „Felhasználói útmutató",
cégnév, verziószám) mindenhonnan kiszedve.

## Hátralévő admin-munka

- A 10 fejezet beillesztése és közzététele.
- A **18.1 UNAS** és a **16.2.2 Jogosultságkezelő** fejezet már bent van az
  adminban, de nincs közzétéve, és hiányoznak belőlük a képek
  (`unas-01`…`unas-05`, `jogosultsag-01`).
- A **18 Piacterek** főfejezet angol/német neve még „Névtelen főfejezet".
