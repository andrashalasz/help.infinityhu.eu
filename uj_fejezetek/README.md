# Új fejezetek – előkészítés alatt

Itt a súgóba még **be nem tett** fejezetek szövege áll, a súgó saját
HTML-formátumában (`call tip` / `call warn` dobozok, `table.erp-table`).

Ez **átmeneti tároló**. Amint egy fejezet bekerül a súgóba, innen törölhető –
onnantól az adatbázis az egyetlen forrás.

## Állapot

| fájl | forrás | szöveg | kép |
|---|---|---|---|
| `5.2-kintlevoseg-kezeles.html` | `Infinity_Kintlevoseg_kezeles_hasznalati_utmutato.docx` | kész | **4 hiányzik** |
| `5.2.1-vevoi-szallitoi-egyenlegek.html` | `Infinity_Egyenlegek_felhasznaloi_utmutato.docx` | kész | **5 hiányzik** |

## A hiányzó képek

A fájlokban a képhivatkozások már a végleges néven szerepelnek. Amint a
képernyőkép elkészül ezen a néven és bekerül a `media/` mappába, a fejezet
magától teljes lesz – a szöveghez nem kell hozzányúlni.

| fájlnév | mit kell mutatnia |
|---|---|
| `egyenlegek-01-attekintes.png` | a Vevői / Szállítói egyenlegek képernyő a négy csempével |
| `egyenlegek-02-korositas.png` | korosítás nézet az öt sávval |
| `egyenlegek-03-sor-kinyitva.png` | kinyitott partnersor a nyitott bizonylatokkal |
| `egyenlegek-04-gyors-nezet.png` | a partner gyors nézete oldalt |
| `egyenlegek-05-folyoszamla-kivonat.png` | folyószámla-kivonat, Teljes forgalom nézet |
| `kintlevoseg-01-nyitott-tartozasok.png` | a Kintlévőség kezelés képernyő a négy füllel |
| `kintlevoseg-02-javaslat-ablak.png` | a javaslat ablak kiküldés előtt |
| `kintlevoseg-03-kamatbekero.png` | kamatbekérő szerkesztő |
| `kintlevoseg-04-eljaras.png` | az Eljárás fül a lépésekkel |

## Amit a képekhez előbb meg kell oldani

- **Kompenzálás:** a „Kétoldalú (kompenzálható)" pill értéke 0 – nincs olyan
  partner, akinek vevői és szállítói oldalon is van nyitott tétele.
- **Kamatbekérő:** a fül 0 elemű, nincs mit fotózni.
- A böngészőpanel 640–720 px-es képet ad, ami dokumentációhoz kevés –
  külön képernyőkép-szkript kell, 1440×900-on, 2× élességgel, PNG-be.

## Szerkezet

A súgó szerkezete a **menüt** követi:

```
Pénzügy → Kintlévőség kezelés            = 5.2
          └ Vevői / Szállítói egyenlegek = 5.2.1
```

A régi kézikönyv ugyanitt még 5 alpontot ismert (Egyenlegközlő, Fizetési
felszólítás, Késedelmi kamat bekérő, Értesítés gyakorisága); ezek azóta
ebbe a két képernyőbe olvadtak.
