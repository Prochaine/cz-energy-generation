<?php
declare(strict_types=1);

date_default_timezone_set('Europe/Prague');

const SERVICE_URL = 'https://www.ceps.cz/_layouts/CepsData.asmx';
const SERVICE_NS  = 'https://www.ceps.cz/CepsData/';
const SD_NS       = 'https://www.ceps.cz/CepsData/StructuredData/1.0';
const START_DATE  = '2026-01-01';
const CHUNK_DAYS  = 31;   // kolik dnů se stáhne jedním voláním služby
const OVERLAP     = '-2 days'; // poslední data se stahují znovu (průběžné přepočty)

/** Kód série ve službě => sloupec v DB. */
const SOURCES = [
    'NPP'   => 'npp',
    'TPP'   => 'tpp',
    'CCGT'  => 'ccgt',
    'AltPP' => 'altpp',
    'ApPP'  => 'appp',
    'HPP'   => 'hpp',
    'PsPP'  => 'pspp',
    'WPP'   => 'wpp',
    'PVPP'  => 'pvpp',
];

function db(): PDO
{
    static $pdo = null;
    if ($pdo) {
        return $pdo;
    }
    $pdo = new PDO('sqlite:' . __DIR__ . '/data/ceps.sqlite');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA busy_timeout = 30000');
    $pdo->exec('PRAGMA journal_mode = WAL');
    $cols = implode(', ', array_map(fn($c) => "$c REAL", SOURCES));
    // ts = UNIX čas začátku hodiny (UTC) – jednoznačný i při změně času (25h den)
    $pdo->exec("CREATE TABLE IF NOT EXISTS generation (ts INTEGER PRIMARY KEY, $cols)");
    $pdo->exec('CREATE TABLE IF NOT EXISTS meta (k TEXT PRIMARY KEY, v TEXT)');
    return $pdo;
}

/** Zavolá metodu Generation (hodinový průměr výkonu, MW). Vrací [ts => [kód => MW]]. */
function fetch_generation(DateTimeImmutable $from, DateTimeImmutable $to): array
{
    $f = $from->format('Y-m-d\TH:i:s');
    $t = $to->format('Y-m-d\TH:i:s');
    $ns = SERVICE_NS;
    $body = '<?xml version="1.0" encoding="utf-8"?>'
        . '<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/"><soap:Body>'
        . "<Generation xmlns=\"$ns\"><dateFrom>$f</dateFrom><dateTo>$t</dateTo>"
        . '<agregation>HR</agregation><function>AVG</function><version>RT</version><para1>all</para1>'
        . '</Generation></soap:Body></soap:Envelope>';

    $ch = curl_init(SERVICE_URL);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 120,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: text/xml; charset=utf-8',
            'SOAPAction: "' . $ns . 'Generation"',
        ],
    ]);
    $cacert = 'C:/laragon/etc/ssl/cacert.pem';
    if (!ini_get('curl.cainfo') && is_file($cacert)) {
        curl_setopt($ch, CURLOPT_CAINFO, $cacert);
    }
    $resp = curl_exec($ch);
    if ($resp === false) {
        $e = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException("Webová služba ČEPS není dostupná: $e");
    }
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code !== 200) {
        throw new RuntimeException("Webová služba ČEPS vrátila HTTP $code");
    }

    $xml = simplexml_load_string($resp);
    if ($xml === false) {
        throw new RuntimeException('Neplatná odpověď webové služby');
    }
    $xml->registerXPathNamespace('s', SD_NS);
    $names = [];
    foreach ($xml->xpath('//s:serie') as $s) {
        $names[(string)$s['id']] = trim(explode('[', (string)$s['name'])[0]);
    }
    $out = [];
    foreach ($xml->xpath('//s:item') as $it) {
        $ts = (new DateTimeImmutable((string)$it['date']))->getTimestamp();
        $row = [];
        foreach ($names as $id => $name) {
            if (isset($it[$id])) {
                $row[$name] = (float)$it[$id];
            }
        }
        $out[$ts] = $row;
    }
    return $out;
}

/**
 * Jeden krok synchronizace: stáhne další úsek od posledního kurzoru do (maximálně) současnosti.
 * Vrací ['done' => bool, 'rows' => int, 'upto' => 'Y-m-d H:i'].
 */
function sync_step(): array
{
    $pdo = db();
    $tz = new DateTimeZone('Europe/Prague');
    $now = new DateTimeImmutable('now', $tz);

    $cur = $pdo->query("SELECT v FROM meta WHERE k = 'cursor'")->fetchColumn();
    $first = new DateTimeImmutable(START_DATE . ' 00:00:00', $tz);
    if ($cur === false) {
        $start = $first;
    } else {
        $start = (new DateTimeImmutable('@' . $cur))->setTimezone($tz)->modify(OVERLAP)->setTime(0, 0);
        if ($start < $first) {
            $start = $first;
        }
    }
    $end = $start->modify('+' . CHUNK_DAYS . ' days');
    if ($end > $now) {
        $end = $now;
    }

    $data = fetch_generation($start, $end);

    $cols = array_values(SOURCES);
    $sql = 'INSERT OR REPLACE INTO generation (ts, ' . implode(', ', $cols) . ') VALUES (:ts, :'
        . implode(', :', $cols) . ')';
    $pdo->beginTransaction();
    $st = $pdo->prepare($sql);
    $n = 0;
    foreach ($data as $ts => $row) {
        $p = [':ts' => $ts];
        foreach (SOURCES as $code => $col) {
            $p[":$col"] = $row[$code] ?? null;
        }
        $st->execute($p);
        $n++;
    }
    $pdo->prepare("INSERT OR REPLACE INTO meta (k, v) VALUES ('cursor', :v)")
        ->execute([':v' => (string)$end->getTimestamp()]);
    $pdo->commit();

    return ['done' => $end >= $now, 'rows' => $n, 'upto' => $end->format('Y-m-d H:i')];
}
