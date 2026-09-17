<?php
/**
 * admin_editor.php - a TELJES szerkeszto (eszkoztar + terulet + HTML forras).
 *
 * Egy lapon tobb peldany is lehet belole: minden azonosito OSZTALY, nem id,
 * a JS pedig a .ed gyokeren belul keresi az elemeit. Ezert hasznalhatja a
 * Fejezetek ful es a Forditas ful is - ott nyelvenkent egyet.
 *
 * @param string $chapter  a fejezet szama (az automatikus szamozas elotagja)
 * @param string $body     a szerkesztendo HTML
 */
declare(strict_types=1);

/**
 * A betuszin-paletta, a Wordbol ismeros elrendezesben.
 *
 * Felso sor: az alapszinek. Alatta negy sor ugyanazokbol vilagosabb es
 * sotetebb arnyalat (a vilagosabbak feherrel, a sotetebbek feketevel
 * keverve) - igy egy oszlopban egyutt all egy szin csaladja, es nem kell
 * hatszamokkal bajlodni. Legalul a Word "standard szinei".
 *
 * A kimenetet a help_clean_style() ugyis ellenorzi, ez csak a kinalat.
 *
 * @return array<int, array<int, string>> sorok, soronkent 10 hexkod
 */
function editor_palette(): array
{
    // Az oszlopok alapszinei (Word "theme colors")
    $base = ['#ffffff', '#000000', '#e7e6e6', '#44546a', '#4472c4',
             '#ed7d31', '#a5a5a5', '#ffc000', '#5b9bd5', '#70ad47'];

    /** Kever ket szint: $t = 0 -> $hex, 1 -> $with */
    $mix = static function (string $hex, string $with, float $t): string {
        $a = sscanf($hex,  '#%02x%02x%02x');
        $b = sscanf($with, '#%02x%02x%02x');
        return sprintf('#%02x%02x%02x',
            (int)round($a[0] + ($b[0] - $a[0]) * $t),
            (int)round($a[1] + ($b[1] - $a[1]) * $t),
            (int)round($a[2] + ($b[2] - $a[2]) * $t));
    };

    $rows = [$base];
    // A feher oszlopot sotetiteni kell, a feketet vilagositani - kulonben
    // ket oszlop vegig egyszinu maradna.
    foreach ([0.8, 0.6, 0.4, 0.15] as $i => $t) {
        $row = [];
        foreach ($base as $c) {
            $light = $c === '#ffffff';
            $dark  = $c === '#000000';
            $row[] = $light || (!$dark && $i >= 2)
                ? $mix($c, '#000000', $light ? [0.05, 0.15, 0.25, 0.35][$i] : (1 - $t) * 0.9)
                : $mix($c, '#ffffff', $dark ? [0.85, 0.65, 0.5, 0.35][$i] : $t);
        }
        $rows[] = $row;
    }

    // Word "standard colors" - a legtobbszor ezekre van szukseg
    $rows[] = ['#c00000', '#ff0000', '#ffc000', '#ffff00', '#92d050',
               '#00b050', '#00b0f0', '#0070c0', '#002060', '#7030a0'];
    return $rows;
}

/** A szovegkiemelo (hatterszin) szinei - a Word kiemelo tollai. */
function editor_marks(): array
{
    return [
        '#ffff00' => t('Sárga'),      '#00ff00' => t('Élénkzöld'),  '#00ffff' => t('Türkiz'),
        '#ff00ff' => t('Rózsaszín'),  '#0000ff' => t('Kék'),        '#ff0000' => 'Piros',
        '#000080' => t('Sötétkék'),   '#008080' => t('Kékeszöld'),  '#008000' => t('Zöld'),
        '#800080' => 'Lila',       '#800000' => t('Sötétvörös'), '#808000' => t('Sötétsárga'),
        '#c0c0c0' => t('Szürke'),     '#fff3a3' => t('Halvány sárga'), '#dceafd' => t('Halvány kék'),
    ];
}

function editor_block(string $chapter, string $body): void
{
    // FIGYELEM: ezek fuggvenyhivasok, nem a hivo valtozoi. Amikor a
    // szerkeszto kikerult sajat fuggvenybe, a $edColors/$edMarks a hivo
    // hatokoreben maradt - a ket szinsor ures lett, es csak a "kiemeles
    // torlese" kocka latszott.
    $edPalette = editor_palette();
    $edMarks   = editor_marks();
    ?>
        <div class="ed" data-chapter="<?= h($chapter) ?>">
          <div class="ed-toolbar">
            <div class="ed-grp">
              <button type="button" class="ed-undo" disabled
                      title="<?= h(t('ed.undo')) ?>">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                     stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 14 4 9l5-5"/><path d="M4 9h11a5 5 0 0 1 0 10h-4"/></svg>
              </button>
              <button type="button" class="ed-redo" disabled
                      title="<?= h(t('ed.redo')) ?>">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                     stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="m15 14 5-5-5-5"/><path d="M20 9H9a5 5 0 0 0 0 10h4"/></svg>
              </button>
            </div>

            <div class="ed-grp">
              <select class="ed-sel ed-level" title="<?= h(t('Bekezdés szintje')) ?>">
                <option value="p"><?= h(t('Bekezdés')) ?></option>
                <option value="h2"><?= h(t('1. szint — címsor')) ?></option>
                <option value="h3">2. szint</option>
                <option value="h4">3. szint</option>
                <option value="h5">4. szint</option>
                <option value="h6">5. szint</option>
              </select>
            </div>

            <div class="ed-grp">
              <button type="button" data-cmd="bold" title="<?= h(t('Félkövér (Ctrl+B)')) ?>"><b>F</b></button>
              <button type="button" data-cmd="italic" title="<?= h(t('Dőlt (Ctrl+I)')) ?>"><i>D</i></button>
              <button type="button" data-cmd="underline" title="<?= h(t('Aláhúzott (Ctrl+U)')) ?>"><u>A</u></button>
              <button type="button" data-cmd="strikeThrough" title="<?= h(t('Áthúzott')) ?>"><s>Á</s></button>
            </div>

            <div class="ed-grp">
              <div class="ed-pop">
                <button type="button" class="ed-pop__b" data-pop="color" title="<?= h(t('Betűszín')) ?>">
 <span class="ed-ink">A</span><span class="ed-bar ed-color-bar"></span><span class="ed-car">▾</span>
                </button>
                <div class="ed-pop__m" data-pop-menu="color">
                  <div class="ed-pop__t"><?= h(t('Betűszín')) ?></div>
                  <div class="ed-sw ed-sw--grid">
                    <?php foreach ($edPalette as $row): foreach ($row as $hex): ?>
                      <button type="button" class="sw" data-color="<?= h($hex) ?>"
                              style="background:<?= h($hex) ?>" title="<?= h($hex) ?>"></button>
                    <?php endforeach; endforeach; ?>
                  </div>
                  <div class="ed-pop__t"><?= h(t('Kiemelés')) ?></div>
                  <div class="ed-sw ed-sw--grid">
                    <?php foreach ($edMarks as $hex => $name): ?>
                      <button type="button" class="sw" data-mark="<?= h($hex) ?>"
                              style="background:<?= h($hex) ?>" title="<?= h($name) ?>"></button>
                    <?php endforeach; ?>
                    <button type="button" class="sw sw--none" data-mark="none" title="<?= h(t('Kiemelés törlése')) ?>"></button>
                  </div>
                  <label class="ed-pop__c"><?= h(t('Egyéni szín')) ?><input type="color" class="ed-color-custom" value="#0a6ed1"></label>
                </div>
              </div>
            </div>

            <div class="ed-grp">
              <button type="button" data-cmd="insertUnorderedList" title="<?= h(t('Felsorolás')) ?>">•&nbsp;lista</button>
              <button type="button" data-cmd="insertOrderedList" title="<?= h(t('Számozott lista')) ?>">1.&nbsp;lista</button>
              <button type="button" data-cmd="outdent" title="<?= h(t('Behúzás csökkentése')) ?>">⇤</button>
              <button type="button" data-cmd="indent" title="<?= h(t('Behúzás növelése')) ?>">⇥</button>
            </div>

            <div class="ed-grp">
              <button type="button" data-align="left"    title="<?= h(t('Balra igazítás')) ?>">≡<sub>◀</sub></button>
              <button type="button" data-align="center"  title="<?= h(t('Középre igazítás')) ?>">≡<sub>◆</sub></button>
              <button type="button" data-align="right"   title="<?= h(t('Jobbra igazítás')) ?>">≡<sub>▶</sub></button>
              <button type="button" data-align="justify" title="<?= h(t('Sorkizárt')) ?>">≡<sub>■</sub></button>
            </div>

            <div class="ed-grp">
              <button type="button" data-act="link" title="<?= h(t('Hivatkozás (Ctrl+K)')) ?>">🔗</button>
              <button type="button" data-act="upload-image" title="<?= h(t('Kép feltöltése és beszúrása')) ?>">🖼&nbsp;<?= h(t('Kép')) ?></button>
              <button type="button" data-act="upload-video" title="<?= h(t('Videó feltöltése és beszúrása')) ?>">🎬&nbsp;<?= h(t('Videó')) ?></button>
              <div class="ed-pop">
 <button type="button" class="ed-pop__b ed-pop__b--wide" data-pop="table" title="<?= h(t('Táblázat beszúrása')) ?>">▦&nbsp;Tábla<span class="ed-car">▾</span></button>
                <div class="ed-pop__m ed-pop__m--tbl" data-pop-menu="table">
                  <div class="ed-pop__t"><?= h(t('Új táblázat')) ?></div>
                  <div class="tblgrid" aria-label="<?= h(t('Méret választása')) ?>"></div>
                  <div class="tblgrid__lbl">3 × 3</div>
 <label class="ed-pop__c tbl-new-head"><?= h(t('Fejlécsor')) ?><input type="checkbox" checked></label>
 <button type="button" class="btn btn--p btn--sm tbl-insert" style="width:100%;margin-top:8px"><?= h(t('Beszúrás')) ?></button>
                </div>
              </div>
            </div>

            <div class="ed-grp">
              <button type="button" data-act="callout-tip" class="ed-b--tip" title="Tipp doboz">Tipp</button>
              <button type="button" data-act="callout-warn" class="ed-b--warn" title="<?= h(t('Figyelmeztetés doboz')) ?>">Figyelem</button>
              <button type="button" data-act="callout-crit" class="ed-b--crit" title="<?= h(t('Piros, erős kiemelés — amit semmiképp nem szabad elnézni')) ?>">Fontos</button>
            </div>

            <div class="ed-grp">
              <button type="button" data-cmd="removeFormat" title="<?= h(t('Formázás törlése')) ?>">Tiszta</button>
              <button type="button" class="ed-renumber" title="<?= h(t('Címsorok újraszámozása most')) ?>"><?= h(t('1.2.3 Számozás')) ?></button>
            </div>

            <span class="sp"></span>
            <div class="ed-grp ed-grp--end">
              <label class="ed-chk" title="<?= h(t('A címsorok számozása gépeléskor magától frissül')) ?>">
                <input type="checkbox" class="ed-autonum" checked><?= h(t('Automatikus számozás')) ?></label>
              <button type="button" class="ed-source" title="<?= h(t('HTML forrás mutatása')) ?>">&lt;/&gt;&nbsp;HTML</button>
            </div>
          </div>
          <!-- Táblázat-eszközök: csak akkor látszik, ha a kurzor táblázatban áll.
               A Word „Táblázateszközök" fülének megfelelője. -->
          <div class="ed-tbl" hidden>
            <span class="ed-tbl__t"><?= h(t('Táblázat')) ?></span>

            <div class="ed-grp">
              <button type="button" data-tbl="row-above"  title="<?= h(t('Sor beszúrása fölé')) ?>">↑ sor</button>
              <button type="button" data-tbl="row-below"  title="<?= h(t('Sor beszúrása alá')) ?>">↓ sor</button>
              <button type="button" data-tbl="row-del"    title="<?= h(t('Sor törlése')) ?>">✕ sor</button>
            </div>
            <div class="ed-grp">
              <button type="button" data-tbl="col-left"   title="<?= h(t('Oszlop beszúrása balra')) ?>">← oszlop</button>
              <button type="button" data-tbl="col-right"  title="<?= h(t('Oszlop beszúrása jobbra')) ?>">→ oszlop</button>
              <button type="button" data-tbl="col-del"    title="<?= h(t('Oszlop törlése')) ?>">✕ oszlop</button>
            </div>

            <div class="ed-grp">
              <select class="ed-sel tbl-border" title="<?= h(t('Keret és cellavonalak')) ?>">
                <option value="grid"><?= h(t('Teljes rács')) ?></option>
                <option value="rows"><?= h(t('Csak vízszintes vonalak')) ?></option>
                <option value="frame"><?= h(t('Csak külső keret')) ?></option>
                <option value="plain">Nincs vonal</option>
              </select>
 <label class="ed-chk tbl-zebra" title="<?= h(t('Váltakozó sorháttér')) ?>"><input type="checkbox" > <?= h(t('Sávozott')) ?></label>
            </div>

            <div class="ed-grp">
              <div class="ed-pop">
                <button type="button" class="ed-pop__b" data-pop="tblhead" title="<?= h(t('Fejléc színei')) ?>">
 <span class="ed-ink"><?= h(t('Fejléc')) ?></span><span class="ed-car">▾</span>
                </button>
                <div class="ed-pop__m" data-pop-menu="tblhead">
                  <div class="ed-pop__t"><?= h(t('Fejléc háttere')) ?></div>
                  <div class="ed-sw">
                    <?php foreach (['#eceff3' => t('Szürke'), '#0a6ed1' => t('Kék'), '#0854a0' => t('Sötétkék'),
                                    '#107e3e' => t('Zöld'), '#b8681a' => 'Narancs', '#bb0000' => 'Piros',
                                    '#1c2a3a' => t('Sötét')] as $hex => $name): ?>
                      <button type="button" class="sw" data-hbg="<?= h($hex) ?>"
                              style="background:<?= h($hex) ?>" title="<?= h($name) ?>"></button>
                    <?php endforeach; ?>
                    <button type="button" class="sw sw--none" data-hbg="none" title="<?= h(t('Nincs kitöltés')) ?>"></button>
                  </div>
                  <div class="ed-pop__t"><?= h(t('Fejléc betűszíne')) ?></div>
                  <div class="ed-sw">
                    <?php foreach (['#1f2a36' => t('Sötét'), '#ffffff' => t('Fehér'), '#0854a0' => t('Kék')] as $hex => $name): ?>
                      <button type="button" class="sw" data-hfg="<?= h($hex) ?>"
                              style="background:<?= h($hex) ?>" title="<?= h($name) ?>"></button>
                    <?php endforeach; ?>
                  </div>
 <label class="ed-pop__c tbl-hbg-custom"><?= h(t('Egyéni háttér')) ?><input type="color" value="#0a6ed1"></label>
 <label class="ed-pop__c tbl-hfg-custom"><?= h(t('Egyéni betűszín')) ?><input type="color" value="#ffffff"></label>
                </div>
              </div>

              <div class="ed-pop">
                <button type="button" class="ed-pop__b" data-pop="tblcell" title="<?= h(t('A kijelölt cellák színei')) ?>">
 <span class="ed-ink">Cella</span><span class="ed-car">▾</span>
                </button>
                <div class="ed-pop__m" data-pop-menu="tblcell">
                  <div class="ed-pop__t"><?= h(t('Cella háttere')) ?></div>
                  <div class="ed-sw">
                    <?php foreach (['#eceff3' => t('Szürke'), '#e8f1fb' => t('Kék'), '#e6f3ec' => t('Zöld'),
                                    '#fdf3e7' => 'Narancs', '#fbecec' => 'Piros', '#fff3a3' => t('Sárga')] as $hex => $name): ?>
                      <button type="button" class="sw" data-cbg="<?= h($hex) ?>"
                              style="background:<?= h($hex) ?>" title="<?= h($name) ?>"></button>
                    <?php endforeach; ?>
                    <button type="button" class="sw sw--none" data-cbg="none" title="<?= h(t('Kitöltés törlése')) ?>"></button>
                  </div>
                  <div class="ed-pop__t"><?= h(t('Cella betűszíne')) ?></div>
                  <div class="ed-sw">
                    <?php foreach (['#1f2a36' => 'Alap', '#0854a0' => t('Kék'), '#107e3e' => t('Zöld'),
                                    '#bb0000' => 'Piros', '#ffffff' => t('Fehér')] as $hex => $name): ?>
                      <button type="button" class="sw" data-cfg="<?= h($hex) ?>"
                              style="background:<?= h($hex) ?>" title="<?= h($name) ?>"></button>
                    <?php endforeach; ?>
                  </div>
 <label class="ed-pop__c tbl-cbg-custom"><?= h(t('Egyéni háttér')) ?><input type="color" value="#e8f1fb"></label>
 <label class="ed-pop__c tbl-cfg-custom"><?= h(t('Egyéni betűszín')) ?><input type="color" value="#1f2a36"></label>
                  <div class="hint" style="margin-top:6px"><?= h(t('Több cellát is színezhetsz: húzd át rajtuk a kijelölést.')) ?></div>
                </div>
              </div>
            </div>

            <span class="sp"></span>
            <button type="button" data-tbl="delete" class="ed-b--warn" title="<?= h(t('A teljes táblázat törlése')) ?>"><?= h(t('Táblázat törlése')) ?></button>
          </div>

          <div class="ed-area body" contenteditable="true" spellcheck="true"><?= fix_img_url($body) ?></div>
          <textarea class="ta ed-src" name="body"></textarea>
        </div>
    <?php
}
