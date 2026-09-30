<?php

declare(strict_types=1);

namespace MacoMocks;

/**
 * Liest die Mock-Definitionen aus einem mocks/-Ordner und prüft sie.
 *
 *   mocks/_global.json                   globale Regeln (optional)
 *   mocks/<gruppe>/<endpunkt>/mock.json  Methode, Pfad, Command, Regeln
 *   mocks/<gruppe>/<endpunkt>/*.json     eigene Antworten, Name <status>[-<name>].json
 *   mocks/<gruppe>/<endpunkt>/quelle/    importierte Antworten; gleicher Name im Endpunkt-Ordner gewinnt
 *   mocks/<gruppe>/<endpunkt>/quelle/erwartungen.json  importierte Mock-Erwartungen (Regeln wie in mock.json)
 */
final class Catalog
{
    /** Aufbau des gespeicherten Index; bei Änderung baut der Proxy ältere Indexe neu auf. */
    public const FORMAT = 2;
    public const RESPONSE_FILE = '/^([1-5]\d\d)(-[A-Za-z0-9._-]+)?\.json$/';
    public const REQUEST_FILE = '/^anfrage(-[A-Za-z0-9._-]+)?\.json$/';
    public const OPERATORS = ['equals', 'not', 'in', 'notIn', 'regex', 'contains', 'notContains', 'exists', 'gt', 'gte', 'lt', 'lte'];
    private const METHODS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'];
    private const SOURCES = ['query', 'header', 'path', 'body'];

    public string $mocksDir = '';
    /** @var list<array<string, mixed>> */
    public array $endpoints = [];
    /** @var list<array<string, mixed>> */
    public array $globalRules = [];
    /** @var list<string> */
    public array $errors = [];

    /**
     * @param bool $checkFiles Antwortdateien auf gültiges JSON prüfen (Build/Validierung), im Betrieb nicht nötig
     */
    public static function fromDirectory(string $mocksDir, bool $checkFiles = true): self
    {
        $catalog = new self();
        $catalog->mocksDir = rtrim($mocksDir, '/');
        if (!is_dir($catalog->mocksDir)) {
            $catalog->errors[] = "Ordner nicht gefunden: {$mocksDir}";
            return $catalog;
        }
        foreach (self::findMockFiles($catalog->mocksDir) as $relDir) {
            $endpoint = $catalog->loadEndpoint($relDir, $checkFiles);
            if ($endpoint !== null) {
                $catalog->endpoints[] = $endpoint;
            }
        }
        $catalog->loadGlobalRules();
        $catalog->checkDuplicates();
        return $catalog;
    }

    /** Aus einem gespeicherten Index (index.json einer Release) laden. */
    public static function fromArray(array $data, string $mocksDir): self
    {
        $catalog = new self();
        $catalog->mocksDir = rtrim($mocksDir, '/');
        $catalog->endpoints = $data['endpoints'] ?? [];
        $catalog->globalRules = $data['globalRules'] ?? [];
        return $catalog;
    }

    public function toArray(): array
    {
        return ['format' => self::FORMAT, 'endpoints' => $this->endpoints, 'globalRules' => $this->globalRules];
    }

    /** Anfragebeispiel (anfrage*.json) als Text, z. B. für die Übersicht. */
    public function requestExample(array $endpoint, string $file): ?string
    {
        if (!in_array($file, $endpoint['requestExamples'] ?? [], true)) {
            return null;
        }
        $text = @file_get_contents($this->mocksDir . '/' . $endpoint['id'] . '/' . $file);
        return $text === false ? null : $text;
    }

    public function responseFile(array $endpoint, string $name): string
    {
        return $this->mocksDir . '/' . $endpoint['id'] . '/' . $endpoint['responses'][$name]['file'];
    }

    // ------------------------------------------------------------------

    /** @return list<string> relative Ordner mit mock.json, sortiert */
    private static function findMockFiles(string $root): array
    {
        $found = [];
        $walk = static function (string $rel, int $depth) use (&$walk, &$found, $root): void {
            $abs = $rel === '' ? $root : $root . '/' . $rel;
            if (is_file($abs . '/mock.json') && $rel !== '') {
                $found[] = $rel;
                return;
            }
            if ($depth >= 4) {
                return;
            }
            foreach (scandir($abs) ?: [] as $entry) {
                if ($entry[0] === '.' || $entry === 'quelle' || !is_dir($abs . '/' . $entry)) {
                    continue;
                }
                $walk($rel === '' ? $entry : $rel . '/' . $entry, $depth + 1);
            }
        };
        $walk('', 0);
        sort($found, SORT_STRING);
        return $found;
    }

    private function loadEndpoint(string $id, bool $checkFiles): ?array
    {
        $dir = $this->mocksDir . '/' . $id;
        $where = "{$id}/mock.json";
        $raw = file_get_contents($dir . '/mock.json');
        $mock = json_decode((string) $raw, false);
        if (!$mock instanceof \stdClass) {
            $this->errors[] = "{$where}: kein gültiges JSON-Objekt (" . json_last_error_msg() . ')';
            return null;
        }

        $method = strtoupper((string) ($mock->method ?? ''));
        $path = (string) ($mock->path ?? '');
        if (!in_array($method, self::METHODS, true)) {
            $this->errors[] = "{$where}: method fehlt oder ungültig (erlaubt: " . implode(', ', self::METHODS) . ')';
        }
        if ($path === '' || $path[0] !== '/' || preg_match('/\s/', $path)) {
            $this->errors[] = "{$where}: path muss mit / beginnen und darf keine Leerzeichen enthalten";
        }
        $command = isset($mock->command) ? (string) $mock->command : null;

        $responses = [];
        $requestExamples = [];
        foreach (['quelle/', ''] as $prefix) {
            if (!is_dir($dir . '/' . $prefix)) {
                continue;
            }
            foreach (scandir($dir . '/' . $prefix) ?: [] as $file) {
                if (preg_match(self::REQUEST_FILE, $file) && is_file($dir . '/' . $prefix . $file)) {
                    $requestExamples[$file] = $prefix . $file;
                    continue;
                }
                if (!preg_match(self::RESPONSE_FILE, $file, $m) || !is_file($dir . '/' . $prefix . $file)) {
                    continue;
                }
                if ($checkFiles) {
                    json_decode((string) file_get_contents($dir . '/' . $prefix . $file));
                    if (json_last_error() !== JSON_ERROR_NONE) {
                        $this->errors[] = "{$id}/{$prefix}{$file}: kein gültiges JSON (" . json_last_error_msg() . ')';
                    }
                }
                $responses[$file] = ['status' => (int) $m[1], 'file' => $prefix . $file];
            }
        }
        ksort($responses, SORT_STRING);
        ksort($requestExamples, SORT_STRING);
        if (!$responses) {
            $this->errors[] = "{$id}: keine Antwortdatei (<status>[-name].json) gefunden";
        }

        $expectations = $this->loadExpectations($id, $responses);

        $default = isset($mock->default) ? (string) $mock->default : self::pickDefault($responses);
        if ($default !== null && !isset($responses[$default])) {
            $this->errors[] = "{$where}: default \"{$default}\" existiert nicht";
        }

        $rules = [];
        if (isset($mock->rules) && !is_array($mock->rules)) {
            $this->errors[] = "{$where}: rules muss eine Liste sein";
        }
        foreach (is_array($mock->rules ?? null) ? $mock->rules : [] as $i => $rule) {
            $normalized = $this->normalizeRule($rule, "{$where} Regel " . ($i + 1), $responses);
            if ($normalized !== null) {
                $rules[] = $normalized;
            }
        }

        $set = [];
        foreach ((array) ($mock->set ?? []) as $target => $value) {
            if (!is_scalar($value)) {
                $this->errors[] = "{$where}: set.{$target} muss ein Text sein";
                continue;
            }
            $set[(string) $target] = (string) $value;
        }

        $paging = null;
        if (isset($mock->paging)) {
            $paging = json_decode((string) json_encode($mock->paging), true);
            foreach (['limitHeader', 'offsetHeader', 'defaultLimit', 'maxLimit', 'maxOffset', 'responseHeaders'] as $key) {
                if (!isset($paging[$key])) {
                    $this->errors[] = "{$where}: paging.{$key} fehlt";
                }
            }
            if (isset($paging['invalid']) && !isset($responses[$paging['invalid']])) {
                $this->errors[] = "{$where}: paging.invalid \"{$paging['invalid']}\" existiert nicht";
            }
        }

        [$pattern, $params] = self::compilePath($path);

        return [
            'id' => $id,
            'method' => $method,
            'path' => $path,
            'pattern' => $pattern,
            'params' => $params,
            'command' => $command,
            'summary' => (string) ($mock->summary ?? ''),
            'default' => $default,
            'responses' => $responses,
            'rules' => $rules,
            'expectations' => $expectations,
            'set' => $set,
            'paging' => $paging,
            'headers' => self::stringMap($mock->headers ?? null),
            'delay' => (int) ($mock->delay ?? 0),
            'requestExamples' => array_values($requestExamples),
            'parameters' => self::parameters($mock->parameters ?? null),
        ];
    }

    /**
     * quelle/erwartungen.json: {"erwartungen": [{"name", "id", "when", "then"}, …]} in Prüfreihenfolge.
     * Markiert die Antwortdateien der Erwartungen, damit sie weder Standard werden noch "set" bekommen.
     *
     * @param array<string, array> $responses
     * @return list<array<string, mixed>>
     */
    private function loadExpectations(string $id, array &$responses): array
    {
        $file = $this->mocksDir . '/' . $id . '/quelle/erwartungen.json';
        if (!is_file($file)) {
            return [];
        }
        $where = "{$id}/quelle/erwartungen.json";
        $data = json_decode((string) file_get_contents($file), false);
        $list = $data instanceof \stdClass ? ($data->erwartungen ?? null) : null;
        if (!is_array($list)) {
            $this->errors[] = "{$where}: Liste \"erwartungen\" fehlt";
            return [];
        }
        $out = [];
        foreach ($list as $i => $entry) {
            // Erwartungen ohne Bedingung ("when": {}) greifen immer – wie im API-Tool
            $rule = $this->normalizeRule($entry, "{$where} Erwartung " . ($i + 1), $responses, true);
            if ($rule === null) {
                continue;
            }
            if (isset($entry->id)) {
                $rule['id'] = (int) $entry->id;
            }
            if (isset($rule['then']['file'])) {
                $responses[$rule['then']['file']]['erwartung'] = true;
            }
            $out[] = $rule;
        }
        return $out;
    }

    /** @return list<array{in: string, name: string, example?: string}> */
    private static function parameters(mixed $list): array
    {
        $out = [];
        foreach (is_array($list) ? $list : [] as $p) {
            if (!$p instanceof \stdClass || !isset($p->name, $p->in)) {
                continue;
            }
            $item = ['in' => (string) $p->in, 'name' => (string) $p->name];
            if (isset($p->example) && is_scalar($p->example)) {
                $item['example'] = (string) $p->example;
            }
            $out[] = $item;
        }
        return $out;
    }

    private function loadGlobalRules(): void
    {
        $file = $this->mocksDir . '/_global.json';
        if (!is_file($file)) {
            return;
        }
        $data = json_decode((string) file_get_contents($file), false);
        if (!$data instanceof \stdClass) {
            $this->errors[] = '_global.json: kein gültiges JSON-Objekt';
            return;
        }
        foreach (is_array($data->rules ?? null) ? $data->rules : [] as $i => $rule) {
            $normalized = $this->normalizeRule($rule, '_global.json Regel ' . ($i + 1), null);
            if ($normalized !== null) {
                $this->globalRules[] = $normalized;
            }
        }
    }

    /**
     * Regel: {"name": "...", "when": {"query.parameter1": "x", ...}, "then": "200-leer.json" | {...}}
     *
     * @param array<string, array>|null $responses null = globale Regel (Datei wird je Endpunkt geprüft)
     */
    private function normalizeRule(mixed $rule, string $where, ?array $responses, bool $allowAlways = false): ?array
    {
        if (!$rule instanceof \stdClass) {
            $this->errors[] = "{$where}: muss ein Objekt sein";
            return null;
        }
        $name = (string) ($rule->name ?? $where);
        $when = $rule->when ?? null;
        if (!$when instanceof \stdClass || (!get_object_vars($when) && !$allowAlways)) {
            $this->errors[] = "{$where} ({$name}): when fehlt oder ist leer";
            return null;
        }
        $conditions = [];
        foreach (get_object_vars($when) as $key => $expected) {
            $condition = $this->normalizeCondition((string) $key, $expected, "{$where} ({$name})");
            if ($condition === null) {
                return null;
            }
            array_push($conditions, ...$condition);
        }
        $then = $this->normalizeThen($rule->then ?? null, "{$where} ({$name})", $responses);
        if ($then === null) {
            return null;
        }
        return ['name' => $name, 'when' => $conditions, 'then' => $then];
    }

    /** @return list<array{0: string, 1: ?string, 2: string, 3: mixed}>|null */
    private function normalizeCondition(string $key, mixed $expected, string $where): ?array
    {
        if ($key === 'command') {
            [$source, $field] = ['command', null];
        } elseif (preg_match('/^(query|header|path|body)\.(.+)$/', $key, $m)) {
            [$source, $field] = [$m[1], $m[1] === 'header' ? strtolower($m[2]) : $m[2]];
        } else {
            $this->errors[] = "{$where}: unbekannte Quelle \"{$key}\" (erlaubt: " . implode('.<name>, ', self::SOURCES) . '.<name>, command)';
            return null;
        }
        if (is_array($expected)) {
            return [[$source, $field, 'in', array_map('strval', $expected)]];
        }
        if ($expected instanceof \stdClass) {
            $out = [];
            foreach (get_object_vars($expected) as $op => $value) {
                if (!in_array($op, self::OPERATORS, true)) {
                    $this->errors[] = "{$where}: unbekannter Operator \"{$op}\" (erlaubt: " . implode(', ', self::OPERATORS) . ')';
                    return null;
                }
                if ($op === 'regex' && @preg_match('~' . str_replace('~', '\~', (string) $value) . '~', '') === false) {
                    $this->errors[] = "{$where}: ungültiger regulärer Ausdruck \"{$value}\"";
                    return null;
                }
                $out[] = [$source, $field, $op, is_array($value) ? array_map('strval', $value) : $value];
            }
            return $out ?: null;
        }
        if ($expected === null) {
            return [[$source, $field, 'exists', false]];
        }
        return [[$source, $field, 'equals', is_bool($expected) ? ($expected ? 'true' : 'false') : (string) $expected]];
    }

    private function normalizeThen(mixed $then, string $where, ?array $responses): ?array
    {
        if (is_string($then)) {
            $file = str_ends_with($then, '.json') ? $then : $then . '.json';
            if ($responses !== null && !isset($responses[$file])) {
                $this->errors[] = "{$where}: Antwort \"{$then}\" existiert nicht";
                return null;
            }
            return ['file' => $file];
        }
        if (!$then instanceof \stdClass) {
            $this->errors[] = "{$where}: then fehlt (Dateiname oder Objekt)";
            return null;
        }
        $out = [];
        if (isset($then->file)) {
            $file = (string) $then->file;
            $file = str_ends_with($file, '.json') ? $file : $file . '.json';
            if ($responses !== null && !isset($responses[$file])) {
                $this->errors[] = "{$where}: Antwort \"{$then->file}\" existiert nicht";
                return null;
            }
            $out['file'] = $file;
        }
        if (isset($then->status)) {
            $status = (int) $then->status;
            if ($status < 100 || $status > 599) {
                $this->errors[] = "{$where}: status {$status} ungültig";
                return null;
            }
            $out['status'] = $status;
        }
        if (property_exists($then, 'body')) {
            $out['bodyJson'] = json_encode($then->body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
        }
        if (!isset($out['file']) && !isset($out['status'])) {
            $this->errors[] = "{$where}: then braucht file oder status";
            return null;
        }
        $headers = self::stringMap($then->headers ?? null);
        if ($headers) {
            $out['headers'] = $headers;
        }
        if (isset($then->delay)) {
            $out['delay'] = max(0, min(30000, (int) $then->delay));
        }
        return $out;
    }

    private function checkDuplicates(): void
    {
        $seen = [];
        foreach ($this->endpoints as $ep) {
            $key = $ep['method'] . ' ' . $ep['path'] . ' ' . ($ep['command'] ?? '');
            if (isset($seen[$key])) {
                $this->errors[] = "{$ep['id']}: gleiche Methode/Pfad/Command wie {$seen[$key]}";
            }
            $seen[$key] = $ep['id'];
        }
    }

    /** @param array<string, array> $responses */
    private static function pickDefault(array $responses): ?string
    {
        if (isset($responses['200.json'])) {
            return '200.json';
        }
        foreach ([false, true] as $expectationFiles) {
            foreach ($responses as $name => $r) {
                if ($r['status'] < 300 && ($r['erwartung'] ?? false) === $expectationFiles) {
                    return $name;
                }
            }
        }
        return array_key_first($responses);
    }

    /** @return array{0: string, 1: list<string>} */
    private static function compilePath(string $path): array
    {
        $params = [];
        $regex = preg_replace_callback(
            '/\{([A-Za-z0-9_]+)\}|[^{]+/',
            static function (array $m) use (&$params): string {
                if (isset($m[1]) && $m[1] !== '') {
                    $params[] = $m[1];
                    return '([^/]+)';
                }
                return preg_quote($m[0], '#');
            },
            $path,
        );
        return ['#^' . $regex . '$#', $params];
    }

    /** @return array<string, string> */
    private static function stringMap(mixed $value): array
    {
        $out = [];
        if ($value instanceof \stdClass) {
            foreach (get_object_vars($value) as $k => $v) {
                if (is_scalar($v)) {
                    $out[(string) $k] = (string) $v;
                }
            }
        }
        return $out;
    }
}
