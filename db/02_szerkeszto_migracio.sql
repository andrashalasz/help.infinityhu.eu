-- ============================================================
-- Infinity Sugo - 002 migracio: szerkeszto felulet
-- Elofeltetel: help_articles.sql (001) mar lefutott
-- PostgreSQL 13+
-- ============================================================
BEGIN;

-- ---------- 1. vazlat / kozzetett kettosseg ----------
-- A publikus oldal a body_html-t olvassa, a szerkeszto a draft_html-t.
-- Kozzetetelnel: body_html := draft_html, draft_html := NULL.
ALTER TABLE help_article
  ADD COLUMN IF NOT EXISTS draft_html   text,
  ADD COLUMN IF NOT EXISTS draft_title  varchar(255),
  ADD COLUMN IF NOT EXISTS draft_by     integer,
  ADD COLUMN IF NOT EXISTS draft_at     timestamptz,
  ADD COLUMN IF NOT EXISTS locked_by    integer,          -- egyideju szerkesztes elleni soft lock
  ADD COLUMN IF NOT EXISTS locked_at    timestamptz,
  ADD COLUMN IF NOT EXISTS source       varchar(16) NOT NULL DEFAULT 'word';
                                        -- word | editor - honnan jott a jelenlegi tartalom

COMMENT ON COLUMN help_article.draft_html IS
  'Nem NULL = van kozzetetelre varo vazlat. A publikus oldal ezt nem latja.';

-- ---------- 2. verziotortenet (visszaallithatosag) ----------
CREATE TABLE IF NOT EXISTS help_article_revision (
  id           bigserial PRIMARY KEY,
  article_id   integer NOT NULL REFERENCES help_article(id) ON DELETE CASCADE,
  rev_no       integer NOT NULL,
  doc_version  varchar(32),
  title        varchar(255) NOT NULL,
  body_html    text NOT NULL,
  content_hash varchar(32)  NOT NULL,
  note         varchar(255),
  created_by   integer,
  created_at   timestamptz NOT NULL DEFAULT now(),
  UNIQUE (article_id, rev_no)
);
CREATE INDEX IF NOT EXISTS help_rev_art_idx ON help_article_revision (article_id, rev_no DESC);

COMMENT ON TABLE help_article_revision IS
  'Minden kozzetetel elott ide kerul az elozo allapot. Innen barmely verzio visszaallithato.';

-- ---------- 3. kepek nyilvantartasa ----------
-- A fajlnev a tartalom hash-e, ezert egy kep egyszer letezik, es tobb cikk
-- hivatkozhat ra. Torolni csak akkor szabad, ha nincs tobb hivatkozo.
CREATE TABLE IF NOT EXISTS help_media (
  id          serial PRIMARY KEY,
  filename    varchar(80) NOT NULL UNIQUE,   -- img_<sha256[0:12]>.png
  sha256      char(64)    NOT NULL UNIQUE,
  mime        varchar(40) NOT NULL,
  bytes       integer     NOT NULL,
  width       integer,
  height      integer,
  alt_text    varchar(255),
  uploaded_by integer,
  uploaded_at timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE IF NOT EXISTS help_media_usage (
  media_id   integer NOT NULL REFERENCES help_media(id) ON DELETE CASCADE,
  article_id integer NOT NULL REFERENCES help_article(id) ON DELETE CASCADE,
  PRIMARY KEY (media_id, article_id)
);

-- ---------- 4. szerkesztoi jogosultsagok ----------
-- Nyelvfuggetlen kulcsok (entity.action), a Rulebook konvencioja szerint.
--   help.view          - sugo olvasasa (alap, mindenki)
--   help.edit          - vazlat szerkesztese es mentese
--   help.publish       - kozzetetel (ez a szuk kapu, keves embernek)
--   help.manage        - fejezet letrehozas/torles, atszamozas, route hozzarendeles
--   help.media         - kep feltoltes

-- ---------- 5. kozzeteteli tranzakcio (tarolt eljaras) ----------
CREATE OR REPLACE FUNCTION help_publish(p_article_id integer, p_user_id integer, p_note varchar DEFAULT NULL)
RETURNS void AS $$
DECLARE
  v_rev integer;
BEGIN
  -- nincs vazlat -> nincs mit tenni
  PERFORM 1 FROM help_article WHERE id = p_article_id AND draft_html IS NOT NULL;
  IF NOT FOUND THEN
    RAISE NOTICE 'Nincs kozzetetelre varo vazlat (article_id=%)', p_article_id;
    RETURN;
  END IF;

  -- 1. az elozo allapot mentese
  SELECT coalesce(max(rev_no), 0) + 1 INTO v_rev
    FROM help_article_revision WHERE article_id = p_article_id;

  INSERT INTO help_article_revision (article_id, rev_no, doc_version, title, body_html, content_hash, note, created_by)
    SELECT id, v_rev, doc_version, title, body_html, content_hash, p_note, p_user_id
      FROM help_article WHERE id = p_article_id;

  -- 2. vazlat -> elo
  UPDATE help_article SET
      title        = coalesce(draft_title, title),
      body_html    = draft_html,
      plain_text   = regexp_replace(draft_html, '<[^>]+>', ' ', 'g'),
      content_hash = md5(coalesce(draft_title, title) || '|' || draft_html),
      updated_at   = CURRENT_DATE,
      change_flag  = 'mod',
      source       = 'editor',
      is_published = true,
      draft_html   = NULL, draft_title = NULL, draft_by = NULL, draft_at = NULL,
      locked_by    = NULL, locked_at = NULL
    WHERE id = p_article_id;

  -- 3. changelog bejegyzes
  INSERT INTO help_changelog (released_at, doc_version, module_id, change_type, description)
    SELECT CURRENT_DATE, doc_version, module_id, 'mod',
           chapter_no || ' ' || title || coalesce(' - ' || p_note, '')
      FROM help_article WHERE id = p_article_id;
END;
$$ LANGUAGE plpgsql;

-- ---------- 6. nezet a publikus oldalnak ----------
-- A frontend ezt olvassa, igy a vazlat soha nem szivarog ki.
CREATE OR REPLACE VIEW help_article_public AS
  SELECT id, module_id, chapter_no, slug, title, lang, body_html, plain_text,
         doc_version, updated_at, change_flag, img_count, sort_order, permission
    FROM help_article
   WHERE is_published;

COMMIT;

-- ============================================================
-- Hasznalat
-- ============================================================
-- vazlat mentese:
--   UPDATE help_article SET draft_html = :html, draft_title = :title,
--          draft_by = :uid, draft_at = now()
--    WHERE id = :id;
--
-- kozzetetel:
--   SELECT help_publish(:id, :uid, 'Korositas nezet leirasa');
--
-- visszaallitas a 3. verziora:
--   UPDATE help_article a SET draft_html = r.body_html, draft_title = r.title,
--          draft_by = :uid, draft_at = now()
--     FROM help_article_revision r
--    WHERE r.article_id = a.id AND a.id = :id AND r.rev_no = 3;
--   -- majd help_publish(), hogy a visszaallitas is auditalva legyen
--
-- nem hasznalt kepek (torolhetok):
--   SELECT m.filename FROM help_media m
--    WHERE NOT EXISTS (SELECT 1 FROM help_media_usage u WHERE u.media_id = m.id);
