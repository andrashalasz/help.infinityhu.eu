<?php
/**
 * tests/futtat.php - a sugo tesztjei, kulso fuggoseg nelkul.
 *
 *   php tests/futtat.php
 *
 * Az eles frissito (deploy/sugo-frissito.py) MINDEN telepites elott lefuttatja,
 * halozat nelkul, a kert verzio tiszta peldanyan. Ha barmelyik teszt bukik,
 * a verzio nem kerul ki. Kilepesi kod: 0 = minden rendben, 1 = hiba.
 *
 * Uj teszt: egy t('nev', function () { ... }) hivas valamelyik tests/*_test.php
 * fajlban. Ellenorzes: eq(), ok(), no().
 */
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', 'stderr');
set_error_handler(static function (int $no, string $msg, string $file, int $line): bool {
    throw new ErrorException($msg, 0, $no, $file, $line);
});

const GYOKER = __DIR__ . '/..';

$GLOBALS['tesztek'] = [];

function t(string $nev, callable $fn): void { $GLOBALS['tesztek'][] = [$nev, $fn]; }

final class Bukas extends Exception {}

function eq(mixed $vart, mixed $kapott, string $mi = ''): void
{
    if ($vart !== $kapott) {
        throw new Bukas(($mi ? $mi . ': ' : '') . 'várt ' . var_export($vart, true) . ', kapott ' . var_export($kapott, true));
    }
}
function ok(mixed $v, string $mi = ''): void { if ($v !== true) { throw new Bukas($mi ?: 'igaznak kellett volna lennie'); } }
function no(mixed $v, string $mi = ''): void { if ($v !== false) { throw new Bukas($mi ?: 'hamisnak kellett volna lennie'); } }

// --- 1. szintaxis: minden PHP-fajl ---------------------------------------------
$hibak = 0;
$fajlok = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(GYOKER . '/site_dinamikus', FilesystemIterator::SKIP_DOTS));
$db = 0;
foreach ($fajlok as $f) {
    if ($f->getExtension() !== 'php') { continue; }
    $db++;
    $ki = [];
    exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($f->getPathname()) . ' 2>&1', $ki, $kod);
    if ($kod !== 0) {
        $hibak++;
        fwrite(STDOUT, "  HIBA  szintaxis: " . $f->getPathname() . "\n    " . implode("\n    ", $ki) . "\n");
    }
}
fwrite(STDOUT, "Szintaxis: {$db} PHP-fájl, " . ($hibak ? "{$hibak} hibás" : 'mind rendben') . "\n");

// --- 2. a tesztfajlok ---------------------------------------------------------
foreach (glob(__DIR__ . '/*_test.php') ?: [] as $f) { require $f; }

$jo = 0;
foreach ($GLOBALS['tesztek'] as [$nev, $fn]) {
    try {
        $fn();
        $jo++;
        fwrite(STDOUT, "  ok    {$nev}\n");
    } catch (Throwable $e) {
        $hibak++;
        $hol = $e instanceof Bukas ? '' : ' (' . get_class($e) . ' ' . basename($e->getFile()) . ':' . $e->getLine() . ')';
        fwrite(STDOUT, "  HIBA  {$nev}\n        " . $e->getMessage() . $hol . "\n");
    }
}

fwrite(STDOUT, "\n{$jo} teszt rendben, {$hibak} hiba.\n");
exit($hibak ? 1 : 0);
