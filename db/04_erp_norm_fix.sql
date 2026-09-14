-- ============================================================
-- erp_norm() javitas: sema-minositett unaccent
--
-- A 01_help_articles_i18n.sql igy definialja:
--     SELECT lower(unaccent(coalesce(txt, '')));
-- Az unaccent itt nincs sema-minositve, ezert az autovacuum/ANALYZE
-- munkafolyamat (ami ures search_path-tal fut) nem talalja meg, es a
-- help_article / help_section tablak automatikus analizise elhasal:
--     ERROR: function unaccent(text) does not exist
-- Ez azert jon elo, mert az erp_norm(title) kifejezes indexben is
-- szerepel (help_article_trgm_idx, help_section_trgm_idx).
--
-- A ketargumentumos unaccent(regdictionary, text) ezen felul valodi
-- IMMUTABLE fuggveny - pont ez a Postgres altal ajanlott forma, ha az
-- unaccent eredmenye indexbe kerul.
--
-- Ugyanez a javitas az eles Infinity adatbazisban is ervenyes, ott is
-- erdemes egyszer lefuttatni.
-- ============================================================

CREATE OR REPLACE FUNCTION erp_norm(txt text) RETURNS text AS $$
  SELECT lower(public.unaccent('public.unaccent'::regdictionary, coalesce(txt, '')));
$$ LANGUAGE sql IMMUTABLE;
