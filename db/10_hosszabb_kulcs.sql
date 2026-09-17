-- 10_hosszabb_kulcs.sql
-- A felulet-szovegek kulcsa MAGA A MAGYAR SZOVEG (igy a forditotablaban
-- rogton olvashato forrasszoveg all, nem egy rejtjeles azonosito). Ehhez a
-- 80 karakter keves: a leghosszabb sugoszoveg 241 karakter.
--
-- A kulcs teljes hosszaban tarolodik, az egyedisegi index viszont csak az
-- elso 191 karaktert nezi (ennel hosszabb utf8mb4 index-elotag mar a
-- MariaDB hatarat feszegetne). Ket kulcs, ami 191 karakteren at azonos,
-- gyakorlatilag nem fordulhat elo - es ha megis, az a ket szoveg amugy is
-- ugyanaz lenne.

ALTER TABLE help_ui
  DROP PRIMARY KEY,
  MODIFY COLUMN ui_key varchar(300) NOT NULL,
  ADD PRIMARY KEY (ui_key(191), lang);
