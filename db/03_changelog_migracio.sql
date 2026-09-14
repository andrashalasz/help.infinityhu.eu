-- ============================================================
-- Infinity Sugo - 003 migracio: Mi ujsag (valtozasnaplo + kiadasok)
-- Elofeltetel: 001 (Word import) es 002 (szerkeszto) lefutott
-- ============================================================
BEGIN;

-- ---------- kiadasok ----------
-- A bejegyzesek kiadasokba gyulnek. A nyitott kiadas addig fogadja oket,
-- amig le nem zarjak - a lezaras adja a datumot es a verziot a felhasznaloi oldalon.
CREATE TABLE IF NOT EXISTS help_release (
  id          serial PRIMARY KEY,
  version     varchar(32) NOT NULL UNIQUE,   -- pl. v2026.09
  status      varchar(8)  NOT NULL DEFAULT 'open',   -- open | closed
  released_at date,                          -- csak lezaras utan
  closed_by   integer,
  note        text,
  created_at  timestamptz NOT NULL DEFAULT now()
);

-- egyszerre csak EGY nyitott kiadas lehet
CREATE UNIQUE INDEX IF NOT EXISTS help_release_one_open
  ON help_release ((status)) WHERE status = 'open';

-- ---------- a 002-ben letrehozott changelog kiegeszitese ----------
ALTER TABLE help_changelog
  ADD COLUMN IF NOT EXISTS release_id integer REFERENCES help_release(id) ON DELETE SET NULL,
  ADD COLUMN IF NOT EXISTS article_id integer REFERENCES help_article(id) ON DELETE SET NULL,
  ADD COLUMN IF NOT EXISTS anchor     varchar(160),   -- ide ugrik a "Megnyitas" link
  ADD COLUMN IF NOT EXISTS is_minor   boolean NOT NULL DEFAULT false,
  ADD COLUMN IF NOT EXISTS created_by integer;

COMMENT ON COLUMN help_changelog.description IS
  'Egysoros osszefoglalo a FELHASZNALO nyelven. A rendszer javaslatot general, a szerkeszto atirja.';
COMMENT ON COLUMN help_changelog.is_minor IS
  'true = apro javitas, nem jelenik meg a Mi ujsagban (de az auditban benne van).';

CREATE INDEX IF NOT EXISTS help_changelog_rel_idx ON help_changelog (release_id);

-- ---------- nyitott kiadas biztositasa ----------
INSERT INTO help_release (version, status)
  SELECT 'v2026.09', 'open'
   WHERE NOT EXISTS (SELECT 1 FROM help_release WHERE status = 'open');

-- ---------- kozzetetel: bejegyzes a nyitott kiadasba ----------
-- A 002-ben letrehozott help_publish() lecserelese: mostantol atveszi a
-- szerkeszto altal megadott osszefoglalot, tipust es horgonyt.
CREATE OR REPLACE FUNCTION help_publish(
    p_article_id integer,
    p_user_id    integer,
    p_summary    text    DEFAULT NULL,     -- a felhasznaloi osszefoglalo
    p_kind       varchar DEFAULT 'mod',    -- new | mod | fix
    p_anchor     varchar DEFAULT NULL,
    p_minor      boolean DEFAULT false
) RETURNS void AS $$
DECLARE
  v_rev integer;
  v_rel integer;
BEGIN
  PERFORM 1 FROM help_article WHERE id = p_article_id AND draft_html IS NOT NULL;
  IF NOT FOUND THEN
    RAISE NOTICE 'Nincs kozzetetelre varo vazlat (article_id=%)', p_article_id;
    RETURN;
  END IF;

  -- 1. elozo allapot mentese
  SELECT coalesce(max(rev_no), 0) + 1 INTO v_rev
    FROM help_article_revision WHERE article_id = p_article_id;

  INSERT INTO help_article_revision (article_id, rev_no, doc_version, title, body_html, content_hash, note, created_by)
    SELECT id, v_rev, doc_version, title, body_html, content_hash, p_summary, p_user_id
      FROM help_article WHERE id = p_article_id;

  -- 2. vazlat elesitese
  UPDATE help_article SET
      title        = coalesce(draft_title, title),
      body_html    = draft_html,
      plain_text   = regexp_replace(draft_html, '<[^>]+>', ' ', 'g'),
      content_hash = md5(coalesce(draft_title, title) || '|' || draft_html),
      updated_at   = CURRENT_DATE,
      change_flag  = CASE WHEN p_kind = 'new' THEN 'new' ELSE 'mod' END,
      source       = 'editor',
      is_published = true,
      draft_html = NULL, draft_title = NULL, draft_by = NULL, draft_at = NULL,
      locked_by = NULL, locked_at = NULL
    WHERE id = p_article_id;

  -- 3. bejegyzes a NYITOTT kiadasba
  IF p_summary IS NOT NULL AND length(trim(p_summary)) > 0 THEN
    SELECT id INTO v_rel FROM help_release WHERE status = 'open' LIMIT 1;
    INSERT INTO help_changelog (released_at, doc_version, module_id, change_type, description,
                                release_id, article_id, anchor, is_minor, created_by)
      SELECT NULL, NULL, module_id, p_kind, p_summary, v_rel, id, p_anchor, p_minor, p_user_id
        FROM help_article WHERE id = p_article_id;
  END IF;
END;
$$ LANGUAGE plpgsql;

-- ---------- kiadas lezarasa ----------
CREATE OR REPLACE FUNCTION help_close_release(p_version varchar, p_next varchar, p_user_id integer)
RETURNS integer AS $$
DECLARE
  v_rel integer;
  v_cnt integer;
BEGIN
  SELECT id INTO v_rel FROM help_release WHERE status = 'open' LIMIT 1;
  IF v_rel IS NULL THEN
    RAISE EXCEPTION 'Nincs nyitott kiadas.';
  END IF;

  UPDATE help_release
     SET version = p_version, status = 'closed', released_at = CURRENT_DATE, closed_by = p_user_id
   WHERE id = v_rel;

  UPDATE help_changelog
     SET released_at = CURRENT_DATE, doc_version = p_version
   WHERE release_id = v_rel;

  SELECT count(*) INTO v_cnt FROM help_changelog WHERE release_id = v_rel AND NOT is_minor;

  UPDATE help_article SET doc_version = p_version WHERE is_published;

  INSERT INTO help_release (version, status) VALUES (p_next, 'open');
  RETURN v_cnt;
END;
$$ LANGUAGE plpgsql;

-- ---------- a felhasznaloi oldal lekerdezese ----------
CREATE OR REPLACE VIEW help_news AS
  SELECT c.id, r.released_at, r.version, c.change_type, c.description,
         a.slug, a.chapter_no, a.title AS article_title, c.anchor,
         m.chapter_no AS module_no, m.title AS module_title,
         (r.released_at = (SELECT max(released_at) FROM help_release WHERE status = 'closed')) AS is_latest
    FROM help_changelog c
    JOIN help_release r ON r.id = c.release_id AND r.status = 'closed'
    LEFT JOIN help_article a ON a.id = c.article_id AND a.is_published
    LEFT JOIN help_module  m ON m.id = c.module_id
   WHERE NOT c.is_minor
   ORDER BY r.released_at DESC, c.id;

COMMIT;

-- ============================================================
-- Hasznalat
-- ============================================================
-- kozzetetel osszefoglaloval:
--   SELECT help_publish(:id, :uid,
--          'A tomeges egyenlegkozlo mar figyelmeztet, ha 24 oran belul mar ment kimutatas.',
--          'mod', '5-4-2-egyenlegkozlo', false);
--
-- kiadas lezarasa:
--   SELECT help_close_release('v2026.09', 'v2026.10', :uid);
--
-- a Mi ujsag feed:
--   SELECT * FROM help_news LIMIT 50;
--
-- "uj neked" jelzes (opcionalis, felhasznalonkent):
--   help_read_state (user_id, last_seen_release_id) tabla, es a feedben
--   minden ennel ujabb bejegyzes kap jelolest. Igy nem a globalis
--   "legutobbi kiadas" dontii el, mit lat ujnak, hanem hogy o mikor jart itt.
