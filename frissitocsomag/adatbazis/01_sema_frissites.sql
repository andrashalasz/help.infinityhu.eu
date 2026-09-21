-- ============================================================
-- Infinity Súgó — adatbázis-frissítés
-- a 2026-09-15-i telepítőcsomagról a 2026-09-18-i változatra
--
--   *** EZ A FÁJL NEM ÍR FELÜL EGYETLEN SÚGÓSZÖVEGET SEM. ***
--
-- Amihez hozzányúl:
--   - szerkezet: új oszlopok, új táblák, nézetek, tárolt eljárások
--   - help_ui:   a KEZELŐFELÜLET feliratai (nem a súgó tartalma)
--   - help_lang: a nyelvek listája (csak ha még nincs benne)
--   - EGY tartalmi mező: help_article.highlight_until — ez az „új/frissítve”
--     jelzés lejárati dátuma, nem a fejezet szövege. Lásd a 4. szakaszt.
--
-- Amihez NEM nyúl:
--   - help_article.title, body_html, draft_html, plain_text, slug, chapter_no
--   - help_module nevek, help_section, help_media, help_user, help_setting
--
-- TÖBBSZÖR IS FUTTATHATÓ: minden lépés ellenőrzi, kell-e még.
-- ============================================================

SET NAMES utf8mb4;


-- ============================================================
-- 1. NYELVEK TÁBLÁJA
-- Eddig a három nyelv a kódba volt beégetve. Mostantól az adatbázisból
-- jön, így fejlesztő nélkül is felvehető új nyelv.
-- ============================================================

CREATE TABLE IF NOT EXISTS help_lang (
  code        CHAR(5)      NOT NULL PRIMARY KEY,
  name        VARCHAR(60)  NOT NULL,
  own_name    VARCHAR(60)  NOT NULL DEFAULT '',
  is_source   TINYINT(1)   NOT NULL DEFAULT 0,
  is_active   TINYINT(1)   NOT NULL DEFAULT 1,
  sort_order  INT          NOT NULL DEFAULT 0,
  created_at  DATETIME     NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- INSERT IGNORE: ha a nyelv már benne van, marad úgy, ahogy be van állítva.
INSERT IGNORE INTO help_lang (code, name, own_name, is_source, is_active, sort_order) VALUES
  ('hu', 'Magyar',  'Magyar',  1, 1, 10),
  ('en', 'English', 'English', 0, 1, 20),
  ('de', 'Deutsch', 'Deutsch', 0, 1, 30);

CREATE OR REPLACE VIEW help_lang_source AS
  SELECT code FROM help_lang WHERE is_source = 1 LIMIT 1;


-- ============================================================
-- 2. A KEZELŐFELÜLET FELIRATAI
-- A súgó TARTALMÁHOZ semmi közük: ezek a gombok, címkék, üzenetek.
-- A kulcs maga a magyar szöveg, ezért hosszú lehet, és számít benne a
-- kis- és nagybetű ("Vázlat" állapotcímke ≠ "vázlat" jelvény).
-- ============================================================

CREATE TABLE IF NOT EXISTS help_ui (
  ui_key     VARCHAR(300) COLLATE utf8mb4_bin NOT NULL,
  lang       CHAR(5)      NOT NULL,
  text       TEXT         NOT NULL,
  updated_at DATETIME     NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (ui_key(191), lang),
  KEY help_ui_lang (lang)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Ha a tábla már létezett a régebbi, rövid és kis-nagybetűre érzéketlen
-- kulcsoszloppal, átépítjük. ALTER TABLE-lel ezt NEM szabad: a meglévő sorok
-- a régi rendezés szerint állnak az indexben, és az index inkonzisztenssé
-- válik ("Index for table 'help_ui' is corrupt").
DROP PROCEDURE IF EXISTS help_ui_atepites;
DELIMITER $$
CREATE PROCEDURE help_ui_atepites()
BEGIN
  DECLARE v_kell INT DEFAULT 0;

  SELECT COUNT(*) INTO v_kell
    FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE()
     AND TABLE_NAME   = 'help_ui'
     AND COLUMN_NAME  = 'ui_key'
     AND (CHARACTER_MAXIMUM_LENGTH < 300 OR COLLATION_NAME <> 'utf8mb4_bin');

  IF v_kell > 0 THEN
    DROP TABLE IF EXISTS help_ui_atmenet;

    CREATE TABLE help_ui_atmenet (
      ui_key     VARCHAR(300) COLLATE utf8mb4_bin NOT NULL,
      lang       CHAR(5)      NOT NULL,
      text       TEXT         NOT NULL,
      updated_at DATETIME     NOT NULL DEFAULT current_timestamp(),
      PRIMARY KEY (ui_key(191), lang),
      KEY help_ui_lang (lang)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

    INSERT IGNORE INTO help_ui_atmenet (ui_key, lang, text, updated_at)
      SELECT ui_key, lang, text, updated_at FROM help_ui;

    DROP TABLE help_ui;
    RENAME TABLE help_ui_atmenet TO help_ui;
  END IF;
END$$
DELIMITER ;
CALL help_ui_atepites();
DROP PROCEDURE help_ui_atepites;

-- Kinek milyen nyelvű a kezelőfelülete. NULL = a súgó forrásnyelve.
ALTER TABLE help_user
  ADD COLUMN IF NOT EXISTS ui_lang CHAR(5) DEFAULT NULL AFTER role;


-- ============================================================
-- 3. FEJEZETEK ÉS FŐFEJEZETEK ELREJTÉSE (szem ikon)
-- A fejezeteknél ez eddig is megvolt (is_published), a főfejezeteknél nem.
-- ============================================================

ALTER TABLE help_module
  ADD COLUMN IF NOT EXISTS is_published TINYINT(1) NOT NULL DEFAULT 1 AFTER sort_order;

-- A nyilvános nézetek: a fejezet csak akkor látszik, ha a saját kapcsolója
-- ÉS a főfejezete kapcsolója is be van kapcsolva.
CREATE OR REPLACE VIEW help_article_public AS
SELECT a.*, m.title AS module_title, m.chapter_no AS module_no, m.slug AS module_slug
  FROM help_article a
  LEFT JOIN help_module m ON m.id = a.module_id
 WHERE a.is_published = 1
   AND COALESCE(m.is_published, 1) = 1;

CREATE OR REPLACE VIEW help_whatsnew AS
SELECT a.lang, a.slug, a.chapter_no, a.title,
       m.chapter_no AS module_no, m.title AS module_title,
       a.change_flag, a.updated_at, a.highlight_until,
       (SELECT c.description FROM help_changelog c
         WHERE c.article_id = a.id AND c.is_minor = 0
         ORDER BY c.id DESC LIMIT 1) AS summary
  FROM help_article a
  JOIN help_module m ON m.id = a.module_id
 WHERE a.is_published = 1
   AND m.is_published = 1
   AND a.highlight_until IS NOT NULL
   AND a.highlight_until >= curdate();



-- ============================================================
-- 3/b. SLUG-TÖRTÉNET — a régi címek ne haljanak el
--
-- A fejezetszám mostantól látszik az URL-ben is, és átszámozáskor követi.
-- Hogy a korábban kiadott hivatkozások (könyvjelzők, e-mailbe másolt
-- linkek) ne fussanak 404-re, minden címváltozás feljegyződik ide, és a
-- nyilvános oldal innen irányít át 301-gyel a mostani címre.
-- ============================================================

CREATE TABLE IF NOT EXISTS help_slug_history (
  slug       VARCHAR(160) NOT NULL,
  lang       CHAR(5)      NOT NULL,
  article_id INT          NOT NULL,
  created_at DATETIME     NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (slug, lang),
  KEY help_slug_history_article (article_id),
  CONSTRAINT help_slug_history_fk FOREIGN KEY (article_id)
      REFERENCES help_article (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- 4. KÖZZÉTÉTELI ELJÁRÁSOK
-- Az újdonságjelzés mostantól a KIADÁS LEZÁRÁSÁIG marad kint, nem 30 napig.
-- A határidő nélküliséget távoli dátum jelöli (9999-12-31), mert a
-- help_whatsnew nézet a highlight_until >= CURRENT_DATE feltételre épül.
--
-- Az eljárások újraírása a súgó szövegeit NEM érinti: ezek csak a
-- közzététel MENETÉT írják le, nem tárolnak tartalmat.
-- ============================================================

DELIMITER $$

DROP PROCEDURE IF EXISTS help_publish$$

CREATE PROCEDURE help_publish(
    IN p_article_id INT,
    IN p_user_id    INT,
    IN p_summary    TEXT,
    IN p_kind       VARCHAR(8),
    IN p_anchor     VARCHAR(160),
    IN p_minor      TINYINT
)
MODIFIES SQL DATA
proc: BEGIN
  DECLARE v_rev   INT;
  DECLARE v_rel   INT;
  DECLARE v_draft LONGTEXT;

  SELECT draft_html INTO v_draft FROM help_article WHERE id = p_article_id;
  IF v_draft IS NULL THEN
    LEAVE proc;
  END IF;

  SELECT IFNULL(MAX(rev_no), 0) + 1 INTO v_rev
    FROM help_article_revision WHERE article_id = p_article_id;

  INSERT INTO help_article_revision
        (article_id, rev_no, doc_version, title, body_html, content_hash, note, created_by)
  SELECT id, v_rev, doc_version, title, body_html, content_hash, p_summary, p_user_id
    FROM help_article WHERE id = p_article_id;

  UPDATE help_article SET
      title        = IFNULL(draft_title, title),
      body_html    = draft_html,
      plain_text   = help_plain(draft_html),
      content_hash = MD5(CONCAT(IFNULL(draft_title, title), '|', draft_html)),
      updated_at   = CURRENT_DATE,
      change_flag  = IF(p_kind = 'new', 'new', 'mod'),
      -- a jelzes a kiadas lezarasaig marad kint; apro javitas nem kelti ujra
      highlight_until = IF(p_minor = 1, highlight_until, DATE '9999-12-31'),
      source       = 'editor',
      is_published = 1,
      img_count    = (CHAR_LENGTH(draft_html) - CHAR_LENGTH(REPLACE(draft_html, '<img', ''))) DIV 4,
      draft_html = NULL, draft_title = NULL, draft_by = NULL, draft_at = NULL,
      locked_by = NULL, locked_at = NULL
    WHERE id = p_article_id;

  SELECT id INTO v_rel FROM help_release WHERE status = 'open' LIMIT 1;

  INSERT INTO help_changelog
        (release_id, article_id, module_id, change_type, description, anchor, is_minor, created_by)
  SELECT v_rel, a.id, a.module_id, IFNULL(p_kind, 'mod'),
         IFNULL(NULLIF(p_summary, ''), CONCAT(a.chapter_no, ' ', a.title)),
         p_anchor, IFNULL(p_minor, 0), p_user_id
    FROM help_article a WHERE a.id = p_article_id;
END$$

DROP PROCEDURE IF EXISTS help_close_release$$

CREATE PROCEDURE help_close_release(
    IN  p_version VARCHAR(32),
    IN  p_next    VARCHAR(32),
    IN  p_user_id INT,
    OUT p_count   INT
)
MODIFIES SQL DATA
BEGIN
  DECLARE v_rel INT;

  SET p_count = 0;
  SELECT id INTO v_rel FROM help_release WHERE status = 'open' LIMIT 1;
  IF v_rel IS NULL THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Nincs nyitott kiadas.';
  END IF;

  UPDATE help_release
     SET version = p_version, status = 'closed', released_at = CURRENT_DATE, closed_by = p_user_id
   WHERE id = v_rel;

  UPDATE help_changelog
     SET released_at = CURRENT_DATE, doc_version = p_version
   WHERE release_id = v_rel;

  SELECT COUNT(*) INTO p_count FROM help_changelog WHERE release_id = v_rel AND is_minor = 0;

  UPDATE help_article SET doc_version = p_version WHERE is_published = 1;

  -- A kiadas lezarasaval az ujdonsagjelzesek egyszerre lekerulnek.
  UPDATE help_article
     SET highlight_until = NULL, change_flag = NULL
   WHERE is_published = 1 AND highlight_until IS NOT NULL;

  INSERT INTO help_release (version, status) VALUES (p_next, 'open');
END$$

DELIMITER ;

DELIMITER ;


-- ============================================================
-- 5. A MÁR KINT LÉVŐ JELZÉSEK ÁTÁLLÍTÁSA
--
-- EZ AZ EGYETLEN SOR, AMI A help_article TÁBLÁBA ÍR. Kizárólag a
-- highlight_until oszlopot állítja: ez az „új/frissítve” jelvény lejárati
-- dátuma a nyilvános oldalon. A fejezet CÍMÉHEZ ÉS SZÖVEGÉHEZ nem nyúl.
--
-- Miért kell: a régi rendszer 30 napot adott a jelzésnek. Enélkül a most
-- kint lévő jelzések a régi dátumukkal tűnnének el, nem a kiadás lezárásakor.
--
-- Ha nem akarod, hogy a mostani jelzések meghosszabbodjanak, ezt a
-- parancsot nyugodtan hagyd ki — semmi más nem múlik rajta.
-- ============================================================

UPDATE help_article
   SET highlight_until = DATE '9999-12-31'
 WHERE is_published = 1
   AND highlight_until IS NOT NULL
   AND highlight_until >= CURRENT_DATE;
