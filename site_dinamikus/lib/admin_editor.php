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

function editor_block(string $chapter, string $body): void
{
    ?>
        <div class="ed" data-chapter="<?= h($chapter) ?>">
          <div class="ed-toolbar">
            <div class="ed-grp">
              <select class="ed-sel ed-level" title="Bekezdés szintje">
                <option value="p">Bekezdés</option>
                <option value="h2">1. szint — címsor</option>
                <option value="h3">2. szint</option>
                <option value="h4">3. szint</option>
                <option value="h5">4. szint</option>
                <option value="h6">5. szint</option>
              </select>
            </div>

            <div class="ed-grp">
              <button type="button" data-cmd="bold" title="Félkövér (Ctrl+B)"><b>F</b></button>
              <button type="button" data-cmd="italic" title="Dőlt (Ctrl+I)"><i>D</i></button>
              <button type="button" data-cmd="underline" title="Aláhúzott (Ctrl+U)"><u>A</u></button>
              <button type="button" data-cmd="strikeThrough" title="Áthúzott"><s>Á</s></button>
            </div>

            <div class="ed-grp">
              <div class="ed-pop">
                <button type="button" class="ed-pop__b" data-pop="color" title="Betűszín">
 <span class="ed-ink ed-bar ed-color-bar ed-car">A</span><span ></span><span >▾</span>
                </button>
                <div class="ed-pop__m" data-pop-menu="color">
                  <div class="ed-pop__t">Betűszín</div>
                  <div class="ed-sw">
                    <?php foreach ($edColors as $hex => $name): ?>
                      <button type="button" class="sw" data-color="<?= h($hex) ?>"
                              style="background:<?= h($hex) ?>" title="<?= h($name) ?>"></button>
                    <?php endforeach; ?>
                  </div>
                  <div class="ed-pop__t">Kiemelés</div>
                  <div class="ed-sw">
                    <?php foreach ($edMarks as $hex => $name): ?>
                      <button type="button" class="sw" data-mark="<?= h($hex) ?>"
                              style="background:<?= h($hex) ?>" title="<?= h($name) ?>"></button>
                    <?php endforeach; ?>
                    <button type="button" class="sw sw--none" data-mark="none" title="Kiemelés törlése"></button>
                  </div>
                  <label class="ed-pop__c">Egyéni szín
                    <input type="color" class="ed-color-custom" value="#0a6ed1"></label>
                </div>
              </div>
            </div>

            <div class="ed-grp">
              <button type="button" data-cmd="insertUnorderedList" title="Felsorolás">•&nbsp;lista</button>
              <button type="button" data-cmd="insertOrderedList" title="Számozott lista">1.&nbsp;lista</button>
              <button type="button" data-cmd="outdent" title="Behúzás csökkentése">⇤</button>
              <button type="button" data-cmd="indent" title="Behúzás növelése">⇥</button>
            </div>

            <div class="ed-grp">
              <button type="button" data-align="left"    title="Balra igazítás">≡<sub>◀</sub></button>
              <button type="button" data-align="center"  title="Középre igazítás">≡<sub>◆</sub></button>
              <button type="button" data-align="right"   title="Jobbra igazítás">≡<sub>▶</sub></button>
              <button type="button" data-align="justify" title="Sorkizárt">≡<sub>■</sub></button>
            </div>

            <div class="ed-grp">
              <button type="button" data-act="link" title="Hivatkozás (Ctrl+K)">🔗</button>
              <button type="button" data-act="upload-image" title="Kép feltöltése és beszúrása">🖼&nbsp;Kép</button>
              <button type="button" data-act="upload-video" title="Videó feltöltése és beszúrása">🎬&nbsp;Videó</button>
              <div class="ed-pop">
 <button type="button" class="ed-pop__b ed-pop__b--wide ed-car" data-pop="table" title="Táblázat beszúrása">▦&nbsp;Tábla<span >▾</span></button>
                <div class="ed-pop__m ed-pop__m--tbl" data-pop-menu="table">
                  <div class="ed-pop__t">Új táblázat</div>
                  <div class="tblgrid" aria-label="Méret választása"></div>
                  <div class="tblgrid__lbl">3 × 3</div>
 <label class="ed-pop__c tbl-new-head">Fejlécsor <input type="checkbox" checked></label>
 <button type="button" class="btn btn--p btn--sm tbl-insert" style="width:100%;margin-top:8px">Beszúrás</button>
                </div>
              </div>
            </div>

            <div class="ed-grp">
              <button type="button" data-act="callout-tip" class="ed-b--tip" title="Tipp doboz">Tipp</button>
              <button type="button" data-act="callout-warn" class="ed-b--warn" title="Figyelmeztetés doboz">Figyelem</button>
              <button type="button" data-act="callout-crit" class="ed-b--crit" title="Piros, erős kiemelés — amit semmiképp nem szabad elnézni">Fontos</button>
            </div>

            <div class="ed-grp">
              <button type="button" data-cmd="removeFormat" title="Formázás törlése">Tiszta</button>
              <button type="button" class="ed-renumber" title="Címsorok újraszámozása most">1.2.3 Számozás</button>
            </div>

            <span class="sp"></span>
            <div class="ed-grp ed-grp--end">
              <label class="ed-chk" title="A címsorok számozása gépeléskor magától frissül">
                <input type="checkbox" class="ed-autonum" checked> Automatikus számozás
              </label>
              <button type="button" class="ed-source" title="HTML forrás mutatása">&lt;/&gt;&nbsp;HTML</button>
            </div>
          </div>
          <!-- Táblázat-eszközök: csak akkor látszik, ha a kurzor táblázatban áll.
               A Word „Táblázateszközök" fülének megfelelője. -->
          <div class="ed-tbl" hidden>
            <span class="ed-tbl__t">Táblázat</span>

            <div class="ed-grp">
              <button type="button" data-tbl="row-above"  title="Sor beszúrása fölé">↑ sor</button>
              <button type="button" data-tbl="row-below"  title="Sor beszúrása alá">↓ sor</button>
              <button type="button" data-tbl="row-del"    title="Sor törlése">✕ sor</button>
            </div>
            <div class="ed-grp">
              <button type="button" data-tbl="col-left"   title="Oszlop beszúrása balra">← oszlop</button>
              <button type="button" data-tbl="col-right"  title="Oszlop beszúrása jobbra">→ oszlop</button>
              <button type="button" data-tbl="col-del"    title="Oszlop törlése">✕ oszlop</button>
            </div>

            <div class="ed-grp">
              <select class="ed-sel tbl-border" title="Keret és cellavonalak">
                <option value="grid">Teljes rács</option>
                <option value="rows">Csak vízszintes vonalak</option>
                <option value="frame">Csak külső keret</option>
                <option value="plain">Nincs vonal</option>
              </select>
 <label class="ed-chk tbl-zebra" title="Váltakozó sorháttér"><input type="checkbox" > Sávozott</label>
            </div>

            <div class="ed-grp">
              <div class="ed-pop">
                <button type="button" class="ed-pop__b" data-pop="tblhead" title="Fejléc színei">
 <span class="ed-ink ed-car">Fejléc</span><span >▾</span>
                </button>
                <div class="ed-pop__m" data-pop-menu="tblhead">
                  <div class="ed-pop__t">Fejléc háttere</div>
                  <div class="ed-sw">
                    <?php foreach (['#eceff3' => 'Szürke', '#0a6ed1' => 'Kék', '#0854a0' => 'Sötétkék',
                                    '#107e3e' => 'Zöld', '#b8681a' => 'Narancs', '#bb0000' => 'Piros',
                                    '#1c2a3a' => 'Sötét'] as $hex => $name): ?>
                      <button type="button" class="sw" data-hbg="<?= h($hex) ?>"
                              style="background:<?= h($hex) ?>" title="<?= h($name) ?>"></button>
                    <?php endforeach; ?>
                    <button type="button" class="sw sw--none" data-hbg="none" title="Nincs kitöltés"></button>
                  </div>
                  <div class="ed-pop__t">Fejléc betűszíne</div>
                  <div class="ed-sw">
                    <?php foreach (['#1f2a36' => 'Sötét', '#ffffff' => 'Fehér', '#0854a0' => 'Kék'] as $hex => $name): ?>
                      <button type="button" class="sw" data-hfg="<?= h($hex) ?>"
                              style="background:<?= h($hex) ?>" title="<?= h($name) ?>"></button>
                    <?php endforeach; ?>
                  </div>
 <label class="ed-pop__c tbl-hbg-custom">Egyéni háttér <input type="color" value="#0a6ed1"></label>
 <label class="ed-pop__c tbl-hfg-custom">Egyéni betűszín <input type="color" value="#ffffff"></label>
                </div>
              </div>

              <div class="ed-pop">
                <button type="button" class="ed-pop__b" data-pop="tblcell" title="A kijelölt cellák színei">
 <span class="ed-ink ed-car">Cella</span><span >▾</span>
                </button>
                <div class="ed-pop__m" data-pop-menu="tblcell">
                  <div class="ed-pop__t">Cella háttere</div>
                  <div class="ed-sw">
                    <?php foreach (['#eceff3' => 'Szürke', '#e8f1fb' => 'Kék', '#e6f3ec' => 'Zöld',
                                    '#fdf3e7' => 'Narancs', '#fbecec' => 'Piros', '#fff3a3' => 'Sárga'] as $hex => $name): ?>
                      <button type="button" class="sw" data-cbg="<?= h($hex) ?>"
                              style="background:<?= h($hex) ?>" title="<?= h($name) ?>"></button>
                    <?php endforeach; ?>
                    <button type="button" class="sw sw--none" data-cbg="none" title="Kitöltés törlése"></button>
                  </div>
                  <div class="ed-pop__t">Cella betűszíne</div>
                  <div class="ed-sw">
                    <?php foreach (['#1f2a36' => 'Alap', '#0854a0' => 'Kék', '#107e3e' => 'Zöld',
                                    '#bb0000' => 'Piros', '#ffffff' => 'Fehér'] as $hex => $name): ?>
                      <button type="button" class="sw" data-cfg="<?= h($hex) ?>"
                              style="background:<?= h($hex) ?>" title="<?= h($name) ?>"></button>
                    <?php endforeach; ?>
                  </div>
 <label class="ed-pop__c tbl-cbg-custom">Egyéni háttér <input type="color" value="#e8f1fb"></label>
 <label class="ed-pop__c tbl-cfg-custom">Egyéni betűszín <input type="color" value="#1f2a36"></label>
                  <div class="hint" style="margin-top:6px">Több cellát is színezhetsz: húzd át rajtuk a kijelölést.</div>
                </div>
              </div>
            </div>

            <span class="sp"></span>
            <button type="button" data-tbl="delete" class="ed-b--warn" title="A teljes táblázat törlése">Táblázat törlése</button>
          </div>

          <div class="ed-area body" contenteditable="true" spellcheck="true"><?= fix_img_url($body) ?></div>
          <textarea class="ta ed-src" name="body"></textarea>
        </div>
    <?php
}
