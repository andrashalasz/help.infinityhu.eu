-- ============================================================
-- 06 - Az ujdonsagjelzes a kiadas lezarasaig marad kint
--
-- Eddig a kozzetetel egy NAPSZAMOT adott a jelzesnek (highlight_days, 30 nap),
-- es a jelzes akkor is eltunt, ha a kiadas meg nyitva volt. Mostantol:
--
--   kozzetetel        -> a fejezet jelzest kap, hataridő nelkul
--   a kiadas lezarasa -> minden jelzes egyszerre lekerul
--
-- A hataridő nelkuliseget tavoli datum jeloli (9999-12-31), mert a
-- help_whatsnew nezet a highlight_until >= CURRENT_DATE feltetelre epul,
-- es igy a nezetet nem kell atirni.
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

-- A mar kint levo, napszamhoz kotott jelzesek atallitasa a kiadas vegeig.
UPDATE help_article
   SET highlight_until = DATE '9999-12-31'
 WHERE is_published = 1 AND highlight_until IS NOT NULL AND highlight_until >= CURRENT_DATE;
