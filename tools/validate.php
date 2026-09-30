<?php

declare(strict_types=1);

// Prüft den mocks/-Ordner so, wie der Proxy ihn beim Update prüft – und etwas strenger:
// JSON-Syntax aller Dateien, Pflichtfelder, Regeln, Dateinamen, und baut jede Antwort einmal.
//
//   php tools/validate.php [mocks-ordner]

use MacoMocks\Catalog;
use MacoMocks\MockServer;

require __DIR__ . '/../proxy/src/bootstrap.php';

$dir = rtrim($argv[1] ?? dirname(__DIR__) . '/mocks', '/');
$catalog = Catalog::fromDirectory($dir, true);
$errors = $catalog->errors;

// Dateinamen und JSON aller Dateien (auch anfrage*.json und _global.json)
$items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
$fileCount = 0;
foreach ($items as $item) {
    $rel = substr($item->getPathname(), strlen($dir) + 1);
    foreach (explode('/', $rel) as $segment) {
        if (!preg_match('/^[A-Za-z0-9._@-]+$/', $segment)) {
            $errors[] = "{$rel}: nur A-Z, a-z, 0-9, Punkt, Minus, Unterstrich und @ im Namen erlaubt";
            continue 2;
        }
    }
    if (str_ends_with($rel, '.json')) {
        $fileCount++;
        json_decode((string) file_get_contents($item->getPathname()));
        if (json_last_error() !== JSON_ERROR_NONE) {
            $errors[] = "{$rel}: kein gültiges JSON (" . json_last_error_msg() . ')';
        }
    } elseif (!str_ends_with($rel, '.md')) {
        $errors[] = "{$rel}: nur .json- und .md-Dateien werden vom Proxy übernommen";
    }
}

// Jede Antwort jedes Endpunkts einmal über den Proxy-Code bauen
$server = new MockServer($catalog, 'validate');
$responseCount = 0;
$ruleCount = 0;
$expectationCount = 0;
foreach ($catalog->endpoints as $endpoint) {
    $ruleCount += count($endpoint['rules']);
    $expectationCount += count($endpoint['expectations']);
    $path = (string) preg_replace('/\{[^}]+\}/', 'x', $endpoint['path']);
    foreach ($endpoint['responses'] as $name => $meta) {
        $responseCount++;
        $response = $server->handle([
            'method' => $endpoint['method'],
            'path' => $path,
            'query' => $endpoint['command'] !== null ? ['command' => $endpoint['command']] : [],
            'headers' => ['x-mock-response' => $name],
            'body' => '{}',
        ]);
        if (($response['headers']['X-Mock-Endpoint'] ?? '') !== $endpoint['id']) {
            $errors[] = "{$endpoint['id']}: Anfrage landet bei " . ($response['headers']['X-Mock-Endpoint'] ?? $response['status']) . ' (Pfad/Command doppelt?)';
            continue 2;
        }
        if ($response['status'] !== $meta['status']) {
            $errors[] = "{$endpoint['id']}/{$name}: Status {$response['status']} statt {$meta['status']}";
        }
    }
}

if ($errors) {
    fwrite(STDERR, count($errors) . " Fehler:\n  - " . implode("\n  - ", $errors) . "\n");
    exit(1);
}
printf(
    "ok: %d Endpunkte, %d Antworten, %d Regeln, %d Apidog-Erwartungen, %d globale Regeln, %d JSON-Dateien\n",
    count($catalog->endpoints),
    $responseCount,
    $ruleCount,
    $expectationCount,
    count($catalog->globalRules),
    $fileCount,
);
