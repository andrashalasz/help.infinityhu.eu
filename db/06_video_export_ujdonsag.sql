-- ============================================================
-- Infinity Sugo - 006 migracio
--   * videok is feltolthetok a kepek melle  (help_media.kind)
--   * a szerkesztobol kozvetlenul feltoltott fajlok nyilvantartasa
--   * "Mi ujdonsag" kiemelese az olvasoi oldalon (help_article.highlight_until)
--   * automatikus forditas kapcsolo (help_setting)
-- ============================================================
BEGIN;

-- ---------- 1. media: kep VAGY video ----------
ALTER TABLE help_media
  ADD COLUMN IF NOT EXISTS kind     varchar(8) NOT NULL DEFAULT 'image',   -- image | video
  ADD COLUMN IF NOT EXISTS duration integer,                               -- masodperc, ha ismert
  ADD COLUMN IF NOT EXISTS title    varchar(255);

COMMENT ON COLUMN help_media.kind IS
  'image = kepernyokep, video = oktatovideo. A fajlnev mindket esetben a tartalom hash-e.';

-- A sha256 oszlop eddig char(64) UNIQUE volt - ez marad, igy ugyanaz a video
-- sem kerul fel ketszer.

-- ---------- 2. ujdonsag-kiemeles az olvasoi oldalon ----------
-- A kozzetetel beallitja; amig le nem jar, a fejezet "ÚJ" / "FRISSÍTVE"
-- jelolest kap a fejezetfaban es a Mi ujsag listan.
ALTER TABLE help_article
  ADD COLUMN IF NOT EXISTS highlight_until date;

COMMENT ON COLUMN help_article.highlight_until IS
  'Eddig a napig mutatja az olvasoi oldal ujdonsagkent. NULL = nincs kiemelve.';

CREATE INDEX IF NOT EXISTS help_article_highlight_idx
  ON help_article (lang, highlight_until DESC) WHERE highlight_until IS NOT NULL;

-- ---------- 3. uj beallitasok ----------
INSERT INTO help_setting (key, value) VALUES
  ('mt_auto',          '0'),    -- 1 = uj/importalt fejezetet rogton leforditja gepi forditoval
  ('highlight_days',   '30'),   -- ennyi napig szamit ujdonsagnak egy kozzetett valtozas
  ('export_company',   'Infinity ERP'),
  ('export_footer',    'Infinity használati útmutató')
ON CONFLICT (key) DO NOTHING;

-- ---------- 4. kozzetetel: allitsa be a kiemelest is ----------
-- Ugyanaz, mint a 005-ben, egy sorral kiegeszitve (highlight_until).
CREATE OR REPLACE FUNCTION help_publish(
    p_article_id integer,
    p_user_id    integer,
    p_summary    text    DEFAULT NULL,
    p_kind       varchar DEFAULT 'mod',
    p_anchor     varchar DEFAULT NULL,
    p_minor      boolean DEFAULT false
) RETURNS void AS $$
DECLARE
  v_rev  integer;
  v_rel  integer;
  v_days integer;
BEGIN
  PERFORM 1 FROM help_article WHERE id = p_article_id AND draft_html IS NOT NULL;
  IF NOT FOUND THEN
    RAISE NOTICE 'Nincs kozzetetelre varo vazlat (article_id=%)', p_article_id;
    RETURN;
  END IF;

  SELECT coalesce((SELECT value FROM help_setting WHERE key = 'highlight_days'), '30')::integer
    INTO v_days;

  SELECT coalesce(max(rev_no), 0) + 1 INTO v_rev
    FROM help_article_revision WHERE article_id = p_article_id;

  INSERT INTO help_article_revision (article_id, rev_no, doc_version, title, body_html, content_hash, note, created_by)
    SELECT id, v_rev, doc_version, title, body_html, content_hash, p_summary, p_user_id
      FROM help_article WHERE id = p_article_id;

  UPDATE help_article SET
      title        = coalesce(draft_title, title),
      body_html    = draft_html,
      plain_text   = help_plain(draft_html),
      content_hash = md5(coalesce(draft_title, title) || '|' || draft_html),
      updated_at   = CURRENT_DATE,
      change_flag  = CASE WHEN p_kind = 'new' THEN 'new' ELSE 'mod' END,
      -- apro javitas nem szamit ujdonsagnak
      highlight_until = CASE WHEN p_minor THEN highlight_until
                             ELSE CURRENT_DATE + v_days END,
      source       = 'editor',
      is_published = true,
      img_count    = (length(draft_html) - length(replace(draft_html, '<img', ''))) / 4,
      draft_html = NULL, draft_title = NULL, draft_by = NULL, draft_at = NULL,
      locked_by = NULL, locked_at = NULL
    WHERE id = p_article_id;

  IF p_summary IS NOT NULL AND length(trim(p_summary)) > 0 THEN
    SELECT id INTO v_rel FROM help_release WHERE status = 'open' LIMIT 1;
    INSERT INTO help_changelog (released_at, doc_version, module_id, change_type, description,
                                release_id, article_id, anchor, is_minor, created_by)
      SELECT NULL, NULL, module_id, p_kind, p_summary, v_rel, id, p_anchor, p_minor, p_user_id
        FROM help_article WHERE id = p_article_id;
  END IF;
END;
$$ LANGUAGE plpgsql;

-- ---------- 5. az olvasoi "Mi ujsag" lista ----------
-- A lezart kiadasok bejegyzesei ES a friss (meg nyitott kiadasban levo)
-- valtozasok egyben, nyelvenkent.
CREATE OR REPLACE VIEW help_whatsnew AS
  SELECT a.lang,
         a.slug,
         a.chapter_no,
         a.title,
         m.chapter_no AS module_no,
         m.title      AS module_title,
         a.change_flag,
         a.updated_at,
         a.highlight_until,
         (SELECT c.description
            FROM help_changelog c
           WHERE c.article_id = a.id AND NOT c.is_minor
        ORDER BY c.id DESC LIMIT 1) AS summary
    FROM help_article a
    JOIN help_module  m ON m.id = a.module_id
   WHERE a.is_published
     AND a.highlight_until IS NOT NULL
     AND a.highlight_until >= CURRENT_DATE;

COMMIT;
