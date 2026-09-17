-- ============================================================
-- 08 - A KEZELOFELULET sajat forditasa
--
-- Eddig minden felirat magyarul, beegetve allt a kodban. Ez a tabla tarolja
-- a felulet szovegeit nyelvenkent; a kod csak KULCSOT hasznal:
--
--     t('save')  ->  "Mentés" / "Save" / "Speichern"
--
-- Ami nincs leforditva, az a forrasnyelven (magyarul) jelenik meg - igy a
-- felulet sosem marad felirat nelkul, es a forditas fokozatosan potolhato.
-- ============================================================

CREATE TABLE IF NOT EXISTS help_ui (
  ui_key     VARCHAR(80)  NOT NULL,
  lang       CHAR(5)      NOT NULL,
  text       TEXT         NOT NULL,
  updated_at DATETIME     NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (ui_key, lang),
  KEY help_ui_lang (lang)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

-- Kinek milyen nyelvu a felulete. NULL = a sugo forrasnyelve.
ALTER TABLE help_user
  ADD COLUMN IF NOT EXISTS ui_lang CHAR(5) DEFAULT NULL AFTER role;
