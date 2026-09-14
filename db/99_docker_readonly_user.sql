-- ============================================================
-- Csak a Docker kornyezethez: olvaso (read-only) szerepkor a
-- site_dinamikus/index.php szamara.
--
-- Eles szerveren ugyanezt kezzel hozzatok letre, sajat jelszoval:
--   CREATE ROLE help_ro LOGIN PASSWORD '...';
--
-- FIGYELEM: a jelszo itt szandekosan fejlesztoi ertek. Eles
-- kornyezetbe ne ez a fajl keruljon.
-- ============================================================

DO $$
BEGIN
  IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'help_ro') THEN
    CREATE ROLE help_ro LOGIN PASSWORD 'help_ro';
  END IF;
END
$$;

GRANT CONNECT ON DATABASE help_infinityhu TO help_ro;
GRANT USAGE  ON SCHEMA public TO help_ro;
GRANT SELECT ON ALL TABLES    IN SCHEMA public TO help_ro;
GRANT SELECT ON ALL SEQUENCES IN SCHEMA public TO help_ro;

-- a kesobb letrehozott tablakra is
ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT SELECT ON TABLES    TO help_ro;
ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT SELECT ON SEQUENCES TO help_ro;
