<?php

declare(strict_types=1);

// Tests ohne Abhängigkeiten:  php tests/run.php

use MacoMocks\App;
use MacoMocks\Catalog;
use MacoMocks\MockServer;
use MacoMocks\RepoSync;

require __DIR__ . '/../proxy/src/bootstrap.php';

const ROOT = __DIR__ . '/..';
$failures = 0;
$count = 0;

function test(string $name, callable $fn): void
{
    global $failures, $count;
    $count++;
    try {
        $fn();
        echo "ok   {$name}\n";
    } catch (Throwable $e) {
        $failures++;
        echo "FAIL {$name}\n     " . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ")\n";
    }
}

function eq(mixed $expected, mixed $actual, string $message = ''): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(($message !== '' ? $message . ': ' : '') . 'erwartet ' . var_export($expected, true) . ', erhalten ' . var_export($actual, true));
    }
}

function ok(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function req(string $method, string $path, array $query = [], array $headers = [], mixed $body = null): array
{
    return [
        'method' => $method,
        'path' => $path,
        'query' => $query,
        'headers' => array_change_key_case($headers, CASE_LOWER),
        'body' => $body === null ? '' : (is_string($body) ? $body : json_encode($body)),
    ];
}

function body(array $response): mixed
{
    return json_decode((string) $response['body'], true);
}

function tempDir(): string
{
    $dir = sys_get_temp_dir() . '/maco-mocks-test-' . bin2hex(random_bytes(4));
    mkdir($dir, 0777, true);
    return $dir;
}

/** Baut ein ZIP wie GitHub: <repo>-<sha>/mocks/..., Kommentar = Commit */
function githubZip(string $mocksDir, string $sha, array $extra = []): string
{
    $file = tempDir() . "/{$sha}.zip";
    $zip = new ZipArchive();
    $zip->open($file, ZipArchive::CREATE);
    $top = "maco-mocks-{$sha}/";
    $zip->addEmptyDir($top);
    $zip->addFromString($top . 'README.md', '# test');
    $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($mocksDir, FilesystemIterator::SKIP_DOTS));
    foreach ($items as $item) {
        $zip->addFile($item->getPathname(), $top . 'mocks/' . substr($item->getPathname(), strlen($mocksDir) + 1));
    }
    foreach ($extra as $name => $content) {
        $zip->addFromString($top . $name, $content);
    }
    $zip->setArchiveComment($sha);
    $zip->close();
    return $file;
}

$catalog = Catalog::fromDirectory(ROOT . '/mocks', true);
$server = new MockServer($catalog, 'test');
$mocks = ROOT . '/mocks';

// ---------------------------------------------------------------------------
// Katalog und Routing

test('Katalog: 28 Endpunkte ohne Fehler', function () use ($catalog) {
    eq([], $catalog->errors);
    eq(28, count($catalog->endpoints));
});

test('lesen: Standardantwort, fremde Parameter werden ignoriert', function () use ($server, $mocks) {
    $a = $server->handle(req('GET', '/getTrancheBasic', ['command' => 'LESEN_TRANCHE_BASIS', 'parameter1' => '1', 'egal' => 'x']));
    $b = $server->handle(req('GET', '/getTrancheBasic'));
    eq(200, $a['status']);
    eq($a['body'], $b['body']);
    eq('200.json', $a['headers']['X-Mock-Response']);
    eq('Standard', $a['headers']['X-Mock-Reason']);
    eq('application/json; charset=utf-8', $a['headers']['Content-Type']);
    eq(file_get_contents("{$mocks}/lesen/getTrancheBasic/quelle/200.json"), $a['body'], 'unveränderte Datei wird 1:1 ausgeliefert');
});

test('lesen: parameter1 wird gespiegelt, ohne parameter1 bleibt der Beispielwert', function () use ($server) {
    $with = body($server->handle(req('GET', '/getMaloidentMarketlocation', ['parameter1' => '12345678901'])));
    eq('12345678901', $with['stammdaten']['MARKTLOKATION'][0]['marktlokationsId']);
    $without = body($server->handle(req('GET', '/getMaloidentMarketlocation')));
    eq('57685676748', $without['stammdaten']['MARKTLOKATION'][0]['marktlokationsId']);
});

test('lesen: Regel je Parameter liefert eigene Antwortdatei', function () use ($server) {
    $r = $server->handle(req('GET', '/getMarketlocationBasic', ['parameter1' => '00000000000']));
    eq(200, $r['status']);
    eq('200-leer.json', $r['headers']['X-Mock-Response']);
    eq(['stammdaten' => ['MARKTLOKATION' => []]], body($r));
    ok(str_starts_with($r['headers']['X-Mock-Reason'], 'Regel: Unbekannte Marktlokation'), $r['headers']['X-Mock-Reason']);
});

test('Erwartungen: Variante je Parameter, unverändert ausgeliefert', function () use ($server, $mocks) {
    $r = $server->handle(req('GET', '/getMarketlocationBasic', ['parameter1' => '51000000001']));
    eq(200, $r['status']);
    eq('Erwartung: Malo STROM 51000000001 Test SAP pos. APERAK', $r['headers']['X-Mock-Reason']);
    eq('200-erwartung-malo-strom-51000000001-test-sap-pos-aperak.json', $r['headers']['X-Mock-Response']);
    eq(file_get_contents("{$mocks}/lesen/getMarketlocationBasic/quelle/200-erwartung-malo-strom-51000000001-test-sap-pos-aperak.json"), $r['body']);
    eq('51000000001', body($r)[0]['marktlokationsId']);
    // parameter2 statt parameter1
    eq('Erwartung: Malo 50754496666 GAS Zaehler', $server->handle(req('GET', '/getMarketlocationBasic', ['parameter2' => 'ZAEHLER']))['headers']['X-Mock-Reason'], 'Umlaute im Header umgeschrieben');
});

test('Erwartungen: Sammel-Erwartung zuletzt, Regeln und Testdaten davor', function () use ($server) {
    $reason = static fn (array $query): string => $server->handle(req('GET', '/getMarketlocationBasic', $query))['headers']['X-Mock-Reason'];
    $any = $server->handle(req('GET', '/getMarketlocationBasic', ['parameter1' => '99999999999']));
    eq('Erwartung: Default mit Platzhaltern', $any['headers']['X-Mock-Reason']);
    eq('#parameter1#', body($any)[0]['marktlokationsId'], 'kein set auf importierte Antworten');
    eq('Erwartung: Malo 50754496001', $reason(['parameter1' => '50754496001']), 'steht im API-Tool hinter dem Default');
    eq('Regel: Unbekannte Marktlokation: parameter1 = 00000000000', $reason(['parameter1' => '00000000000']));
    eq('Globale Regel: Testdaten parameter1=FEHLER400 -> 400', $reason(['parameter1' => 'FEHLER400']));
    eq('Standard', $reason([]));
});

test('Erwartungen: ungleich, Body-Felder, enthält nicht, ohne Bedingung', function () use ($server) {
    $reason = static fn (array $r): string => $server->handle($r)['headers']['X-Mock-Reason'];
    eq('Erwartung: Zuordnungsermaechtigung vorhanden', $reason(req('GET', '/getAllocationAuthorization', ['parameter4' => '11Y0-0000-0076-N'])));
    eq('Erwartung: Zuordnungsermaechtigung nicht vorhanden', $reason(req('GET', '/getAllocationAuthorization', ['parameter4' => 'anders'])));
    eq('Erwartung: Zuordnungsermaechtigung nicht vorhanden', $reason(req('GET', '/getAllocationAuthorization')));
    eq('Erwartung: marktlokationsId = 10002137935', $reason(req('POST', '/identifyLocation', [], [], ['marktlokationsId' => '10002137935'])));
    eq('Erwartung: Default mit Platzhaltern', $reason(req('POST', '/identifyLocation', [], [], ['boTyp' => 'MARKTLOKATION'])));
    eq('Regel: Identifikation per Marktlokations-ID -> eine Marktlokation', $reason(req('POST', '/identifyLocation', [], [], ['stammdaten' => ['MARKTLOKATION' => [['marktlokationsId' => '10002137935']]]])));
    eq('Erwartung: MaloIdent Mock', $reason(req('POST', '/identifyMarketlocation', [], [], ['malo' => 'abc'])));
    eq('Standard', $reason(req('POST', '/identifyMarketlocation', [], [], ['malo' => 'xyz'])));
    $price = $server->handle(req('GET', '/getPriceSheetBasic'));
    eq('200-erwartung-default-2.json', $price['headers']['X-Mock-Response'], 'Erwartung ohne Bedingung greift immer');
    eq('200-erwartung-default.json', $server->handle(req('GET', '/getPriceSheetBasic', ['parameter3' => '1']))['headers']['X-Mock-Response']);
    eq('Erwartung: Malo 50754497777', $reason(req('GET', '/getPriceSheetBasic', ['parameter1' => '50754497777'])));
});

test('Auswahl per Header/Query nach Status oder Dateiname', function () use ($server) {
    eq(422, $server->handle(req('GET', '/getAvisBasic', [], ['X-Mock-Response' => '422']))['status']);
    eq(400, $server->handle(req('GET', '/getAvisBasic', ['__response' => '400']))['status']);
    $named = $server->handle(req('POST', '/identifyLocation', [], ['X-Mock-Response' => '200-eine-marktlokation'], '{}'));
    eq(1, count(body($named)));
    eq(200, $server->handle(req('GET', '/getAvisBasic', ['__response' => '999']))['status'], 'unbekannte Auswahl wird ignoriert');
});

test('globale Testdaten-Regeln nur, wenn der Endpunkt die Datei hat', function () use ($server) {
    eq(400, $server->handle(req('GET', '/getAvisBasic', ['parameter1' => 'FEHLER400']))['status']);
    eq(422, $server->handle(req('GET', '/getAvisBasic', ['parameter1' => 'FEHLER422']))['status']);
    eq(200, $server->handle(req('GET', '/getAccountingBasic', ['parameter1' => 'FEHLER422']))['status']);
});

test('aktualisieren: 03002/03003 -> 201 {}, Body ohne transaktionsdaten -> 400', function () use ($server, $mocks) {
    foreach (['anfrage-03002.json', 'anfrage-03003.json'] as $example) {
        $r = $server->handle(req('POST', '/updateProcessData', [], [], (string) file_get_contents("{$mocks}/aktualisieren/updateProcessData/quelle/{$example}")));
        eq(201, $r['status'], $example);
        eq("{}\n", $r['body'], 'leeres Objekt bleibt {}');
    }
    eq(400, $server->handle(req('POST', '/updateProcessData', [], [], ['x' => 1]))['status']);
    eq(400, $server->handle(req('POST', '/updateProcessData', [], [], 'kein json'))['status']);
});

test('identifyLocation: Varianten je Merkmal und Paging über Treffer-Header', function () use ($server) {
    $byId = $server->handle(req('POST', '/identifyLocation', [], [], ['stammdaten' => ['MARKTLOKATION' => [['marktlokationsId' => '90131677420']]]]));
    eq(1, count(body($byId)));
    eq('1', $byId['headers']['Treffer-Gesamtanzahl']);

    $byAddress = $server->handle(req('POST', '/identifyLocation', [], [], ['stammdaten' => ['MARKTLOKATION' => [['lokationsadresse' => ['ort' => 'Berlin']]]]]));
    eq(2, count(body($byAddress)));
    eq('false', $byAddress['headers']['Treffer-Weitere-Vorhanden']);

    $none = $server->handle(req('POST', '/identifyLocation', [], [], ['stammdaten' => ['MARKTLOKATION' => [['marktlokationsId' => '00000000000']]]]));
    eq([], body($none));
    eq('0', $none['headers']['Treffer-Gesamtanzahl']);

    $page1 = $server->handle(req('POST', '/identifyLocation', [], ['Treffer-Max-Anzahl' => '1'], '{}'));
    eq(1, count(body($page1)));
    eq('true', $page1['headers']['Treffer-Weitere-Vorhanden']);
    $page2 = $server->handle(req('POST', '/identifyLocation', [], ['Treffer-Max-Anzahl' => '1', 'Treffer-Offset' => '1'], '{}'));
    eq('50754497001', body($page2)[0]['marktlokationsId']);
    eq('false', $page2['headers']['Treffer-Weitere-Vorhanden']);
    $behind = $server->handle(req('POST', '/identifyLocation', [], ['Treffer-Offset' => '50'], '{}'));
    eq([], body($behind));
    eq('2', $behind['headers']['Treffer-Gesamtanzahl']);
    foreach ([['Treffer-Max-Anzahl' => '0'], ['Treffer-Max-Anzahl' => '501'], ['Treffer-Max-Anzahl' => 'zehn'], ['Treffer-Offset' => '10001']] as $bad) {
        eq(400, $server->handle(req('POST', '/identifyLocation', [], $bad, '{}'))['status'], json_encode($bad));
    }
});

test('generischer Endpunkt über command, auch mit Präfix', function () use ($server) {
    eq('lesen/getGridLocationBasic', $server->handle(req('GET', '/backend/generic', ['command' => 'LESEN_NETZLOKATION_BASIS']))['headers']['X-Mock-Endpoint']);
    eq('lesen/getGridLocationBasic', $server->handle(req('GET', '/', ['command' => 'SAP_LESEN_NETZLOKATION_BASIS']))['headers']['X-Mock-Endpoint']);
    eq(400, $server->handle(req('POST', '/generic', ['command' => 'AKTUALISIEREN_PROZESSDATEN'], [], '{}'))['status']);
});

test('gleicher Pfad mehrfach: command entscheidet', function () use ($server) {
    eq('lesen/identifyMarketlocation', $server->handle(req('POST', '/identifyMarketlocation', [], [], '{}'))['headers']['X-Mock-Endpoint']);
    eq('lesen/identifyMarketlocation.LESEN_LOKATIONIDENT_BASIS', $server->handle(req('POST', '/identifyMarketlocation', ['command' => 'LESEN_LOKATIONIDENT_BASIS'], [], '{}'))['headers']['X-Mock-Endpoint']);
});

test('HTTP-Grundverhalten: .json, Slash, HEAD, 404, 405, OPTIONS', function () use ($server) {
    eq(200, $server->handle(req('GET', '/getTrancheBasic.json'))['status']);
    eq(200, $server->handle(req('GET', '/getTrancheBasic/'))['status']);
    $head = $server->handle(req('HEAD', '/getTrancheBasic'));
    eq(200, $head['status']);
    eq(null, $head['body']);
    eq(404, $server->handle(req('GET', '/gibtsnicht'))['status']);
    $wrong = $server->handle(req('POST', '/getTrancheBasic', [], [], '{}'));
    eq(405, $wrong['status']);
    eq('GET, HEAD', $wrong['headers']['Allow']);
    $pre = $server->handle(req('OPTIONS', '/updateProcessData', [], ['Access-Control-Request-Headers' => 'content-type,x-mock-response']));
    eq(204, $pre['status']);
    eq('content-type,x-mock-response', $pre['headers']['Access-Control-Allow-Headers']);
});

test('Regel-Syntax: Operatoren, Inline-Antwort, Platzhalter, Pfadparameter', function () {
    $dir = tempDir() . '/mocks';
    mkdir("{$dir}/demo/item", 0777, true);
    file_put_contents("{$dir}/demo/item/200.json", json_encode(['id' => '{{path.id}}', 'name' => '{{body.name|unbekannt}}', 'text' => 'Hallo {{query.wer}}!', 'offen' => '{{query.fehlt}}', 'leer' => new stdClass()]));
    file_put_contents("{$dir}/demo/item/202-gross.json", '{"gross": true}');
    file_put_contents("{$dir}/demo/item/mock.json", json_encode([
        'method' => 'POST',
        'path' => '/items/{id}',
        'rules' => [
            ['name' => 'Menge > 10', 'when' => ['body.menge' => ['gt' => 10]], 'then' => ['file' => '202-gross.json', 'headers' => ['X-Menge' => '{{body.menge}}']]],
            ['name' => 'Kanal', 'when' => ['header.x-kanal' => ['regex' => '^EDI'], 'query.typ' => ['A', 'B']], 'then' => ['status' => 418, 'body' => ['tee' => true]]],
            ['name' => 'Nicht', 'when' => ['query.typ' => ['not' => 'Z'], 'query.x' => ['exists' => true]], 'then' => ['status' => 204]],
        ],
    ]));
    $c = Catalog::fromDirectory($dir, true);
    eq([], $c->errors);
    $s = new MockServer($c);
    $ok = body($s->handle(req('POST', '/items/42', ['wer' => 'Welt'], [], ['menge' => 3])));
    eq(['id' => '42', 'name' => 'unbekannt', 'text' => 'Hallo Welt!', 'offen' => '{{query.fehlt}}', 'leer' => []], $ok);
    ok(str_contains((string) $s->handle(req('POST', '/items/1', [], [], '{}'))['body'], '"leer": {}'), 'leeres Objekt bleibt {} auch nach Platzhaltern');
    $big = $s->handle(req('POST', '/items/1', [], [], ['menge' => 11]));
    eq(202, $big['status']);
    eq('11', $big['headers']['X-Menge']);
    eq(418, $s->handle(req('POST', '/items/1', ['typ' => 'B'], ['X-Kanal' => 'EDIFACT'], '{}'))['status']);
    eq(200, $s->handle(req('POST', '/items/1', ['typ' => 'C'], ['X-Kanal' => 'EDIFACT'], '{}'))['status']);
    $noContent = $s->handle(req('POST', '/items/1', ['x' => '1'], [], '{}'));
    eq(204, $noContent['status']);
    eq(null, $noContent['body']);
});

test('Validierung meldet kaputte Regeln und Dateien', function () {
    $dir = tempDir() . '/mocks';
    mkdir("{$dir}/g/a", 0777, true);
    file_put_contents("{$dir}/g/a/200.json", '{kaputt');
    file_put_contents("{$dir}/g/a/mock.json", json_encode(['method' => 'GET', 'path' => 'ohne-slash', 'rules' => [
        ['name' => 'r1', 'when' => ['cookie.x' => 'y'], 'then' => '200.json'],
        ['name' => 'r2', 'when' => ['query.x' => ['ungefaehr' => 1]], 'then' => '200.json'],
        ['name' => 'r3', 'when' => ['query.x' => 'y'], 'then' => '404-gibtsnicht.json'],
    ]]));
    $errors = implode("\n", Catalog::fromDirectory($dir, true)->errors);
    foreach (['kein gültiges JSON', 'path muss', 'unbekannte Quelle', 'unbekannter Operator', 'existiert nicht'] as $needle) {
        ok(str_contains($errors, $needle), "Meldung fehlt: {$needle}\n{$errors}");
    }
});

test('Übersicht: jede Variante verlinkt, POST mit Senden, nur unerreichbare per Auswahl', function () use ($catalog) {
    $config = require ROOT . '/proxy/config.php';
    $overview = new MacoMocks\Overview($catalog, 'test', $config);
    $html = $overview->html();
    ok(str_contains($html, 'href="/getMarketlocationBasic?parameter1=51000000001"'), 'GET-Variante als Link');
    ok(str_contains($html, 'href="/getMarketlocationBasic?parameter2=ZAEHLER"'), 'Variante über parameter2');
    ok(str_contains($html, 'data-url="/identifyLocation" data-headers="{&quot;content-type&quot;:&quot;application/json&quot;}" data-body="{&quot;marktlokationsId&quot;:&quot;10002137935&quot;}"'), 'POST-Variante mit Body');
    $forced = [];
    $count = 0;
    foreach ($catalog->endpoints as $ep) {
        foreach ($overview->variants($ep) as $v) {
            $count++;
            if ($v['forced'] !== null) {
                $forced[] = $ep['id'] . ' ' . $v['name'];
            }
        }
    }
    eq(124, $count, 'Varianten');
    eq(['lesen/getAllocationAuthorization Standard', 'lesen/getPriceSheetBasic Standard', 'lesen/identifyMarketlocation Standard'], $forced);
});

test('Import: Erwartungen, Reihenfolge, JSON-Reparatur, ohne --erwartungen bleiben sie stehen', function () {
    $dir = tempDir();
    $openapi = ['openapi' => '3.0.1', 'paths' => ['/getX' => ['get' => [
        'operationId' => 'LESEN_X', 'tags' => ['LESEN | READ'], 'summary' => 'X lesen',
        'parameters' => [['in' => 'query', 'name' => 'parameter1', 'schema' => ['type' => 'string']]],
        'responses' => ['200' => ['content' => ['application/json' => ['example' => ['id' => 'beispiel']]]]],
    ]]]];
    file_put_contents("{$dir}/openapi.json", json_encode($openapi));
    $api = ['id' => 1, 'name' => 'X lesen', 'method' => 'GET', 'path' => '/getX', 'operationId' => 'LESEN_X'];
    $expectation = static fn (int $id, int $ordering, string $name, array $conditions, string $body): array => [
        'id' => $id, 'name' => $name, 'ordering' => $ordering, 'api' => $api, 'conditions' => $conditions,
        'response' => ['code' => 200, 'delay' => 0, 'headers' => [], 'bodyType' => 'json', 'bodyData' => $body],
    ];
    file_put_contents("{$dir}/erwartungen.json", json_encode(['erwartungen' => [
        $expectation(1, 10, 'Default', [['location' => 'query', 'name' => 'parameter1', 'value' => 'true', 'comparison' => 'exists']], '{"id": "#parameter1#"}'),
        $expectation(2, 20, 'Malo 1', [['location' => 'query', 'name' => 'parameter1', 'value' => '1', 'comparison' => 'equal']], "{\n \"a\": {\"b\": 1}\n \"c\": [1, 2,],\n // Kommentar\n}"),
        $expectation(3, 20, 'Malo 2 neuer', [['location' => 'query', 'name' => 'parameter1', 'value' => '2', 'comparison' => 'equal']], '{"id": "2"}'),
        $expectation(4, 5, 'Immer', [], '{"immer": true}'),
        $expectation(5, 1, 'Kaputt', [['location' => 'cookie', 'name' => 'x', 'value' => '1', 'comparison' => 'equal']], '{}'),
    ]]));
    $run = static function (string $args) use ($dir): string {
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(ROOT . '/tools/import.php') . ' ' . escapeshellarg("{$dir}/openapi.json") . " --mocks {$dir}/mocks {$args} 2>&1", $out, $code);
        eq(0, $code, implode("\n", $out));
        return implode("\n", $out);
    };
    $log = $run('--erwartungen ' . escapeshellarg("{$dir}/erwartungen.json"));
    ok(str_contains($log, '4 übernommen'), $log);
    ok(str_contains($log, '„Kaputt“ nicht übernommen (Ort cookie nicht unterstützt)'), $log);
    ok(str_contains($log, '„Malo 1“: Antwort repariert (fehlendes Komma ergänzt, Komma am Ende entfernt, Kommentare entfernt)'), $log);
    $rules = json_decode((string) file_get_contents("{$dir}/mocks/lesen/getX/quelle/erwartungen.json"), true)['erwartungen'];
    eq(['Malo 2 neuer', 'Malo 1', 'Default', 'Immer'], array_column($rules, 'name'), 'konkret (neuere zuerst), vorhanden, ohne Bedingung');
    eq(['a' => ['b' => 1], 'c' => [1, 2]], json_decode((string) file_get_contents("{$dir}/mocks/lesen/getX/quelle/200-erwartung-malo-1.json"), true));

    $c = Catalog::fromDirectory("{$dir}/mocks", true);
    eq([], $c->errors);
    eq('200.json', $c->endpoints[0]['default'], 'Erwartungsdatei wird nie Standard');
    $s = new MockServer($c);
    eq('Erwartung: Malo 2 neuer', $s->handle(req('GET', '/getX', ['parameter1' => '2']))['headers']['X-Mock-Reason']);
    eq('Erwartung: Default', $s->handle(req('GET', '/getX', ['parameter1' => '9']))['headers']['X-Mock-Reason']);
    eq('Erwartung: Immer', $s->handle(req('GET', '/getX'))['headers']['X-Mock-Reason']);

    $run('');
    ok(is_file("{$dir}/mocks/lesen/getX/quelle/erwartungen.json") && is_file("{$dir}/mocks/lesen/getX/quelle/200-erwartung-default.json"), 'Import ohne --erwartungen lässt sie stehen');
});

// ---------------------------------------------------------------------------
// Lokale Repo-Kopie und Update

function syncConfig(string $dataDir, string $zip, int $interval = 0): array
{
    $config = require ROOT . '/proxy/config.php';
    return array_replace($config, [
        'dataDir' => $dataDir,
        'mocksDir' => null,
        'archiveUrl' => 'file://' . $zip,
        'shaUrl' => '',
        'minUpdateInterval' => $interval,
        'keepReleases' => 2,
    ]);
}

test('Update: laden, prüfen, umschalten, unverändert erkennen, aufräumen', function () use ($mocks) {
    $data = tempDir();
    $sha1 = str_repeat('a', 40);
    $sync = new RepoSync(syncConfig($data, githubZip($mocks, $sha1)));
    $r = $sync->update('test');
    eq('updated', $r['status'], $r['message']);
    eq($sha1, $sync->current()['sha']);
    eq(28, count($sync->catalog()->endpoints));
    ok(!is_dir("{$data}/releases/{$sha1}/proxy") && is_file("{$data}/releases/{$sha1}/mocks/_global.json"), 'nur mocks/ wird übernommen');
    ok(is_file("{$data}/.htaccess"), 'data/ ist gegen Webzugriff geschützt');
    eq('unchanged', $sync->update('test')['status']);

    foreach (['b', 'c', 'd'] as $char) {
        $sha = str_repeat($char, 40);
        $s = new RepoSync(syncConfig($data, githubZip($mocks, $sha)));
        eq('updated', $s->update('test')['status']);
        touch("{$data}/releases/{$sha}", time() + ord($char)); // Reihenfolge für das Aufräumen
    }
    $left = array_values(array_filter(scandir("{$data}/releases"), static fn ($e) => $e[0] !== '.'));
    eq(2, count($left), 'keepReleases=2');
    ok(in_array(str_repeat('d', 40), $left, true), 'aktueller Stand bleibt');
});

test('Update: Index einer älteren Proxy-Version wird neu aufgebaut', function () use ($mocks) {
    $data = tempDir();
    $sha = str_repeat('8', 40);
    $sync = new RepoSync(syncConfig($data, githubZip($mocks, $sha)));
    eq('updated', $sync->update('test')['status']);
    $indexFile = "{$data}/releases/{$sha}/index.json";
    $old = json_decode((string) file_get_contents($indexFile), true);
    unset($old['format']);
    foreach ($old['endpoints'] as &$ep) {
        unset($ep['expectations']);
    }
    unset($ep);
    file_put_contents($indexFile, json_encode($old));
    $catalog = $sync->catalog();
    ok(count($catalog->endpoints[array_search('lesen/getMarketlocationBasic', array_column($catalog->endpoints, 'id'), true)]['expectations']) > 20, 'Erwartungen wieder da');
    eq(Catalog::FORMAT, json_decode((string) file_get_contents($indexFile), true)['format'], 'Index neu gespeichert');
});

test('Update: fehlerhafter Stand wird abgelehnt, alter bleibt aktiv', function () use ($mocks) {
    $data = tempDir();
    $good = str_repeat('1', 40);
    (new RepoSync(syncConfig($data, githubZip($mocks, $good))))->update('test');
    $bad = str_repeat('2', 40);
    $sync = new RepoSync(syncConfig($data, githubZip($mocks, $bad, ['mocks/lesen/getTrancheBasic/mock.json' => '{kaputt'])));
    $r = $sync->update('test');
    eq('error', $r['status']);
    ok(str_contains($r['message'], 'getTrancheBasic'), $r['message']);
    eq($good, $sync->current()['sha']);
    ok(!is_dir("{$data}/releases/{$bad}"), 'kein Release-Ordner für fehlerhaften Stand');
});

test('Update: Pfade mit .. im Archiv werden abgelehnt', function () use ($mocks) {
    $data = tempDir();
    $sha = str_repeat('e', 40);
    $sync = new RepoSync(syncConfig($data, githubZip($mocks, $sha, ['mocks/../../evil.json' => '{}'])));
    $r = $sync->update('test');
    eq('error', $r['status']);
    ok(str_contains($r['message'], 'Unzulässiger Pfad'), $r['message']);
    ok(!file_exists(dirname($data) . '/evil.json'), 'nichts außerhalb geschrieben');
});

test('Update: Drossel und Nachholen', function () use ($mocks) {
    $data = tempDir();
    $sha = str_repeat('f', 40);
    $sync = new RepoSync(syncConfig($data, githubZip($mocks, $sha), 60));
    eq('updated', $sync->update('test')['status']);
    eq('skipped', $sync->update('test')['status'], 'innerhalb der Drossel');
    $sync->markPending();
    ok($sync->isPending(), 'Merker gesetzt');
    touch("{$data}/pending", time() + 5); // so, als wäre er während des Updates gesetzt worden
    eq('updated', $sync->update('test', true)['status']);
    ok($sync->isPending(), 'später gesetzter Merker bleibt stehen');
    touch("{$data}/pending", time() - 5); // vor dem Update gesetzt
    eq('updated', $sync->update('test', true)['status']);
    ok(!$sync->isPending(), 'erledigter Merker wird entfernt');
});

// ---------------------------------------------------------------------------
// Front-Controller mit Repo-Kopie und Webhook

test('App: erster Aufruf lädt die Kopie, Status und Übersicht', function () use ($mocks) {
    $data = tempDir();
    $sha = str_repeat('9', 40);
    $app = new App(syncConfig($data, githubZip($mocks, $sha)));
    $r = $app->handle(req('GET', '/getTrancheBasic', ['parameter1' => 'x']));
    eq(200, $r['status']);
    eq('9999999', $r['headers']['X-Mock-Version']);
    $status = body($app->handle(req('GET', '/_status')));
    eq($sha, $status['commit']);
    eq(28, $status['endpunkte']);
    $html = $app->handle(req('GET', '/'));
    ok(str_contains((string) $html['body'], '/getTrancheBasic'), 'Übersicht listet Endpunkte');
    eq(28, count(body($app->handle(req('GET', '/_mocks')))['endpunkte']));
});

test('App: Webhook mit Secret, ping, fremder Branch, Push auf main', function () use ($mocks) {
    $data = tempDir();
    $sha = str_repeat('7', 40);
    $config = syncConfig($data, githubZip($mocks, $sha)) + [];
    $config['updateSecret'] = 'geheim';
    $app = new App($config);
    $push = json_encode(['ref' => 'refs/heads/main', 'repository' => ['full_name' => 'conuti-das/maco-mocks']]);
    $sign = static fn (string $body): string => 'sha256=' . hash_hmac('sha256', $body, 'geheim');

    eq(403, $app->handle(req('POST', '/_update', [], ['X-GitHub-Event' => 'push'], $push))['status']);
    eq(403, $app->handle(req('POST', '/_update', [], ['X-GitHub-Event' => 'push', 'X-Hub-Signature-256' => 'sha256=falsch'], $push))['status']);
    eq('pong', body($app->handle(req('POST', '/_update', [], ['X-GitHub-Event' => 'ping', 'X-Hub-Signature-256' => $sign('{}')], '{}')))['status']);
    $other = json_encode(['ref' => 'refs/heads/feature']);
    eq(202, $app->handle(req('POST', '/_update', [], ['X-GitHub-Event' => 'push', 'X-Hub-Signature-256' => $sign($other)], $other))['status']);
    $r = $app->handle(req('POST', '/_update', [], ['X-GitHub-Event' => 'push', 'X-Hub-Signature-256' => $sign($push)], $push));
    eq(200, $r['status']);
    eq('updated', body($r)['status']);
    eq(200, $app->handle(req('POST', '/_update', [], ['Authorization' => 'Bearer geheim']))['status'], 'manuell mit Token');
});

test('App: Webhook ohne Secret wird gedrosselt und nachgeholt', function () use ($mocks) {
    $data = tempDir();
    $config = syncConfig($data, githubZip($mocks, str_repeat('3', 40)), 1);
    $app = new App($config);
    $push = json_encode(['ref' => 'refs/heads/main']);
    eq('updated', body($app->handle(req('POST', '/_update', [], ['X-GitHub-Event' => 'push'], $push)))['status']);
    // neuer Commit, zweiter Push sofort hinterher -> gedrosselt, Merker gesetzt
    $config['archiveUrl'] = 'file://' . githubZip($mocks, str_repeat('4', 40));
    $app = new App($config);
    $r = $app->handle(req('POST', '/_update', [], ['X-GitHub-Event' => 'push'], $push));
    eq(202, $r['status']);
    sleep(2);
    $mock = $app->handle(req('GET', '/getTrancheBasic'));
    eq('4444444', $mock['headers']['X-Mock-Version'], 'nächste Anfrage holt den Stand nach');
});

// ---------------------------------------------------------------------------
// Echter HTTP-Durchlauf über index.php (PHP-Webserver, Entwicklungsmodus)

test('HTTP: index.php im PHP-Webserver', function () use ($mocks) {
    $port = random_int(20000, 40000);
    $cmd = sprintf('MOCKS_DIR=%s exec %s -S 127.0.0.1:%d %s', escapeshellarg($mocks), escapeshellarg(PHP_BINARY), $port, escapeshellarg(ROOT . '/proxy/index.php'));
    $proc = proc_open($cmd, [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
    try {
        $fetch = static function (string $method, string $path, array $headers = [], ?string $body = null) use ($port): array {
            $context = stream_context_create(['http' => ['method' => $method, 'header' => $headers, 'content' => $body ?? '', 'ignore_errors' => true, 'timeout' => 5]]);
            $content = @file_get_contents("http://127.0.0.1:{$port}{$path}", false, $context);
            $status = isset($http_response_header[0]) ? (int) explode(' ', $http_response_header[0])[1] : 0;
            return [$status, (string) $content, implode("\n", $http_response_header ?? [])];
        };
        for ($i = 0; $i < 50 && $fetch('GET', '/_status')[0] !== 200; $i++) {
            usleep(100000);
        }
        [$status, $content, $headers] = $fetch('GET', '/getMarketlocationBasic?parameter1=51000000001&command=LESEN_MARKTLOKATION_BASIS&a.b=1');
        eq(200, $status);
        eq('51000000001', json_decode($content, true)[0]['marktlokationsId']);
        ok(str_contains($headers, 'X-Mock-Reason: Erwartung: Malo STROM 51000000001'), $headers);
        [$status, $html] = $fetch('GET', '/');
        eq(200, $status);
        ok(str_contains($html, 'href="/getMarketlocationBasic?parameter1=51000000001"'), 'Übersicht verlinkt Varianten');
        [$status, , $headers] = $fetch('POST', '/identifyLocation', ['Content-Type: application/json', 'Treffer-Max-Anzahl: 1'], '{}');
        eq(200, $status);
        ok(str_contains($headers, 'Treffer-Weitere-Vorhanden: true'), $headers);
        eq(400, $fetch('POST', '/updateProcessData', ['Content-Type: application/json'], '{}')[0]);
        eq(200, $fetch('POST', '/_update')[0], 'Entwicklungsmodus meldet "lokal"');
    } finally {
        proc_terminate($proc);
        proc_close($proc);
    }
});

echo "\n{$count} Tests, " . ($count - $failures) . ' ok, ' . $failures . " fehlgeschlagen\n";
exit($failures > 0 ? 1 : 0);
