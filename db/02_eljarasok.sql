-- ============================================================
-- Infinity Sugo - tarolt eljarasok, fuggvenyek, nezetek  (MariaDB 11.8+)
--
--   help_plain()        HTML -> kereshetó sima szoveg
--   help_publish()      a vazlat elesitese (verziomentessel, changeloggal)
--   help_unpublish()    a legutobbi kozzetetel visszavonasa
--   help_close_release() kiadas lezarasa
--   help_news           a "Mi ujsag" a lezart kiadasokbol
--   help_whatsnew       a friss valtozasok az olvasoi oldalnak
-- ============================================================

SET NAMES utf8mb4 COLLATE utf8mb4_uca1400_ai_ci;

DROP FUNCTION IF EXISTS help_plain;
DROP PROCEDURE IF EXISTS help_publish;
DROP PROCEDURE IF EXISTS help_unpublish;
DROP PROCEDURE IF EXISTS help_close_release;

DELIMITER $$

-- ------------------------------------------------------------
-- HTML -> sima szoveg (a kereseshez es a kivonatokhoz)
-- ------------------------------------------------------------
CREATE FUNCTION help_plain(p_html LONGTEXT)
RETURNS LONGTEXT
DETERMINISTIC
NO SQL
BEGIN
  DECLARE v LONGTEXT;
  SET v = IFNULL(p_html, '');
  SET v = REGEXP_REPLACE(v, '<[^>]+>', ' ');
  SET v = REPLACE(v, '&nbsp;', ' ');
  SET v = REPLACE(v, '&amp;',  '&');
  SET v = REPLACE(v, '&lt;',   '<');
  SET v = REPLACE(v, '&gt;',   '>');
  SET v = REPLACE(v, '&quot;', '"');
  SET v = REPLACE(v, '&#39;',  '''');
  SET v = REGEXP_REPLACE(v, '[[:space:]]+', ' ');
  RETURN TRIM(v);
END$$

-- ------------------------------------------------------------
-- Kozzetetel: a vazlatbol eles tartalom lesz.
--   1. az elozo allapot bekerul a verziotortenetbe
--   2. a vazlat atkerul a body_html-be, a vazlat-mezok urulnek
--   3. ha van osszefoglalo, bejegyzes keszul a nyitott kiadasba
--   4. a fejezet "ujdonsag" jelolest kap (highlight_until), kiveve apro javitasnal
-- ------------------------------------------------------------
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
  DECLARE v_days  INT DEFAULT 30;
  DECLARE v_draft LONGTEXT;

  SELECT draft_html INTO v_draft FROM help_article WHERE id = p_article_id;
  IF v_draft IS NULL THEN
    -- nincs kozzetetelre varo vazlat: csendben nem csinalunk semmit
    LEAVE proc;
  END IF;

  SELECT CAST(IFNULL((SELECT value FROM help_setting WHERE `key` = 'highlight_days'), '30') AS UNSIGNED)
    INTO v_days;

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
      highlight_until = IF(p_minor = 1, highlight_until, DATE_ADD(CURRENT_DATE, INTERVAL v_days DAY)),
      source       = 'editor',
      is_published = 1,
      img_count    = (CHAR_LENGTH(draft_html) - CHAR_LENGTH(REPLACE(draft_html, '<img', ''))) DIV 4,
      draft_html = NULL, draft_title = NULL, draft_by = NULL, draft_at = NULL,
      locked_by = NULL, locked_at = NULL
    WHERE id = p_article_id;

  IF p_summary IS NOT NULL AND CHAR_LENGTH(TRIM(p_summary)) > 0 THEN
    SELECT id INTO v_rel FROM help_release WHERE status = 'open' LIMIT 1;
    INSERT INTO help_changelog
          (released_at, doc_version, module_id, change_type, description,
           release_id, article_id, anchor, is_minor, created_by)
    SELECT NULL, NULL, module_id, p_kind, p_summary, v_rel, id, p_anchor, p_minor, p_user_id
      FROM help_article WHERE id = p_article_id;
  END IF;
END proc$$

-- ------------------------------------------------------------
-- A legutobbi kozzetetel visszavonasa.
-- A cikk visszaall az elozo mentett verziora, a visszavont szoveg pedig
-- VAZLATKENT marad meg, hogy ne vesszen el.
-- p_ok:  1 = sikerult, 0 = nem volt mit visszavonni
-- ------------------------------------------------------------
CREATE PROCEDURE help_unpublish(
    IN  p_article_id INT,
    IN  p_user_id    INT,
    OUT p_ok         TINYINT
)
MODIFIES SQL DATA
proc: BEGIN
  DECLARE v_rev      INT;
  DECLARE v_title    VARCHAR(255);
  DECLARE v_body     LONGTEXT;
  DECLARE v_hash     VARCHAR(32);
  DECLARE v_docver   VARCHAR(32);
  DECLARE v_curtitle VARCHAR(255);
  DECLARE v_curbody  LONGTEXT;

  SET p_ok = 0;

  SELECT MAX(rev_no) INTO v_rev FROM help_article_revision WHERE article_id = p_article_id;
  IF v_rev IS NULL THEN
    LEAVE proc;
  END IF;

  SELECT title, body_html, content_hash, doc_version
    INTO v_title, v_body, v_hash, v_docver
    FROM help_article_revision
   WHERE article_id = p_article_id AND rev_no = v_rev;

  SELECT title, body_html INTO v_curtitle, v_curbody
    FROM help_article WHERE id = p_article_id;

  UPDATE help_article SET
      title        = v_title,
      body_html    = v_body,
      plain_text   = help_plain(v_body),
      content_hash = v_hash,
      doc_version  = IFNULL(v_docver, doc_version),
      -- a visszavont valtozat ne vesszen el: vazlatkent megmarad
      draft_html   = v_curbody,
      draft_title  = v_curtitle,
      draft_by     = p_user_id,
      draft_at     = NOW(),
      highlight_until = NULL,
      change_flag  = NULL
    WHERE id = p_article_id;

  DELETE FROM help_article_revision WHERE article_id = p_article_id AND rev_no = v_rev;

  -- a meg nyitott kiadasban levo bejegyzes torlese (a lezartakhoz nem nyulunk)
  DELETE c FROM help_changelog c
    JOIN help_release r ON r.id = c.release_id
   WHERE c.article_id = p_article_id AND r.status = 'open';

  SET p_ok = 1;
END proc$$

-- ------------------------------------------------------------
-- Kiadas lezarasa: datumot es verziot ad a bejegyzeseknek, majd
-- megnyit egy ujat. p_count = a "Mi ujsag" listaba kerulo bejegyzesek szama.
-- ------------------------------------------------------------
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

  INSERT INTO help_release (version, status) VALUES (p_next, 'open');
END$$

DELIMITER ;

-- ------------------------------------------------------------
-- Nezetek
-- ------------------------------------------------------------

-- a publikus oldal altal olvasott cikkek
CREATE OR REPLACE VIEW help_article_public AS
  SELECT a.*, m.title AS module_title, m.chapter_no AS module_no, m.slug AS module_slug
    FROM help_article a
    LEFT JOIN help_module m ON m.id = a.module_id
   WHERE a.is_published = 1;

-- "Mi ujsag" a lezart kiadasokbol
CREATE OR REPLACE VIEW help_news AS
  SELECT c.id, r.released_at, r.version, c.change_type, c.description,
         a.slug, a.chapter_no, a.title AS article_title, c.anchor,
         m.chapter_no AS module_no, m.title AS module_title,
         (r.released_at = (SELECT MAX(released_at) FROM help_release WHERE status = 'closed')) AS is_latest
    FROM help_changelog c
    JOIN help_release  r ON r.id = c.release_id AND r.status = 'closed'
    LEFT JOIN help_article a ON a.id = c.article_id AND a.is_published = 1
    LEFT JOIN help_module  m ON m.id = c.module_id
   WHERE c.is_minor = 0;

-- a friss valtozasok az olvasoi oldalnak (fejlec-szamlalo, Mi ujsag lap)
CREATE OR REPLACE VIEW help_whatsnew AS
  SELECT a.lang, a.slug, a.chapter_no, a.title,
         m.chapter_no AS module_no, m.title AS module_title,
         a.change_flag, a.updated_at, a.highlight_until,
         (SELECT c.description
            FROM help_changelog c
           WHERE c.article_id = a.id AND c.is_minor = 0
        ORDER BY c.id DESC LIMIT 1) AS summary
    FROM help_article a
    JOIN help_module  m ON m.id = a.module_id
   WHERE a.is_published = 1
     AND a.highlight_until IS NOT NULL
     AND a.highlight_until >= CURRENT_DATE;
