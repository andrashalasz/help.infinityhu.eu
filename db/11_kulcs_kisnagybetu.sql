-- 11_kulcs_kisnagybetu.sql
-- A kulcs MAGA A MAGYAR SZOVEG, es a feluleten szamit a kis- es nagybetu:
-- a "Vázlat" egy allapotcimke a szuroben, a "vázlat" pedig egy jelveny a
-- fejezet mellett. Az alapertelmezett rendezes (utf8mb4_uca1400_ai_ci) ezt a
-- kettot AZONOS kulcsnak latta, ezert a masodik felulirta az elsot - a
-- forditas beirodott, de sosem talalta meg a felulet (10 szoveg magyarul
-- maradt mindket nyelven).
--
-- FIGYELEM: ezt NEM szabad ALTER TABLE ... MODIFY COLUMN-nal megcsinalni.
-- A megletezo sorok a REGI rendezes szerint vannak az indexben; a rendezes
-- atirasa utan az index nem lesz konzisztens ("Index for table 'help_ui' is
-- corrupt"), es a tabla olvashatatlanna valik. Ezert uj tablat epitunk, es
-- atmasoljuk, ami atmasolhato - a hianyzo forditasokat a Beallitasok ->
-- A kezelofelulet szovegei lapon egy gombbal ujra le lehet gyartatni.

CREATE TABLE IF NOT EXISTS help_ui_uj (
  ui_key     varchar(300) COLLATE utf8mb4_bin NOT NULL,
  lang       char(5) NOT NULL,
  text       text NOT NULL,
  updated_at datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (ui_key(191), lang),
  KEY help_ui_lang (lang)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO help_ui_uj (ui_key, lang, text, updated_at)
  SELECT ui_key, lang, text, updated_at FROM help_ui;

DROP TABLE help_ui;
RENAME TABLE help_ui_uj TO help_ui;
