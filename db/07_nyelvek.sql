-- ============================================================
-- 07 - A nyelvek az adatbazisba kerulnek
--
-- Eddig a nyelvlista a kodban allt (ADMIN_LANGS), ezert uj nyelvhez
-- forraskodot kellett modositani es ujra telepiteni. Mostantol a
-- help_lang tabla mondja meg, milyen nyelvek vannak, es a Beallitasok
-- fulon lehet ujat felvenni.
--
-- A sorrend elso eleme a FORRASNYELV (is_source = 1): ebbol keszul a
-- forditas, ezt nem lehet torolni.
-- ============================================================

CREATE TABLE IF NOT EXISTS help_lang (
  code        CHAR(5)      NOT NULL PRIMARY KEY,   -- hu, en, de, sk, ro ...
  name        VARCHAR(60)  NOT NULL,               -- Magyar, English, Deutsch
  own_name    VARCHAR(60)  DEFAULT NULL,           -- ahogy az adott nyelven hivjak
  is_source   TINYINT(1)   NOT NULL DEFAULT 0,
  is_active   TINYINT(1)   NOT NULL DEFAULT 1,
  sort_order  INT          NOT NULL DEFAULT 0,
  created_at  DATETIME     NOT NULL DEFAULT current_timestamp(),
  KEY help_lang_order (is_active, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

-- a mostani harom nyelv atvetele (ha meg nincs benne)
INSERT IGNORE INTO help_lang (code, name, own_name, is_source, is_active, sort_order) VALUES
  ('hu', 'Magyar',  'Magyar',   1, 1, 10),
  ('en', 'English', 'English',  0, 1, 20),
  ('de', 'Deutsch', 'Deutsch',  0, 1, 30);

-- Egyszerre csak EGY forrasnyelv lehet.
CREATE OR REPLACE VIEW help_lang_source AS
  SELECT code FROM help_lang WHERE is_source = 1 ORDER BY sort_order LIMIT 1;
