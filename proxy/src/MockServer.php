<?php

declare(strict_types=1);

namespace MacoMocks;

/**
 * Beantwortet eine Anfrage aus dem Katalog.
 *
 * Reihenfolge je Anfrage:
 *   1. Endpunkt: Methode + Pfad (".json"-Endung und "/" am Ende werden toleriert).
 *      Gleicher Pfad mehrfach: Query-Parameter "command" entscheidet.
 *      Unbekannter Pfad mit "command": Suche über den Command (Präfixe wie SAP_ werden erkannt).
 *   2. Antwort: Auswahl per Header X-Mock-Response / Query __response → Paging-Prüfung
 *      → Regeln des Endpunkts → globale Regeln → default. Parameter ohne Regel werden ignoriert.
 *   3. Aufbereiten: "set" und {{…}}-Platzhalter, Paging, Header.
 *
 * Anfrage:  ['method' => 'GET', 'path' => '/x', 'query' => [...], 'headers' => [klein => wert], 'body' => string]
 * Antwort:  ['status' => int, 'headers' => [...], 'body' => ?string]
 */
final class MockServer
{
    private const PLACEHOLDER = '/\{\{\s*([A-Za-z_][\w.\-\[\]]*)\s*(?:\|([^}]*))?\}\}/';
    private const JSON_FLAGS = JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION;

    /** @var array<string, mixed> */
    private array $ctx = [];

    public function __construct(private Catalog $catalog, private string $version = '')
    {
    }

    public function handle(array $request): array
    {
        $method = strtoupper($request['method']);
        if ($method === 'OPTIONS') {
            return self::preflight($request['headers']['access-control-request-headers'] ?? '*');
        }

        $path = rawurldecode($request['path']);
        if (strlen($path) > 1 && str_ends_with($path, '/')) {
            $path = rtrim($path, '/');
        }
        $lookup = $method === 'HEAD' ? 'GET' : $method;
        $query = $request['query'];
        $command = isset($query['command']) && is_string($query['command']) ? $query['command'] : null;

        $candidates = $this->findByPath($path, $lookup);
        if (!$candidates && str_ends_with($path, '.json')) {
            $path = substr($path, 0, -5);
            $candidates = $this->findByPath($path, $lookup);
        }

        $match = null;
        foreach ($candidates as $candidate) {
            if (self::commandMatches($candidate[0]['command'], $command)) {
                $match = $candidate;
                break;
            }
        }
        $match ??= $candidates[0] ?? null;
        if ($match === null && $command !== null) {
            $match = $this->findByCommand($command, $lookup);
        }

        if ($match === null) {
            $allowed = $this->allowedMethods($path);
            if ($allowed) {
                return self::json(405, ['fehler' => "{$method} ist für {$path} nicht gemockt", 'erlaubt' => $allowed], ['Allow' => implode(', ', $allowed)]);
            }
            return self::json(404, ['fehler' => "Kein Mock für {$method} {$path}", 'hinweis' => 'Übersicht aller Mocks unter /_mocks']);
        }

        [$endpoint, $params] = $match;
        $this->ctx = [
            'query' => $query,
            'headers' => $request['headers'],
            'params' => $params,
            'command' => $command,
            'rawBody' => $request['body'] ?? '',
            'body' => null,
            'bodyParsed' => false,
        ];
        return $this->respond($endpoint, $method === 'HEAD');
    }

    // ------------------------------------------------------------------
    // Routing

    /** @return list<array{0: array, 1: array<string, string>}> */
    private function findByPath(string $path, string $method): array
    {
        $hits = [];
        foreach ($this->catalog->endpoints as $endpoint) {
            if ($endpoint['method'] !== $method || !preg_match($endpoint['pattern'], $path, $m)) {
                continue;
            }
            $params = [];
            foreach ($endpoint['params'] as $i => $name) {
                $params[$name] = rawurldecode($m[$i + 1]);
            }
            $hits[] = [$endpoint, $params];
        }
        return $hits;
    }

    private function findByCommand(string $command, string $method): ?array
    {
        $fallback = null;
        foreach ($this->catalog->endpoints as $endpoint) {
            if (!self::commandMatches($endpoint['command'], $command)) {
                continue;
            }
            if ($endpoint['method'] === $method) {
                return [$endpoint, []];
            }
            $fallback ??= [$endpoint, []];
        }
        return $fallback;
    }

    /** @return list<string> */
    private function allowedMethods(string $path): array
    {
        $methods = [];
        foreach ($this->catalog->endpoints as $endpoint) {
            if (preg_match($endpoint['pattern'], $path)) {
                $methods[$endpoint['method']] = true;
            }
        }
        if (isset($methods['GET'])) {
            $methods['HEAD'] = true;
        }
        return array_keys($methods);
    }

    private static function commandMatches(?string $endpointCommand, ?string $command): bool
    {
        if ($endpointCommand === null || $command === null || $command === '') {
            return false;
        }
        $c = strtoupper(trim($command));
        $e = strtoupper($endpointCommand);
        return $c === $e || str_ends_with($c, '_' . $e);
    }

    // ------------------------------------------------------------------
    // Auswahl der Antwort

    /** @return array{then: array, name: string, reason: string, paging: ?array} */
    private function decide(array $endpoint): array
    {
        $responses = $endpoint['responses'];

        $selection = $this->ctx['headers']['x-mock-response'] ?? ($this->ctx['query']['__response'] ?? null);
        if (is_string($selection) && $selection !== '') {
            $name = self::resolveName($responses, $selection);
            if ($name !== null) {
                return ['then' => ['file' => $name], 'name' => $name, 'reason' => 'Auswahl', 'paging' => null];
            }
        }

        $paging = null;
        if ($endpoint['paging']) {
            $paging = $this->readPaging($endpoint['paging']);
            if (isset($paging['error'])) {
                $name = $endpoint['paging']['invalid'] ?? self::firstWithStatus($responses, 400);
                $then = $name !== null ? ['file' => $name] : ['status' => 400, 'bodyJson' => json_encode(['fehler' => $paging['error']])];
                return ['then' => $then, 'name' => $name ?? 'inline', 'reason' => $paging['error'], 'paging' => null];
            }
        }

        foreach ($endpoint['rules'] as $rule) {
            if ($this->ruleMatches($rule)) {
                return ['then' => $rule['then'], 'name' => $rule['then']['file'] ?? 'inline', 'reason' => 'Regel: ' . $rule['name'], 'paging' => $paging];
            }
        }
        foreach ($this->catalog->globalRules as $rule) {
            if (isset($rule['then']['file']) && !isset($responses[$rule['then']['file']])) {
                continue;
            }
            if ($this->ruleMatches($rule)) {
                return ['then' => $rule['then'], 'name' => $rule['then']['file'] ?? 'inline', 'reason' => 'Globale Regel: ' . $rule['name'], 'paging' => $paging];
            }
        }
        return ['then' => ['file' => $endpoint['default']], 'name' => (string) $endpoint['default'], 'reason' => 'Standard', 'paging' => $paging];
    }

    private static function resolveName(array $responses, string $selection): ?string
    {
        $selection = trim($selection);
        foreach ([$selection, $selection . '.json'] as $candidate) {
            if (isset($responses[$candidate])) {
                return $candidate;
            }
        }
        return ctype_digit($selection) ? self::firstWithStatus($responses, (int) $selection) : null;
    }

    private static function firstWithStatus(array $responses, int $status): ?string
    {
        foreach ($responses as $name => $response) {
            if ($response['status'] === $status) {
                return $name;
            }
        }
        return null;
    }

    private function ruleMatches(array $rule): bool
    {
        foreach ($rule['when'] as [$source, $field, $op, $expected]) {
            if (!$this->conditionMatches($source, $field, $op, $expected)) {
                return false;
            }
        }
        return true;
    }

    private function conditionMatches(string $source, ?string $field, string $op, mixed $expected): bool
    {
        $value = $this->lookup($source, $field);
        $present = $value !== null && $value !== '';
        if ($op === 'exists') {
            return $present === (bool) $expected;
        }
        if (!$present) {
            return $op === 'not' || $op === 'notIn';
        }
        $text = is_scalar($value) ? (is_bool($value) ? ($value ? 'true' : 'false') : (string) $value) : (string) json_encode($value, JSON_UNESCAPED_UNICODE);
        return match ($op) {
            'equals' => $text === (string) $expected,
            'not' => $text !== (string) $expected,
            'in' => in_array($text, (array) $expected, true),
            'notIn' => !in_array($text, (array) $expected, true),
            'regex' => (bool) preg_match('~' . str_replace('~', '\~', (string) $expected) . '~', $text),
            'contains' => is_array($value) ? in_array((string) $expected, array_map('strval', array_filter($value, 'is_scalar')), true) : str_contains($text, (string) $expected),
            'gt' => is_numeric($text) && (float) $text > (float) $expected,
            'gte' => is_numeric($text) && (float) $text >= (float) $expected,
            'lt' => is_numeric($text) && (float) $text < (float) $expected,
            'lte' => is_numeric($text) && (float) $text <= (float) $expected,
            default => false,
        };
    }

    private function lookup(string $source, ?string $field): mixed
    {
        return match ($source) {
            'query' => $this->ctx['query'][$field] ?? null,
            'header' => $this->ctx['headers'][$field] ?? null,
            'path' => $this->ctx['params'][$field] ?? null,
            'body' => self::getPath($this->requestBody(), $field ?? ''),
            'command' => $this->ctx['command'],
            default => null,
        };
    }

    private function requestBody(): mixed
    {
        if (!$this->ctx['bodyParsed']) {
            $raw = (string) $this->ctx['rawBody'];
            $decoded = $raw === '' ? null : json_decode($raw, true);
            $this->ctx['body'] = $decoded ?? ($raw === '' ? null : $raw);
            $this->ctx['bodyParsed'] = true;
        }
        return $this->ctx['body'];
    }

    // ------------------------------------------------------------------
    // Paging, z. B. identifyLocation mit Treffer-Max-Anzahl / Treffer-Offset

    private function readPaging(array $p): array
    {
        $rawLimit = $this->ctx['headers'][strtolower($p['limitHeader'])] ?? '';
        $rawOffset = $this->ctx['headers'][strtolower($p['offsetHeader'])] ?? '';
        $limit = $rawLimit === '' ? (int) $p['defaultLimit'] : (ctype_digit(trim($rawLimit)) ? (int) $rawLimit : -1);
        $offset = $rawOffset === '' ? 0 : (ctype_digit(trim($rawOffset)) ? (int) $rawOffset : -1);
        if ($limit < 1 || $limit > (int) $p['maxLimit']) {
            return ['error' => "{$p['limitHeader']} ungültig (erlaubt 1 bis {$p['maxLimit']})"];
        }
        if ($offset < 0 || $offset > (int) $p['maxOffset']) {
            return ['error' => "{$p['offsetHeader']} ungültig (erlaubt 0 bis {$p['maxOffset']})"];
        }
        return ['limit' => $limit, 'offset' => $offset];
    }

    // ------------------------------------------------------------------
    // Antwort bauen

    private function respond(array $endpoint, bool $headOnly): array
    {
        $decision = $this->decide($endpoint);
        $then = $decision['then'];
        $file = $then['file'] ?? null;
        $status = $then['status'] ?? ($file !== null ? $endpoint['responses'][$file]['status'] : 200);

        $raw = null;
        if (array_key_exists('bodyJson', $then)) {
            $raw = $then['bodyJson'];
        } elseif ($file !== null) {
            $raw = (string) file_get_contents($this->catalog->responseFile($endpoint, $file));
        }

        $headers = [];
        $needsProcessing = $raw !== null && ($endpoint['set'] || $decision['paging'] !== null || str_contains($raw, '{{'));
        if ($needsProcessing) {
            $body = json_decode($raw, false);
            foreach ($endpoint['set'] as $target => $template) {
                $value = $this->renderString($template, true);
                if ($value !== null) {
                    self::setPath($body, self::parsePath($target), $value);
                }
            }
            $body = $this->render($body);
            if ($decision['paging'] !== null && is_array($body) && array_is_list($body) && $status < 300) {
                $p = $endpoint['paging'];
                $total = count($body);
                $body = array_slice($body, $decision['paging']['offset'], $decision['paging']['limit']);
                $h = $p['responseHeaders'];
                $headers[$h['offset']] = (string) $decision['paging']['offset'];
                $headers[$h['count']] = (string) count($body);
                $headers[$h['total']] = (string) $total;
                $headers[$h['more']] = $decision['paging']['offset'] + count($body) < $total ? 'true' : 'false';
            }
            $raw = json_encode($body, self::JSON_FLAGS);
        }

        $headers = [
            'X-Mock-Endpoint' => $endpoint['id'],
            'X-Mock-Response' => $decision['name'],
            'X-Mock-Reason' => $decision['reason'],
        ] + ($this->version !== '' ? ['X-Mock-Version' => $this->version] : []) + $headers;
        foreach ($endpoint['headers'] + ($then['headers'] ?? []) as $name => $value) {
            $headers[$name] = (string) $this->renderString($value, false);
        }

        $delay = $then['delay'] ?? $endpoint['delay'];
        if ($delay > 0) {
            usleep(min($delay, 30000) * 1000);
        }

        return self::build($status, $raw, $headers, $headOnly);
    }

    private function render(mixed $value): mixed
    {
        if (is_string($value)) {
            return str_contains($value, '{{') ? $this->renderString($value, false) : $value;
        }
        if (is_array($value)) {
            foreach ($value as $k => $v) {
                $value[$k] = $this->render($v);
            }
            return $value;
        }
        if ($value instanceof \stdClass) {
            foreach (get_object_vars($value) as $k => $v) {
                $value->{$k} = $this->render($v);
            }
        }
        return $value;
    }

    /**
     * {{query.x}}, {{header.x}}, {{path.x}}, {{body.a.b[0]}}, {{command}}, {{now}}, {{uuid}}; Standardwert mit {{query.x|wert}}.
     * $strict: ohne Wert und ohne Standardwert null liefern (für "set": dann bleibt der Beispielwert).
     */
    private function renderString(string $text, bool $strict): mixed
    {
        if (preg_match('/^' . substr(self::PLACEHOLDER, 1, -1) . '$/', $text, $m)) {
            $value = $this->resolve($m[1]);
            if ($value !== null && $value !== '') {
                return $value;
            }
            if (isset($m[2])) {
                return trim($m[2]);
            }
            return $strict ? null : $text;
        }
        $unresolved = false;
        $out = preg_replace_callback(self::PLACEHOLDER, function (array $m) use (&$unresolved): string {
            $value = $this->resolve($m[1]);
            if ($value === null || $value === '') {
                if (isset($m[2])) {
                    return trim($m[2]);
                }
                $unresolved = true;
                return $m[0];
            }
            return is_scalar($value) ? (string) $value : (string) json_encode($value, JSON_UNESCAPED_UNICODE);
        }, $text);
        return $strict && $unresolved ? null : $out;
    }

    private function resolve(string $expr): mixed
    {
        if ($expr === 'now') {
            return gmdate('Y-m-d\TH:i:s\Z');
        }
        if ($expr === 'uuid') {
            $b = random_bytes(16);
            $b[6] = chr(ord($b[6]) & 0x0f | 0x40);
            $b[8] = chr(ord($b[8]) & 0x3f | 0x80);
            return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
        }
        if ($expr === 'command') {
            return $this->ctx['command'];
        }
        if (!preg_match('/^(query|header|path|body)\.(.+)$/', $expr, $m)) {
            return null;
        }
        return $this->lookup($m[1], $m[1] === 'header' ? strtolower($m[2]) : $m[2]);
    }

    // ------------------------------------------------------------------
    // Hilfsfunktionen

    /** "a.b[0].c", "a.b.0.c" oder "[0].c" */
    public static function parsePath(string $path): array
    {
        $normalized = preg_replace('/\[(\d+)\]/', '.$1', $path);
        return array_values(array_filter(explode('.', (string) $normalized), static fn ($p) => $p !== ''));
    }

    public static function getPath(mixed $data, string $path): mixed
    {
        foreach (self::parsePath($path) as $key) {
            if (is_array($data) && array_key_exists($key, $data)) {
                $data = $data[$key];
            } elseif ($data instanceof \stdClass && property_exists($data, $key)) {
                $data = $data->{$key};
            } else {
                return null;
            }
        }
        return $data;
    }

    /** Setzt nur, wenn der Pfad schon existiert – so passt "set" auch auf anders aufgebaute Beispiele. */
    public static function setPath(mixed &$root, array $keys, mixed $value): bool
    {
        if (!$keys) {
            return false;
        }
        $last = array_pop($keys);
        $current = &$root;
        foreach ($keys as $key) {
            if ($current instanceof \stdClass && property_exists($current, $key)) {
                $current = &$current->{$key};
            } elseif (is_array($current) && array_key_exists(self::index($key), $current)) {
                $current = &$current[self::index($key)];
            } else {
                return false;
            }
        }
        if ($current instanceof \stdClass && property_exists($current, $last)) {
            $current->{$last} = $value;
            return true;
        }
        if (is_array($current) && array_key_exists(self::index($last), $current)) {
            $current[self::index($last)] = $value;
            return true;
        }
        return false;
    }

    private static function index(string $key): int|string
    {
        return ctype_digit($key) ? (int) $key : $key;
    }

    public static function json(int $status, mixed $data, array $headers = []): array
    {
        return self::build($status, json_encode($data, self::JSON_FLAGS), $headers, false);
    }

    private static function build(int $status, ?string $body, array $headers, bool $headOnly): array
    {
        $base = [
            'Access-Control-Allow-Origin' => '*',
            'Access-Control-Expose-Headers' => '*',
            'Cache-Control' => 'no-store',
        ];
        $noBody = $body === null || $status === 204 || $status === 304;
        if (!$noBody || $headOnly) {
            $base['Content-Type'] = 'application/json; charset=utf-8';
        }
        $clean = [];
        foreach ($base + $headers as $name => $value) {
            // Header-Werte nur als ASCII ausgeben (Umlaute umschreiben, Rest ersetzen)
            $ascii = strtr((string) $value, ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'Ä' => 'Ae', 'Ö' => 'Oe', 'Ü' => 'Ue', 'ß' => 'ss', '–' => '-', '→' => '->']);
            $clean[$name] = (string) preg_replace('/[^\x20-\x7E]+/', '?', $ascii);
        }
        return ['status' => $status, 'headers' => $clean, 'body' => $headOnly || $noBody ? null : $body];
    }

    private static function preflight(string $requestHeaders): array
    {
        return [
            'status' => 204,
            'headers' => [
                'Access-Control-Allow-Origin' => '*',
                'Access-Control-Allow-Methods' => 'GET, HEAD, POST, PUT, PATCH, DELETE, OPTIONS',
                'Access-Control-Allow-Headers' => $requestHeaders !== '' ? $requestHeaders : '*',
                'Access-Control-Max-Age' => '86400',
            ],
            'body' => null,
        ];
    }
}
