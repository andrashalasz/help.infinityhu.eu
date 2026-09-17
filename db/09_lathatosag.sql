-- 09_lathatosag.sql
-- A fofejezetek is ki- es bekapcsolhatok (szem ikon a fejezetlistaban).
-- A cikkeknel erre mar ott van a help_article.is_published; a fofejezeteknel
-- eddig nem volt semmi, pedig egy egesz fofejezetet elrejteni gyakoribb igeny,
-- mint egyesevel minden alfejezetet kikapcsolni.
--
-- Egy kikapcsolt fofejezet a nyilvanos oldalon ugy viselkedik, mintha nem
-- letezne: nincs a bal oldali menuben, nem talalhato meg keresessel, es a
-- benne levo fejezetek sem nyithatok meg - akkor sem, ha azok kulon-kulon
-- be vannak kapcsolva.

ALTER TABLE help_module
  ADD COLUMN is_published tinyint(1) NOT NULL DEFAULT 1 AFTER sort_order;

-- A nyilvanos nezetek: a cikk csak akkor latszik, ha a sajat kapcsoloja ES a
-- fofejezete kapcsoloja is be van kapcsolva.
CREATE OR REPLACE VIEW help_article_public AS
SELECT a.*, m.title AS module_title, m.chapter_no AS module_no, m.slug AS module_slug
  FROM help_article a
  LEFT JOIN help_module m ON m.id = a.module_id
 WHERE a.is_published = 1
   AND COALESCE(m.is_published, 1) = 1;

CREATE OR REPLACE VIEW help_whatsnew AS
SELECT a.lang, a.slug, a.chapter_no, a.title,
       m.chapter_no AS module_no, m.title AS module_title,
       a.change_flag, a.updated_at, a.highlight_until,
       (SELECT c.description FROM help_changelog c
         WHERE c.article_id = a.id AND c.is_minor = 0
         ORDER BY c.id DESC LIMIT 1) AS summary
  FROM help_article a
  JOIN help_module m ON m.id = a.module_id
 WHERE a.is_published = 1
   AND m.is_published = 1
   AND a.highlight_until IS NOT NULL
   AND a.highlight_until >= curdate();
