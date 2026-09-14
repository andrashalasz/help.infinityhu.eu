-- ============================================================
-- Infinity Sugo - 005 migracio: admin felulet
--
-- Amit hozzatesz:
--   * help_user            - bejelentkezes (admin / 12345678, elso belepeskor kotelezo jelszocsere)
--   * help_setting         - futasideju beallitasok (oldalcim, gepi fordito, ...)
--   * help_import          - Word (.docx) importok naploja
--   * help_import_item     - az importbol szarmazo fejezetek, osszehasonlitasra varva
--   * help_audit           - ki mit csinalt az admin feluleten
--   * help_rw szerepkor    - az admin felulet iras-olvasas jogu adatbazis-felhasznaloja
--   * ket hibajavitas a 003 migracio help_publish() fuggvenyehez (lasd lent)
--
-- Elofeltetel: 01 - 04 mar lefutott.
-- ============================================================
BEGIN;

-- ---------- 1. felhasznalok ----------
CREATE TABLE IF NOT EXISTS help_user (
  id             serial PRIMARY KEY,
  username       varchar(64)  NOT NULL UNIQUE,
  password_hash  varchar(255) NOT NULL,
  display_name   varchar(120) NOT NULL DEFAULT '',
  email          varchar(190),
  role           varchar(16)  NOT NULL DEFAULT 'editor',   -- admin | editor | translator
  is_active      boolean      NOT NULL DEFAULT true,
  must_change_pw boolean      NOT NULL DEFAULT false,
  last_login_at  timestamptz,
  failed_logins  integer      NOT NULL DEFAULT 0,
  locked_until   timestamptz,
  created_at     timestamptz  NOT NULL DEFAULT now()
);

COMMENT ON COLUMN help_user.must_change_pw IS
  'true = a kovetkezo belepes utan a rendszer kotelezoen jelszocseret ker.';

-- Kezdo admin: admin / 12345678 - az elso belepesnel KOTELEZO megvaltoztatni.
-- A hash bcrypt (PHP password_hash, PASSWORD_BCRYPT, cost 10).
INSERT INTO help_user (username, password_hash, display_name, role, must_change_pw)
  SELECT 'admin',
         '$2y$10$zvsStkKbEF5kT.X/yni0Nu.ikcFQC0vkeby3ZOIEjd/Xs2i1oqV66',
         'Adminisztrátor', 'admin', true
   WHERE NOT EXISTS (SELECT 1 FROM help_user WHERE username = 'admin');

-- ---------- 2. beallitasok ----------
CREATE TABLE IF NOT EXISTS help_setting (
  key        varchar(64) PRIMARY KEY,
  value      text,
  updated_at timestamptz NOT NULL DEFAULT now(),
  updated_by integer
);

INSERT INTO help_setting (key, value) VALUES
  ('site_title_hu', 'Infinity Súgó'),
  ('site_title_en', 'Infinity Help'),
  ('site_title_de', 'Infinity Hilfe'),
  ('mt_provider',   'none'),        -- none | deepl | libre | google  (env is felulirhatja)
  ('mt_endpoint',   ''),
  ('mt_key',        '')
ON CONFLICT (key) DO NOTHING;

-- ---------- 3. Word import ----------
CREATE TABLE IF NOT EXISTS help_import (
  id            serial PRIMARY KEY,
  filename      varchar(255) NOT NULL,
  lang          char(2)      NOT NULL DEFAULT 'hu',
  bytes         integer      NOT NULL DEFAULT 0,
  status        varchar(16)  NOT NULL DEFAULT 'parsed',  -- parsed | applied | discarded
  doc_version   varchar(32),
  stats         jsonb        NOT NULL DEFAULT '{}'::jsonb,
  uploaded_by   integer,
  uploaded_at   timestamptz  NOT NULL DEFAULT now()
);

-- Egy fejezet az importbol. A "match" allapot mondja meg, mit talalt a parolo:
--   matched  - van ilyen chapter_no/slug az adatbazisban
--   new      - nincs meg ilyen fejezet
--   same     - van, es a tartalom valtozatlan (nincs mit importalni)
CREATE TABLE IF NOT EXISTS help_import_item (
  id            serial PRIMARY KEY,
  import_id     integer NOT NULL REFERENCES help_import(id) ON DELETE CASCADE,
  seq           integer NOT NULL DEFAULT 0,
  chapter_no    varchar(16)  NOT NULL DEFAULT '',
  title         varchar(255) NOT NULL DEFAULT '',
  slug          varchar(160) NOT NULL DEFAULT '',
  module_no     varchar(16)  NOT NULL DEFAULT '',
  module_title  varchar(255) NOT NULL DEFAULT '',
  body_html     text         NOT NULL DEFAULT '',
  plain_text    text         NOT NULL DEFAULT '',
  img_count     integer      NOT NULL DEFAULT 0,
  article_id    integer REFERENCES help_article(id) ON DELETE SET NULL,
  match_state   varchar(12)  NOT NULL DEFAULT 'new',   -- matched | new | same
  similarity    numeric(5,2) NOT NULL DEFAULT 0,       -- 0..100, a jelenlegi szoveghez kepest
  applied       boolean      NOT NULL DEFAULT false,
  applied_at    timestamptz
);
CREATE INDEX IF NOT EXISTS help_import_item_imp_idx ON help_import_item (import_id, seq);

-- ---------- 4. audit ----------
CREATE TABLE IF NOT EXISTS help_audit (
  id         bigserial PRIMARY KEY,
  user_id    integer,
  username   varchar(64),
  action     varchar(48) NOT NULL,
  object     varchar(120),
  detail     text,
  ip         varchar(45),
  created_at timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS help_audit_created_idx ON help_audit (created_at DESC);

-- ---------- 5. forditasi allapot ----------
-- Melyik cikk melyik nyelvi valtozata mennyire naprakesz a magyarhoz kepest.
ALTER TABLE help_article
  ADD COLUMN IF NOT EXISTS translated_from_hash varchar(32),   -- a HU forras content_hash-e a forditas pillanataban
  ADD COLUMN IF NOT EXISTS translated_by        varchar(16),   -- manual | deepl | libre | google
  ADD COLUMN IF NOT EXISTS translated_at        timestamptz;

COMMENT ON COLUMN help_article.translated_from_hash IS
  'Ha ez elter a HU valtozat content_hash-etol, a forditas elavult - az admin Forditas fule jelzi.';

-- ---------- 6. ket hibajavitas a 003 help_publish()-hez ----------
-- (a) A 003 valtozat NULL-t ir a help_changelog.released_at es .doc_version
--     mezokbe (helyesen - a kiadas lezarasakor tolti ki), csakhogy a 001-ben
--     ezek NOT NULL-ok. Igy minden osszefoglaloval tortent kozzetetel
--     "null value in column released_at violates not-null constraint" hibaval
--     allt volna meg. Feloldjuk a megszoritast.
ALTER TABLE help_changelog ALTER COLUMN released_at DROP NOT NULL;
ALTER TABLE help_changelog ALTER COLUMN doc_version DROP NOT NULL;

-- (b) A kozzetetel a plain_text-et regexp_replace(draft_html, '<[^>]+>', ' ')
--     mintaval allitja elo, ami a HTML-entitasokat (&nbsp;, &amp;, &hellip;)
--     benne hagyja a kereshetó szovegben. Ezt egy kis segedfuggvennyel
--     rendbe tesszuk, es a help_publish() ezt hasznalja.
CREATE OR REPLACE FUNCTION help_plain(p_html text) RETURNS text AS $$
  SELECT trim(regexp_replace(
           replace(replace(replace(replace(replace(
             regexp_replace(coalesce(p_html, ''), '<[^>]+>', ' ', 'g'),
             '&nbsp;', ' '), '&amp;', '&'), '&lt;', '<'), '&gt;', '>'), '&quot;', '"'),
           '\s+', ' ', 'g'));
$$ LANGUAGE sql IMMUTABLE;

CREATE OR REPLACE FUNCTION help_publish(
    p_article_id integer,
    p_user_id    integer,
    p_summary    text    DEFAULT NULL,
    p_kind       varchar DEFAULT 'mod',
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

COMMIT;
