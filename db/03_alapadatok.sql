-- ============================================================
-- Infinity Sugo - alapadatok  (MariaDB)
--   * kezdo admin felhasznalo
--   * alapertelmezett beallitasok
--   * egy nyitott kiadas
-- ============================================================

SET NAMES utf8mb4 COLLATE utf8mb4_uca1400_ai_ci;

-- ---------- kezdo admin ----------
-- admin / 12345678
-- A jelszocsere NEM kotelezo a belepeskor: a Beallitasok fulon barmikor
-- elvegezheto, belepes utan egyszer emlekeztet ra a rendszer.
-- A hash bcrypt (PHP password_hash, PASSWORD_BCRYPT, cost 10).
INSERT IGNORE INTO help_user (username, password_hash, display_name, role, must_change_pw)
VALUES ('admin',
        '$2y$10$zvsStkKbEF5kT.X/yni0Nu.ikcFQC0vkeby3ZOIEjd/Xs2i1oqV66',
        'Adminisztrátor', 'admin', 1);

-- ---------- beallitasok ----------
INSERT IGNORE INTO help_setting (`key`, value) VALUES
  ('site_title_hu',  'Infinity Súgó'),
  ('site_title_en',  'Infinity Help'),
  ('site_title_de',  'Infinity Hilfe'),
  ('mt_provider',    'none'),        -- none | deepl | libre | google (a kornyezeti valtozo erosebb)
  ('mt_endpoint',    ''),
  ('mt_key',         ''),
  ('mt_auto',        '0'),           -- 1 = uj/importalt fejezet automatikus forditasa
  ('highlight_days', '30'),          -- ennyi napig szamit ujdonsagnak egy valtozas
  ('export_company', 'Infinity ERP'),
  ('export_footer',  'Infinity használati útmutató');

-- ---------- nyitott kiadas ----------
INSERT INTO help_release (version, status)
SELECT 'v2026.09', 'open'
  FROM DUAL
 WHERE NOT EXISTS (SELECT 1 FROM help_release WHERE status = 'open');
