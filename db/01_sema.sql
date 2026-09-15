-- ============================================================
-- Infinity Sugo - adatbazis sema  (MariaDB 11.8+)
--
-- Karakterkeszlet: utf8mb4, rendezes: utf8mb4_uca1400_ai_ci
--   ai = accent insensitive, ci = case insensitive
-- Ezert a keresesben NEM kell kulon ekezet-eltavolito fuggveny (a
-- PostgreSQL-valtozat erp_norm()-ja): a "szamla" magatol megtalalja a
-- "Számlá"-t, a "penzugy" a "Pénzügy"-et.
--
-- SZANDEKOSAN NEM a ..._hungarian_ai_ci van itt: a magyar tajolas az o/o/u/u
-- parokat kulon betunek veszi, ezert a "penzugy" NEM talalna meg a "Pénzügy"-et.
-- Rendezni ugyis sort_order es chapter_no szerint rendezunk, nem cim szerint.
--
-- A teljes szoveges keresest InnoDB FULLTEXT index szolgalja ki
-- (help_article.title + plain_text), LIKE-tartalekkal a rovid szavakra.
-- ============================================================

SET NAMES utf8mb4 COLLATE utf8mb4_uca1400_ai_ci;
SET SESSION sql_mode = 'STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION';

-- ---------- modulok ----------
CREATE TABLE IF NOT EXISTS help_module (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  chapter_no  VARCHAR(16)  NOT NULL,
  slug        VARCHAR(120) NOT NULL,
  title       VARCHAR(255) NOT NULL,
  lang        CHAR(2)      NOT NULL DEFAULT 'hu',
  sort_order  INT          NOT NULL DEFAULT 0,
  UNIQUE KEY help_module_slug_lang (slug, lang),
  KEY help_module_lang_sort (lang, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

-- ---------- cikkek (fejezet szint) ----------
CREATE TABLE IF NOT EXISTS help_article (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  module_id     INT          DEFAULT NULL,
  chapter_no    VARCHAR(16)  NOT NULL,
  slug          VARCHAR(160) NOT NULL,
  title         VARCHAR(255) NOT NULL,
  lang          CHAR(2)      NOT NULL DEFAULT 'hu',
  body_html     LONGTEXT     NOT NULL,
  plain_text    LONGTEXT     NOT NULL,
  doc_version   VARCHAR(32)  NOT NULL,
  updated_at    DATE         NOT NULL,
  change_flag   VARCHAR(8)   DEFAULT NULL,        -- new | mod | NULL
  img_count     INT          NOT NULL DEFAULT 0,
  content_hash  VARCHAR(32)  NOT NULL,
  sort_order    INT          NOT NULL DEFAULT 0,
  is_published  TINYINT(1)   NOT NULL DEFAULT 1,
  permission    VARCHAR(64)  DEFAULT NULL,

  -- szerkeszto (vazlat / kozzetett kettosseg)
  draft_html    LONGTEXT     DEFAULT NULL,
  draft_title   VARCHAR(255) DEFAULT NULL,
  draft_by      INT          DEFAULT NULL,
  draft_at      DATETIME     DEFAULT NULL,
  locked_by     INT          DEFAULT NULL,
  locked_at     DATETIME     DEFAULT NULL,
  source        VARCHAR(16)  NOT NULL DEFAULT 'word',   -- word | editor

  -- forditasi allapot
  translated_from_hash VARCHAR(32) DEFAULT NULL,
  translated_by        VARCHAR(16) DEFAULT NULL,
  translated_at        DATETIME    DEFAULT NULL,

  -- ujdonsag-kiemeles az olvasoi oldalon
  highlight_until DATE DEFAULT NULL,

  UNIQUE KEY help_article_slug_lang (slug, lang),
  KEY help_article_module (module_id),
  KEY help_article_lang_sort (lang, sort_order),
  KEY help_article_highlight (lang, highlight_until),
  KEY help_article_chapter (lang, chapter_no),
  FULLTEXT KEY help_article_ft (title, plain_text),
  CONSTRAINT help_article_module_fk FOREIGN KEY (module_id)
    REFERENCES help_module(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

-- ---------- szakaszok (alfejezet szint: horgony + oldalon beluli TJ) ----------
CREATE TABLE IF NOT EXISTS help_section (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  article_id  INT          NOT NULL,
  chapter_no  VARCHAR(16)  NOT NULL,
  anchor      VARCHAR(160) NOT NULL,
  title       VARCHAR(255) NOT NULL,
  level       SMALLINT     NOT NULL DEFAULT 3,
  plain_text  LONGTEXT     NOT NULL,
  sort_order  INT          NOT NULL DEFAULT 0,
  KEY help_section_article (article_id, sort_order),
  CONSTRAINT help_section_article_fk FOREIGN KEY (article_id)
    REFERENCES help_article(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

-- ---------- kepernyo -> fejezet hozzarendeles (a "?" gombhoz) ----------
CREATE TABLE IF NOT EXISTS help_screen_map (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  route       VARCHAR(160) NOT NULL,
  article_id  INT          NOT NULL,
  anchor      VARCHAR(160) DEFAULT NULL,
  is_verified TINYINT(1)   NOT NULL DEFAULT 0,
  UNIQUE KEY help_screen_map_route (route),
  KEY help_screen_map_article (article_id),
  CONSTRAINT help_screen_map_article_fk FOREIGN KEY (article_id)
    REFERENCES help_article(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

-- ---------- kiadasok ----------
-- Egyszerre csak EGY nyitott kiadas lehet: ezt a generalt oszlopra tett
-- egyedi index biztositja (a MariaDB nem ismer reszleges indexet).
CREATE TABLE IF NOT EXISTS help_release (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  version     VARCHAR(32) NOT NULL,
  status      VARCHAR(8)  NOT NULL DEFAULT 'open',    -- open | closed
  released_at DATE        DEFAULT NULL,
  closed_by   INT         DEFAULT NULL,
  note        TEXT        DEFAULT NULL,
  created_at  DATETIME    NOT NULL DEFAULT current_timestamp(),
  open_flag   TINYINT GENERATED ALWAYS AS (IF(status = 'open', 1, NULL)) STORED,
  UNIQUE KEY help_release_version (version),
  UNIQUE KEY help_release_one_open (open_flag)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

-- ---------- valtozasnaplo ----------
CREATE TABLE IF NOT EXISTS help_changelog (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  released_at  DATE         DEFAULT NULL,      -- a kiadas lezarasakor tolt ki
  doc_version  VARCHAR(32)  DEFAULT NULL,
  module_id    INT          DEFAULT NULL,
  change_type  VARCHAR(8)   NOT NULL,          -- new | mod | fix
  description  TEXT         NOT NULL,
  release_id   INT          DEFAULT NULL,
  article_id   INT          DEFAULT NULL,
  anchor       VARCHAR(160) DEFAULT NULL,
  is_minor     TINYINT(1)   NOT NULL DEFAULT 0,
  created_by   INT          DEFAULT NULL,
  KEY help_changelog_release (release_id),
  KEY help_changelog_article (article_id),
  CONSTRAINT help_changelog_module_fk  FOREIGN KEY (module_id)  REFERENCES help_module(id)  ON DELETE SET NULL,
  CONSTRAINT help_changelog_release_fk FOREIGN KEY (release_id) REFERENCES help_release(id) ON DELETE SET NULL,
  CONSTRAINT help_changelog_article_fk FOREIGN KEY (article_id) REFERENCES help_article(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

-- ---------- visszajelzes ----------
CREATE TABLE IF NOT EXISTS help_feedback (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  article_id  INT        NOT NULL,
  user_id     INT        DEFAULT NULL,
  is_helpful  TINYINT(1) NOT NULL,
  comment     TEXT       DEFAULT NULL,
  created_at  DATETIME   NOT NULL DEFAULT current_timestamp(),
  KEY help_feedback_article (article_id),
  CONSTRAINT help_feedback_article_fk FOREIGN KEY (article_id)
    REFERENCES help_article(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

-- ---------- verziotortenet (minden kozzetetel elott ide kerul az elozo allapot) ----------
CREATE TABLE IF NOT EXISTS help_article_revision (
  id           BIGINT AUTO_INCREMENT PRIMARY KEY,
  article_id   INT          NOT NULL,
  rev_no       INT          NOT NULL,
  doc_version  VARCHAR(32)  DEFAULT NULL,
  title        VARCHAR(255) NOT NULL,
  body_html    LONGTEXT     NOT NULL,
  content_hash VARCHAR(32)  NOT NULL,
  note         VARCHAR(255) DEFAULT NULL,
  created_by   INT          DEFAULT NULL,
  created_at   DATETIME     NOT NULL DEFAULT current_timestamp(),
  UNIQUE KEY help_rev_article_no (article_id, rev_no),
  KEY help_rev_article (article_id, rev_no DESC),
  CONSTRAINT help_rev_article_fk FOREIGN KEY (article_id)
    REFERENCES help_article(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

-- ---------- kepek es videok ----------
-- A fajlnev a tartalom sha256-janak eleje, ezert egy fajl egyszer letezik.
CREATE TABLE IF NOT EXISTS help_media (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  filename    VARCHAR(80)  NOT NULL,
  sha256      CHAR(64)     NOT NULL,
  mime        VARCHAR(40)  NOT NULL,
  bytes       INT          NOT NULL,
  width       INT          DEFAULT NULL,
  height      INT          DEFAULT NULL,
  alt_text    VARCHAR(255) DEFAULT NULL,
  kind        VARCHAR(8)   NOT NULL DEFAULT 'image',   -- image | video
  duration    INT          DEFAULT NULL,
  title       VARCHAR(255) DEFAULT NULL,
  uploaded_by INT          DEFAULT NULL,
  uploaded_at DATETIME     NOT NULL DEFAULT current_timestamp(),
  UNIQUE KEY help_media_filename (filename),
  UNIQUE KEY help_media_sha (sha256)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

CREATE TABLE IF NOT EXISTS help_media_usage (
  media_id   INT NOT NULL,
  article_id INT NOT NULL,
  PRIMARY KEY (media_id, article_id),
  KEY help_media_usage_article (article_id),
  CONSTRAINT help_media_usage_media_fk   FOREIGN KEY (media_id)   REFERENCES help_media(id)   ON DELETE CASCADE,
  CONSTRAINT help_media_usage_article_fk FOREIGN KEY (article_id) REFERENCES help_article(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

-- ---------- felhasznalok ----------
CREATE TABLE IF NOT EXISTS help_user (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  username       VARCHAR(64)  NOT NULL,
  password_hash  VARCHAR(255) NOT NULL,
  display_name   VARCHAR(120) NOT NULL DEFAULT '',
  email          VARCHAR(190) DEFAULT NULL,
  role           VARCHAR(16)  NOT NULL DEFAULT 'editor',   -- admin | editor | translator
  is_active      TINYINT(1)   NOT NULL DEFAULT 1,
  must_change_pw TINYINT(1)   NOT NULL DEFAULT 0,
  last_login_at  DATETIME     DEFAULT NULL,
  failed_logins  INT          NOT NULL DEFAULT 0,
  locked_until   DATETIME     DEFAULT NULL,
  created_at     DATETIME     NOT NULL DEFAULT current_timestamp(),
  UNIQUE KEY help_user_username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

-- ---------- beallitasok ----------
CREATE TABLE IF NOT EXISTS help_setting (
  `key`      VARCHAR(64) PRIMARY KEY,
  value      TEXT        DEFAULT NULL,
  updated_at DATETIME    NOT NULL DEFAULT current_timestamp(),
  updated_by INT         DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

-- ---------- Word import ----------
CREATE TABLE IF NOT EXISTS help_import (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  filename    VARCHAR(255) NOT NULL,
  lang        CHAR(2)      NOT NULL DEFAULT 'hu',
  bytes       INT          NOT NULL DEFAULT 0,
  status      VARCHAR(16)  NOT NULL DEFAULT 'parsed',   -- parsed | applied | discarded
  doc_version VARCHAR(32)  DEFAULT NULL,
  stats       LONGTEXT     NOT NULL DEFAULT '{}' CHECK (json_valid(stats)),
  uploaded_by INT          DEFAULT NULL,
  uploaded_at DATETIME     NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

CREATE TABLE IF NOT EXISTS help_import_item (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  import_id    INT          NOT NULL,
  seq          INT          NOT NULL DEFAULT 0,
  chapter_no   VARCHAR(16)  NOT NULL DEFAULT '',
  title        VARCHAR(255) NOT NULL DEFAULT '',
  slug         VARCHAR(160) NOT NULL DEFAULT '',
  module_no    VARCHAR(16)  NOT NULL DEFAULT '',
  module_title VARCHAR(255) NOT NULL DEFAULT '',
  body_html    LONGTEXT     NOT NULL,
  plain_text   LONGTEXT     NOT NULL,
  img_count    INT          NOT NULL DEFAULT 0,
  article_id   INT          DEFAULT NULL,
  match_state  VARCHAR(12)  NOT NULL DEFAULT 'new',     -- matched | new | same
  similarity   DECIMAL(5,2) NOT NULL DEFAULT 0,
  applied      TINYINT(1)   NOT NULL DEFAULT 0,
  applied_at   DATETIME     DEFAULT NULL,
  KEY help_import_item_imp (import_id, seq),
  CONSTRAINT help_import_item_imp_fk FOREIGN KEY (import_id)  REFERENCES help_import(id)  ON DELETE CASCADE,
  CONSTRAINT help_import_item_art_fk FOREIGN KEY (article_id) REFERENCES help_article(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

-- ---------- audit ----------
CREATE TABLE IF NOT EXISTS help_audit (
  id         BIGINT AUTO_INCREMENT PRIMARY KEY,
  user_id    INT         DEFAULT NULL,
  username   VARCHAR(64) DEFAULT NULL,
  action     VARCHAR(48) NOT NULL,
  object     VARCHAR(120) DEFAULT NULL,
  detail     TEXT        DEFAULT NULL,
  ip         VARCHAR(45) DEFAULT NULL,
  created_at DATETIME    NOT NULL DEFAULT current_timestamp(),
  KEY help_audit_created (created_at DESC)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

-- ---------- kuka (visszaallithato torles) ----------
CREATE TABLE IF NOT EXISTS help_trash (
  id          BIGINT AUTO_INCREMENT PRIMARY KEY,
  kind        VARCHAR(16)  NOT NULL,            -- article | module | move
  label       VARCHAR(300) NOT NULL,
  lang        CHAR(2)      DEFAULT NULL,
  payload     LONGTEXT     NOT NULL CHECK (json_valid(payload)),
  deleted_by  INT          DEFAULT NULL,
  deleted_at  DATETIME     NOT NULL DEFAULT current_timestamp(),
  restored_at DATETIME     DEFAULT NULL,
  KEY help_trash_open (deleted_at DESC)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
