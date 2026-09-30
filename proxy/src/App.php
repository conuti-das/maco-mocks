<?php

declare(strict_types=1);

namespace MacoMocks;

/**
 * Front-Controller: interne Endpunkte + Weitergabe an den MockServer.
 *
 *   POST /_update   GitHub-Webhook (Push auf main) oder manuell: lokale Kopie aktualisieren
 *   GET  /_status   Stand der lokalen Kopie, letztes Update
 *   GET  /_mocks    alle Endpunkte mit Antworten und Regeln (JSON)
 *   GET  /          Übersicht (HTML)
 *   alles andere    Mock-Antwort
 */
final class App
{
    private ?RepoSync $sync = null;

    public function __construct(private array $config)
    {
    }

    public static function requestFromGlobals(): array
    {
        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with((string) $key, 'HTTP_')) {
                $headers[strtolower(str_replace('_', '-', substr((string) $key, 5)))] = (string) $value;
            }
        }
        foreach (['CONTENT_TYPE' => 'content-type', 'CONTENT_LENGTH' => 'content-length', 'REDIRECT_HTTP_AUTHORIZATION' => 'authorization'] as $key => $name) {
            if (isset($_SERVER[$key]) && !isset($headers[$name])) {
                $headers[$name] = (string) $_SERVER[$key];
            }
        }
        // Query selbst parsen: PHP macht aus "a.b" sonst "a_b"
        $query = [];
        foreach (explode('&', (string) ($_SERVER['QUERY_STRING'] ?? '')) as $pair) {
            if ($pair === '') {
                continue;
            }
            [$key, $value] = array_pad(explode('=', $pair, 2), 2, '');
            $key = urldecode($key);
            if (!array_key_exists($key, $query)) {
                $query[$key] = urldecode($value);
            }
        }
        return [
            'method' => (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'),
            'path' => (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/'),
            'query' => $query,
            'headers' => $headers,
            'body' => (string) file_get_contents('php://input'),
        ];
    }

    public static function emit(array $response): void
    {
        http_response_code($response['status']);
        foreach ($response['headers'] as $name => $value) {
            header($name . ': ' . $value);
        }
        if ($response['body'] !== null) {
            echo $response['body'];
        }
    }

    public function handle(array $request): array
    {
        $path = $request['path'];
        $base = rtrim((string) ($this->config['basePath'] ?? ''), '/');
        if ($base !== '' && ($path === $base || str_starts_with($path, $base . '/'))) {
            $path = substr($path, strlen($base)) ?: '/';
        }
        $request['path'] = $path;
        $method = strtoupper($request['method']);

        if ($path === '/_update') {
            return $this->handleUpdate($request);
        }
        if ($method === 'GET' && $path === '/_status') {
            return MockServer::json(200, $this->status());
        }

        [$catalog, $version] = $this->loadCatalog();
        if ($catalog === null) {
            return MockServer::json(503, [
                'fehler' => 'Noch keine lokale Kopie der Mocks vorhanden',
                'hinweis' => 'POST /_update auslösen, Details unter /_status',
                'letztesUpdate' => $this->sync()->state()['lastResult'] ?? null,
            ]);
        }
        if ($method === 'GET' && $path === '/_mocks') {
            return MockServer::json(200, self::summary($catalog, $version));
        }
        $token = (string) ($this->config['token'] ?? '');
        if ($token !== '' && $method !== 'OPTIONS' && !self::hasBearer($request, $token)) {
            return MockServer::json(401, ['fehler' => 'Authorization: Bearer <Token> fehlt oder ist falsch']);
        }
        if ($method === 'GET' && $path === '/' && !isset($request['query']['command'])) {
            return $this->overview($catalog, $version);
        }
        return (new MockServer($catalog, $version))->handle($request);
    }

    // ------------------------------------------------------------------

    /** @return array{0: ?Catalog, 1: string} */
    private function loadCatalog(): array
    {
        if (!empty($this->config['mocksDir'])) {
            return [Catalog::fromDirectory((string) $this->config['mocksDir'], false), 'lokal'];
        }
        $sync = $this->sync();
        $catalog = $sync->catalog();
        $due = $sync->isPending() && time() - (int) ($sync->state()['lastAttempt'] ?? 0) >= (int) ($this->config['minUpdateInterval'] ?? 10);
        if ($catalog === null || $due) {
            $sync->update($catalog === null ? 'erster Aufruf' : 'nachgeholt');
            $catalog = $sync->catalog();
        }
        $current = $sync->current();
        return [$catalog, $current !== null ? substr((string) $current['sha'], 0, 7) : ''];
    }

    private function handleUpdate(array $request): array
    {
        $method = strtoupper($request['method']);
        if ($method !== 'POST' && $method !== 'GET') {
            return MockServer::json(405, ['fehler' => 'Nur POST oder GET'], ['Allow' => 'POST, GET']);
        }
        if (!empty($this->config['mocksDir'])) {
            return MockServer::json(200, ['status' => 'lokal', 'message' => 'Entwicklungsmodus: liest direkt aus ' . $this->config['mocksDir']]);
        }

        $secret = (string) ($this->config['updateSecret'] ?? '');
        if ($secret !== '') {
            $signature = $request['headers']['x-hub-signature-256'] ?? '';
            $expected = 'sha256=' . hash_hmac('sha256', $request['body'], $secret);
            $token = (string) ($request['query']['token'] ?? '');
            $valid = ($signature !== '' && hash_equals($expected, $signature))
                || self::hasBearer($request, $secret)
                || ($token !== '' && hash_equals($secret, $token));
            if (!$valid) {
                return MockServer::json(403, ['fehler' => 'Signatur oder Token fehlt bzw. ist falsch']);
            }
        }

        $event = $request['headers']['x-github-event'] ?? null;
        if ($event === 'ping') {
            return MockServer::json(200, ['status' => 'pong']);
        }
        if ($event !== null && $event !== 'push') {
            return MockServer::json(202, ['status' => 'ignoriert', 'message' => "Event {$event}"]);
        }
        if ($event === 'push') {
            $payload = self::webhookPayload($request);
            $ref = (string) ($payload['ref'] ?? '');
            $repo = (string) ($payload['repository']['full_name'] ?? '');
            if ($ref !== 'refs/heads/' . $this->config['branch']) {
                return MockServer::json(202, ['status' => 'ignoriert', 'message' => "Push auf {$ref}"]);
            }
            if ($repo !== '' && strcasecmp($repo, (string) $this->config['repo']) !== 0) {
                return MockServer::json(202, ['status' => 'ignoriert', 'message' => "Anderes Repo: {$repo}"]);
            }
        }

        $force = $secret !== '' && isset($request['query']['force']);
        $sync = $this->sync();
        $result = $sync->update($event === 'push' ? 'webhook' : 'manuell', $force);
        if ($result['status'] === 'skipped' || $result['status'] === 'busy') {
            $sync->markPending(); // wird mit der nächsten Anfrage nachgeholt
            $result['message'] .= ' – wird nachgeholt';
        }
        $code = match ($result['status']) {
            'updated', 'unchanged' => 200,
            'skipped', 'busy' => 202,
            default => 500,
        };
        return MockServer::json($code, $result);
    }

    private static function webhookPayload(array $request): array
    {
        $body = $request['body'];
        if (str_contains($request['headers']['content-type'] ?? '', 'application/x-www-form-urlencoded')) {
            parse_str($body, $form);
            $body = (string) ($form['payload'] ?? '');
        }
        $payload = json_decode($body, true);
        return is_array($payload) ? $payload : [];
    }

    private function status(): array
    {
        $local = !empty($this->config['mocksDir']);
        $sync = $this->sync();
        $current = $local ? null : $sync->current();
        $state = $local ? [] : $sync->state();
        return [
            'modus' => $local ? 'lokal (' . $this->config['mocksDir'] . ')' : 'Repo-Kopie',
            'repo' => 'https://github.com/' . $this->config['repo'] . '/tree/' . $this->config['branch'],
            'version' => $current !== null ? substr((string) $current['sha'], 0, 7) : null,
            'commit' => $current['sha'] ?? null,
            'aktivSeit' => $current['updatedAt'] ?? null,
            'endpunkte' => $current['endpoints'] ?? null,
            'letztesUpdate' => $state['lastResult'] ?? null,
            'nachholen' => !$local && $sync->isPending(),
            'webhookSecret' => (string) ($this->config['updateSecret'] ?? '') !== '',
            'php' => PHP_VERSION,
            'zip' => class_exists(\ZipArchive::class),
            'curl' => function_exists('curl_init'),
        ];
    }

    private static function summary(Catalog $catalog, string $version): array
    {
        return [
            'version' => $version,
            'endpunkte' => array_map(static fn (array $ep): array => [
                'id' => $ep['id'],
                'method' => $ep['method'],
                'path' => $ep['path'],
                'command' => $ep['command'],
                'summary' => $ep['summary'],
                'default' => $ep['default'],
                'antworten' => array_map(static fn (array $r): int => $r['status'], $ep['responses']),
                'regeln' => array_map(static fn (array $r): string => $r['name'], $ep['rules']),
            ], $catalog->endpoints),
            'globaleRegeln' => array_map(static fn (array $r): string => $r['name'], $catalog->globalRules),
        ];
    }

    private function overview(Catalog $catalog, string $version): array
    {
        $e = static fn (mixed $s): string => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $rows = '';
        foreach ($catalog->endpoints as $ep) {
            $responses = [];
            foreach ($ep['responses'] as $name => $r) {
                $label = $e($name);
                $responses[] = $name === $ep['default'] ? "<strong>{$label}</strong>" : $label;
            }
            $rules = array_map(static fn (array $r): string => $e($r['name']), $ep['rules']);
            if ($ep['paging']) {
                $rules[] = 'Paging über ' . $e($ep['paging']['limitHeader']) . ' / ' . $e($ep['paging']['offsetHeader']);
            }
            $rows .= '<tr><td>' . $e($ep['method']) . '</td><td><code>' . $e($ep['path']) . '</code></td><td><code>'
                . $e($ep['command'] ?? '') . '</code><br><span class="muted">' . $e($ep['summary']) . '</span></td><td>'
                . implode('<br>', $responses) . '</td><td>' . (implode('<br>', $rules) ?: '–') . '</td></tr>';
        }
        $repo = 'https://github.com/' . $this->config['repo'];
        $html = <<<HTML
<!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>MaKo Backend-Mocks</title>
<style>
  :root { color-scheme: light dark; --fg: #1d2330; --bg: #fff; --muted: #5b6475; --line: #d9dde5; --accent: #0b5cad; }
  @media (prefers-color-scheme: dark) { :root { --fg: #e6e9ef; --bg: #14171d; --muted: #9aa3b2; --line: #2c323d; --accent: #6fb1ff; } }
  body { font: 15px/1.5 system-ui, sans-serif; color: var(--fg); background: var(--bg); margin: 0 auto; padding: 24px 16px; max-width: 1150px; }
  h1 { font-size: 22px; margin: 0 0 4px; } p, .muted { color: var(--muted); } a { color: var(--accent); }
  .wrap { overflow-x: auto; } table { border-collapse: collapse; width: 100%; font-size: 14px; }
  th, td { text-align: left; padding: 6px 8px; border-bottom: 1px solid var(--line); vertical-align: top; } code { font-size: 12px; }
</style>
</head>
<body>
<h1>MaKo Backend-Mocks</h1>
<p>Stand <code>{$e($version)}</code> aus <a href="{$e($repo)}">{$e($this->config['repo'])}</a> · JSON-Übersicht: <a href="_mocks">/_mocks</a> · Status: <a href="_status">/_status</a></p>
<p>Parameter ohne Regel werden ignoriert. Eine bestimmte Antwort erzwingen: Header <code>X-Mock-Response: 422</code> oder <code>?__response=200-leer</code>. Welche Regel gegriffen hat, steht in <code>X-Mock-Reason</code>.</p>
<div class="wrap"><table>
<thead><tr><th>Methode</th><th>Pfad</th><th>Command</th><th>Antworten (Standard fett)</th><th>Regeln</th></tr></thead>
<tbody>{$rows}</tbody>
</table></div>
</body>
</html>
HTML;
        return ['status' => 200, 'headers' => ['Content-Type' => 'text/html; charset=utf-8', 'Cache-Control' => 'no-store'], 'body' => $html];
    }

    private static function hasBearer(array $request, string $token): bool
    {
        return $token !== '' && hash_equals('Bearer ' . $token, (string) ($request['headers']['authorization'] ?? ''));
    }

    private function sync(): RepoSync
    {
        return $this->sync ??= new RepoSync($this->config);
    }
}
