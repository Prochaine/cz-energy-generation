<?php
declare(strict_types=1);
require __DIR__ . '/lib.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function row_out(array $r): array
{
    $v = [];
    foreach (SOURCES as $code => $col) {
        $v[$code] = $r[$col] === null ? null : round((float)$r[$col], 1);
    }
    return ['ts' => (int)$r['ts'], 'v' => $v];
}

try {
    $tz = new DateTimeZone('Europe/Prague');
    $pdo = db();
    switch ($_GET['a'] ?? '') {
        case 'sync':
            echo json_encode(sync_step());
            break;

        case 'latest': // poslední hodina s nenulovou výrobou + rozsah dostupných dat
            $cols = implode(' + ', array_map(fn($c) => "COALESCE($c,0)", SOURCES));
            $r = $pdo->query("SELECT * FROM generation WHERE ($cols) > 0 ORDER BY ts DESC LIMIT 1")->fetch();
            $range = $pdo->query('SELECT MIN(ts) AS a, MAX(ts) AS b FROM generation')->fetch();
            echo json_encode([
                'row'   => $r ? row_out($r) : null,
                'first' => $range['a'] ? (new DateTimeImmutable('@' . $range['a']))->setTimezone($tz)->format('Y-m-d') : null,
                'last'  => $range['b'] ? (new DateTimeImmutable('@' . $range['b']))->setTimezone($tz)->format('Y-m-d') : null,
            ]);
            break;

        case 'day':
            $d = $_GET['d'] ?? '';
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
                throw new InvalidArgumentException('Neplatné datum');
            }
            $from = new DateTimeImmutable($d . ' 00:00:00', $tz);
            $to = $from->modify('+1 day');
            $st = $pdo->prepare('SELECT * FROM generation WHERE ts >= :a AND ts < :b ORDER BY ts');
            $st->execute([':a' => $from->getTimestamp(), ':b' => $to->getTimestamp()]);
            $rows = [];
            foreach ($st as $r) {
                $o = row_out($r);
                $o['t'] = (new DateTimeImmutable('@' . $o['ts']))->setTimezone($tz)->format('H:i');
                $rows[] = $o;
            }
            echo json_encode(['date' => $d, 'rows' => $rows]);
            break;

        default:
            http_response_code(400);
            echo json_encode(['error' => 'Neznámá akce']);
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
