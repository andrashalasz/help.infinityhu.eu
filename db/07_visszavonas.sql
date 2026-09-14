-- ============================================================
-- Infinity Sugo - 007 migracio: visszavonhato torles es kozzetetel
--
--   * help_trash  - a torolt fejezetek/modulok teljes tartalma, visszaallithatoan
--   * help_unpublish() - a legutobbi kozzetetel visszavonasa
--
-- Elofeltetel: 01 - 06 mar lefutott.
-- ============================================================
BEGIN;

-- ---------- 1. kuka ----------
-- A torles nem semmisiti meg a tartalmat: a cikk (vagy modul) teljes allapota
-- ide kerul JSON-kent, es a Kuka fulrol barmikor visszaallithato.
CREATE TABLE IF NOT EXISTS help_trash (
  id          bigserial PRIMARY KEY,
  kind        varchar(16)  NOT NULL,            -- article | module
  label       varchar(300) NOT NULL,            -- ember altal olvashato megnevezes
  lang        char(2),
  payload     jsonb        NOT NULL,            -- a sor(ok) teljes tartalma
  deleted_by  integer,
  deleted_at  timestamptz  NOT NULL DEFAULT now(),
  restored_at timestamptz
);
CREATE INDEX IF NOT EXISTS help_trash_open_idx
  ON help_trash (deleted_at DESC) WHERE restored_at IS NULL;

COMMENT ON TABLE help_trash IS
  'Torolt fejezetek/modulok. A restored_at kitoltese jelzi, hogy mar visszaallitottak.';

-- ---------- 2. kozzetetel visszavonasa ----------
-- A legutobbi kozzetetelt vonja vissza: a cikk visszaall az elozo mentett
-- verziora, a most kozzetett szoveg pedig VAZLATKENT marad meg, hogy ne vesszen el.
-- A hozza tartozo, meg le nem zart changelog-bejegyzest is torli.
CREATE OR REPLACE FUNCTION help_unpublish(p_article_id integer, p_user_id integer)
RETURNS boolean AS $$
DECLARE
  v_rev  integer;
  v_prev help_article_revision%ROWTYPE;
  v_cur  help_article%ROWTYPE;
BEGIN
  SELECT * INTO v_cur FROM help_article WHERE id = p_article_id;
  IF NOT FOUND THEN
    RETURN false;
  END IF;

  SELECT max(rev_no) INTO v_rev FROM help_article_revision WHERE article_id = p_article_id;
  IF v_rev IS NULL THEN
    RETURN false;            -- meg nem volt kozzeteve, nincs mit visszavonni
  END IF;

  SELECT * INTO v_prev FROM help_article_revision
   WHERE article_id = p_article_id AND rev_no = v_rev;

  UPDATE help_article SET
      title        = v_prev.title,
      body_html    = v_prev.body_html,
      plain_text   = help_plain(v_prev.body_html),
      content_hash = v_prev.content_hash,
      doc_version  = coalesce(v_prev.doc_version, doc_version),
      -- a visszavont valtozat ne vesszen el: vazlatkent megmarad
      draft_html   = v_cur.body_html,
      draft_title  = v_cur.title,
      draft_by     = p_user_id,
      draft_at     = now(),
      highlight_until = NULL,
      change_flag  = NULL
    WHERE id = p_article_id;

  DELETE FROM help_article_revision WHERE article_id = p_article_id AND rev_no = v_rev;

  -- a meg nyitott kiadasban levo bejegyzes torlese (a lezartakhoz nem nyulunk)
  DELETE FROM help_changelog c
   USING help_release r
   WHERE c.article_id = p_article_id
     AND c.release_id = r.id
     AND r.status = 'open';

  RETURN true;
END;
$$ LANGUAGE plpgsql;

COMMENT ON FUNCTION help_unpublish(integer, integer) IS
  'A legutobbi kozzetetel visszavonasa. A visszavont szoveg vazlatkent megmarad.';

COMMIT;
