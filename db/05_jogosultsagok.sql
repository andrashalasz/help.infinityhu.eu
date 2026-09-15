-- ============================================================
-- Csak a Docker kornyezethez: adatbazis-felhasznalok.
--
--   help_ro - a NYILVANOS oldal (index.php). Csak olvas.
--   help_rw - az ADMIN felulet (admin.php). Ir es olvas.
--
-- Eles szerveren ugyanezt kezzel hozd letre, SAJAT jelszoval - lasd TELEPITES.md.
--
-- FIGYELEM: a jelszavak itt szandekosan fejlesztoi ertekek. Eles
-- kornyezetbe ne ez a fajl keruljon.
-- ============================================================

CREATE USER IF NOT EXISTS 'help_ro'@'%' IDENTIFIED BY 'help_ro';
CREATE USER IF NOT EXISTS 'help_rw'@'%' IDENTIFIED BY 'help_rw';

-- olvaso: a nyilvanos oldal
GRANT SELECT ON help_infinityhu.* TO 'help_ro'@'%';

-- iro: az admin felulet (a tarolt eljarasok futtatasaval egyutt)
GRANT SELECT, INSERT, UPDATE, DELETE ON help_infinityhu.* TO 'help_rw'@'%';
GRANT EXECUTE ON help_infinityhu.* TO 'help_rw'@'%';

FLUSH PRIVILEGES;
