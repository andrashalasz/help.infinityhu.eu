-- ============================================================
-- Csak a Docker kornyezethez: adatbazis-szerepkorok.
--
--   help_ro - a NYILVANOS oldal (index.php). Csak olvas.
--   help_rw - az ADMIN felulet (admin.php). Ir es olvas.
--
-- Eles szerveren ugyanezt kezzel hozzatok letre, sajat jelszoval:
--   CREATE ROLE help_ro LOGIN PASSWORD '...';
--   CREATE ROLE help_rw LOGIN PASSWORD '...';
--
-- FIGYELEM: a jelszavak itt szandekosan fejlesztoi ertekek. Eles
-- kornyezetbe ne ez a fajl keruljon.
-- ============================================================

DO $$
BEGIN
  IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'help_ro') THEN
    CREATE ROLE help_ro LOGIN PASSWORD 'help_ro';
  END IF;
  IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'help_rw') THEN
    CREATE ROLE help_rw LOGIN PASSWORD 'help_rw';
  END IF;
END
$$;

-- ---------- olvaso ----------
GRANT CONNECT ON DATABASE help_infinityhu TO help_ro;
GRANT USAGE  ON SCHEMA public TO help_ro;
GRANT SELECT ON ALL TABLES    IN SCHEMA public TO help_ro;
GRANT SELECT ON ALL SEQUENCES IN SCHEMA public TO help_ro;
ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT SELECT ON TABLES    TO help_ro;
ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT SELECT ON SEQUENCES TO help_ro;

-- ---------- iro (admin felulet) ----------
GRANT CONNECT ON DATABASE help_infinityhu TO help_rw;
GRANT USAGE  ON SCHEMA public TO help_rw;
GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES    IN SCHEMA public TO help_rw;
GRANT USAGE, SELECT, UPDATE          ON ALL SEQUENCES IN SCHEMA public TO help_rw;
GRANT EXECUTE ON ALL FUNCTIONS IN SCHEMA public TO help_rw;
ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES    TO help_rw;
ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT USAGE, SELECT, UPDATE          ON SEQUENCES TO help_rw;
ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT EXECUTE                        ON FUNCTIONS TO help_rw;
