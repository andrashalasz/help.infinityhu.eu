-- ============================================================
-- 12 - Slug-tortenet: a regi cimek ne haljanak el
--
-- A fejezet URL-je (slug) eddig SOHA nem valtozott, mert a valtozasa minden
-- korabban kiadott hivatkozast eltorne: konyvjelzoket, e-mailben kikuldott
-- linkeket, jegyekbe masolt cimeket. Ezert maradt a "15-1-felhasznalok"
-- akkor is, amikor a fejezet mar 7.1 lett - ami viszont zavaro.
--
-- Ez a tabla feljegyzi a regi cimeket. A nyilvanos oldal, ha nem talal egy
-- slugot, ideneze, es 301-gyel atiranyit a mostanira. Igy a szam kovetheto
-- az URL-ben ANELKUL, hogy barmi eltorne.
--
-- A (slug, lang) az elsodleges kulcs: egy cim egyszerre csak egy fejezethez
-- tartozhat. Ha egy regi cimet kesobb ujra kioszt a rendszer egy mas
-- fejezetnek, az uj bejegyzes felulirja a regit (a mostani cim az eros).
-- ============================================================

CREATE TABLE IF NOT EXISTS help_slug_history (
  slug       VARCHAR(160) NOT NULL,
  lang       CHAR(5)      NOT NULL,
  article_id INT          NOT NULL,
  created_at DATETIME     NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (slug, lang),
  KEY help_slug_history_article (article_id),
  CONSTRAINT help_slug_history_fk FOREIGN KEY (article_id)
      REFERENCES help_article (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
