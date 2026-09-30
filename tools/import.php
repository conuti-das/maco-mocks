<?php

declare(strict_types=1);

// Übernimmt Endpunkte und Beispiele aus dem Backup der API-Spezifikation (OpenAPI-Export) nach mocks/,
// optional auch die Mock-Erwartungen (Varianten je Parameter) aus dem API-Tool.
//
//   php tools/import.php <openapi.json> [--erwartungen <erwartungen.json>] [--stand <commit>] [--dry-run]
//
// Auswahl und Gruppen: tools/import.json. Der Import schreibt nur
//   mocks/<gruppe>/<endpunkt>/quelle/*    Antworten (<status>[-name].json) und Anfragebeispiele (anfrage*.json)
//   mocks/<gruppe>/<endpunkt>/quelle/erwartungen.json + <status>-erwartung-<name>.json   Mock-Erwartungen
//   mocks/<gruppe>/<endpunkt>/mock.json   Felder summary, method, path, command, parameters, quelle
// Regeln, default, set, paging und eigene Antwortdateien im Endpunkt-Ordner bleiben unangetastet.
// Mit "import": false in mock.json wird ein Endpunkt beim Import übersprungen.
// Die Erwartungs-Datei erzeugt tools/erwartungen-export.js im Browser. Ohne --erwartungen bleiben
// vorhandene Erwartungen stehen.

ini_set('memory_limit', '1G');

$args = parseArgs(array_slice($argv, 1));
$openapiFile = $args['_'][0] ?? null;
if ($openapiFile === null || !is_file($openapiFile)) {
    fwrite(STDERR, "Aufruf: php tools/import.php <openapi.json> [--erwartungen <erwartungen.json>] [--stand <commit>] [--dry-run]\n");
    exit(1);
}
$expectationsByKey = null;
$expectationsExport = null;
if (isset($args['erwartungen'])) {
    $expectationsExport = is_string($args['erwartungen']) && is_file($args['erwartungen'])
        ? json_decode((string) file_get_contents($args['erwartungen']), false)
        : null;
    if (!$expectationsExport instanceof stdClass || !is_array($expectationsExport->erwartungen ?? null)) {
        fwrite(STDERR, "Keine Erwartungs-Datei (tools/erwartungen-export.js): " . (is_string($args['erwartungen']) ? $args['erwartungen'] : '') . "\n");
        exit(1);
    }
    $expectationsByKey = [];
    foreach ($expectationsExport->erwartungen as $expectation) {
        $api = $expectation->api ?? null;
        if (!$api instanceof stdClass) {
            continue;
        }
        // Kopien (Pfad mit _ am Ende) gehören wie beim Import der Endpunkte zum Original
        $key = strtoupper((string) $api->method) . ' ' . rtrim((string) $api->path, '_') . ' ' . ($api->operationId ?? '');
        $expectationsByKey[$key][] = $expectation;
    }
}
$root = dirname(__DIR__);
$mocksDir = isset($args['mocks']) && is_string($args['mocks']) ? rtrim($args['mocks'], '/') : $root . '/mocks'; // --mocks nur für Tests
$config = json_decode((string) file_get_contents(__DIR__ . '/import.json'), true);
$dryRun = isset($args['dry-run']);
$stand = isset($args['stand']) && is_string($args['stand']) ? $args['stand'] : null;

$oas = json_decode((string) file_get_contents($openapiFile), false);
if (!$oas instanceof stdClass || !isset($oas->paths)) {
    fwrite(STDERR, "Keine OpenAPI-Datei: {$openapiFile}\n");
    exit(1);
}
$methods = ['get', 'post', 'put', 'patch', 'delete'];

// 1. Operationen einsammeln ----------------------------------------------------------
$all = [];
foreach (get_object_vars($oas->paths) as $exportPath => $item) {
    foreach ($methods as $method) {
        if (isset($item->{$method}) && $item->{$method} instanceof stdClass) {
            $all[] = ['exportPath' => (string) $exportPath, 'method' => strtoupper($method), 'op' => $item->{$method}];
        }
    }
}

// Beispiele je operationId + Statuscode – für Kopien ohne eigenes Beispiel
$examplesByOperation = [];
foreach ([true, false] as $selectedFirst) {
    foreach ($all as $entry) {
        if (isSelected($entry, $config['select']) !== $selectedFirst) {
            continue;
        }
        foreach (get_object_vars($entry['op']->responses ?? new stdClass()) as $code => $response) {
            $examples = collectExamples(pickContent($response->content ?? null));
            $key = ($entry['op']->operationId ?? '') . '|' . $code;
            if ($examples && !isset($examplesByOperation[$key])) {
                $examplesByOperation[$key] = $examples;
            }
        }
    }
}

// Auswahl, Suffixe von Kopien (…_ / …__) auflösen, Doppelte zusammenführen
$endpoints = [];
foreach ($all as $entry) {
    if (!isSelected($entry, $config['select'])) {
        continue;
    }
    $path = $entry['exportPath'];
    $base = rtrim($path, '_');
    if ($base !== $path && isset($oas->paths->{$base})) {
        $path = $base;
    }
    $key = $entry['method'] . ' ' . $path . ' ' . ($entry['op']->operationId ?? '');
    $isPrimary = $path === $entry['exportPath'];
    if (!isset($endpoints[$key]) || ($isPrimary && !$endpoints[$key]['primary'])) {
        $endpoints[$key] = $entry + ['path' => $path, 'primary' => $isPrimary];
    }
}

// 2. Vorhandene Endpunkt-Ordner (dürfen umbenannt/verschoben sein) --------------------
$existing = [];
foreach (findMockJson($mocksDir) as $relDir) {
    $mock = json_decode((string) file_get_contents("{$mocksDir}/{$relDir}/mock.json"), false);
    if ($mock instanceof stdClass) {
        $existing[strtoupper((string) ($mock->method ?? '')) . ' ' . ($mock->path ?? '') . ' ' . ($mock->command ?? '')] = $relDir;
    }
}

// 3. Schreiben ------------------------------------------------------------------------
$report = ['neu' => [], 'aktualisiert' => [], 'übersprungen' => [], 'ausSchema' => [], 'erwartungen' => 0, 'hinweise' => []];
$usedDirs = array_flip($existing);
$usedExpectationKeys = [];
foreach ($endpoints as $key => $entry) {
    $op = $entry['op'];
    $command = (string) ($op->operationId ?? '');
    $relDir = $existing[$key] ?? null;
    if ($relDir === null) {
        $slug = trim(preg_replace('/[^A-Za-z0-9._-]+/', '_', $entry['path']) ?? '', '_') ?: 'root';
        $relDir = groupOf($op, $config['groups']) . '/' . $slug;
        if (isset($usedDirs[$relDir])) {
            $relDir .= '.' . $command;
        }
        $usedDirs[$relDir] = $key;
    }
    $dir = "{$mocksDir}/{$relDir}";
    $mockFile = "{$dir}/mock.json";
    $mock = is_file($mockFile) ? json_decode((string) file_get_contents($mockFile), false) : null;
    if ($mock instanceof stdClass && ($mock->import ?? true) === false) {
        $report['übersprungen'][] = $relDir;
        continue;
    }
    $isNew = !$mock instanceof stdClass;

    // Antworten und Anfragebeispiele nach quelle/
    $files = [];
    $fromSchema = [];
    foreach (get_object_vars($op->responses ?? new stdClass()) as $code => $response) {
        $code = (string) $code;
        if (!preg_match('/^[1-5]\d\d$/', $code)) {
            continue;
        }
        $content = pickContent($response->content ?? null);
        $examples = collectExamples($content);
        if (!$examples && (int) $code < 300) {
            $examples = $examplesByOperation[$command . '|' . $code] ?? [];
        }
        if (count($examples) === 1) {
            $files["{$code}.json"] = $examples[0]['value'];
        } elseif ($examples) {
            foreach ($examples as $example) {
                $files["{$code}-{$example['name']}.json"] = $example['value'];
            }
        } else {
            $files["{$code}.json"] = isset($content->schema) ? sampleSchema($content->schema, $oas) : new stdClass();
            if ((int) $code < 300) {
                $fromSchema[] = "{$code}.json"; // Fehlerantworten sind in der Quelle meist bewusst {}
            }
        }
    }
    $requestExamples = collectExamples(pickContent($op->requestBody->content ?? null));
    foreach ($requestExamples as $example) {
        $files[count($requestExamples) === 1 ? 'anfrage.json' : "anfrage-{$example['name']}.json"] = $example['value'];
    }

    // Mock-Erwartungen (Varianten je Parameter)
    $keep = [];
    if ($expectationsByKey !== null) {
        $pathKey = $entry['method'] . ' ' . rtrim($entry['path'], '_') . ' ';
        $expectationKey = $pathKey . $command;
        $renamed = null;
        if (!isset($expectationsByKey[$expectationKey])) {
            // Command in der Quelle umbenannt? Dann über Methode + Pfad, wenn beides eindeutig ist
            $candidates = array_values(array_filter(array_keys($expectationsByKey), static fn ($k) => str_starts_with($k, $pathKey)));
            $samePath = array_filter($endpoints, static fn ($e) => $e['method'] . ' ' . rtrim($e['path'], '_') . ' ' === $pathKey);
            if (count($candidates) === 1 && count($samePath) === 1) {
                $expectationKey = $candidates[0];
                $renamed = substr($expectationKey, strlen($pathKey));
            }
        }
        $usedExpectationKeys[$expectationKey] = true;
        [$expectationRules, $expectationFiles, $notes] = convertExpectations($expectationsByKey[$expectationKey] ?? [], array_keys($files));
        if ($renamed !== null) {
            array_unshift($notes, "Command heißt im API-Tool inzwischen {$renamed} (OpenAPI-Stand: {$command}), Erwartungen trotzdem übernommen");
        }
        $files += $expectationFiles;
        if ($expectationRules) {
            $files['erwartungen.json'] = [
                'hinweis' => 'Importierte Mock-Erwartungen. Nicht von Hand ändern, eigene Regeln in mock.json haben Vorrang.',
                'exportiert' => $expectationsExport->exportiert ?? null,
                'erwartungen' => $expectationRules,
            ];
            $report['erwartungen'] += count($expectationRules);
        }
        foreach ($notes as $note) {
            $report['hinweise'][] = "{$relDir}: {$note}";
        }
    } else {
        $keep = keptExpectationFiles("{$dir}/quelle");
    }

    // mock.json: nur die importierten Felder setzen
    $mock = $isNew ? new stdClass() : $mock;
    $fresh = new stdClass();
    $fresh->summary = trim((string) ($op->summary ?? ''));
    $fresh->method = $entry['method'];
    $fresh->path = $entry['path'];
    $fresh->command = $command;
    if ($isNew) {
        $fresh->rules = [];
    }
    foreach (get_object_vars($fresh) as $k => $v) {
        if (!$isNew && $k === 'rules') {
            continue;
        }
        $mock->{$k} = $v;
    }
    $mock->parameters = array_map('describeParameter', $op->parameters ?? []);
    $meta = new stdClass();
    $meta->operationId = $command;
    if ($stand !== null) {
        $meta->stand = $stand;
    }
    if ($fromSchema) {
        $meta->ausSchema = $fromSchema;
    }
    $mock->quelle = $meta;

    if ($fromSchema) {
        $report['ausSchema'][] = "{$relDir}: " . implode(', ', $fromSchema);
    }
    $report[$isNew ? 'neu' : 'aktualisiert'][] = $relDir;
    if ($dryRun) {
        continue;
    }
    if (!is_dir("{$dir}/quelle")) {
        mkdir("{$dir}/quelle", 0775, true);
    }
    foreach (scandir("{$dir}/quelle") ?: [] as $old) {
        if (str_ends_with($old, '.json') && !isset($files[$old]) && !isset($keep[$old])) {
            unlink("{$dir}/quelle/{$old}"); // quelle/ gehört dem Import
        }
    }
    foreach ($files as $name => $value) {
        file_put_contents("{$dir}/quelle/{$name}", prettyJson($value));
    }
    file_put_contents($mockFile, prettyJson($mock));
}

$imported = array_flip(array_map(static fn ($k) => $k, array_keys($endpoints)));
$gone = array_values(array_diff_key($existing, $imported));

echo ($dryRun ? '[Probelauf] ' : '') . count($endpoints) . " Endpunkte aus der Quelle\n";
echo '  neu: ' . count($report['neu']) . ', aktualisiert: ' . count($report['aktualisiert']) . ', übersprungen: ' . count($report['übersprungen']) . "\n";
foreach ($report['ausSchema'] as $line) {
    echo "  ohne Beispiel (aus Schema erzeugt): {$line}\n";
}
foreach ($gone as $relDir) {
    echo "  nicht mehr in der Quelle (bleibt stehen, bitte prüfen): {$relDir}\n";
}
if ($expectationsByKey !== null) {
    echo '  Mock-Erwartungen: ' . $report['erwartungen'] . " übernommen\n";
    foreach ($report['hinweise'] as $line) {
        echo "  {$line}\n";
    }
    foreach (array_diff_key($expectationsByKey, $usedExpectationKeys) as $key => $list) {
        echo '  Erwartungen ohne importierten Endpunkt (nicht übernommen): ' . trim($key) . ' (' . count($list) . ")\n";
    }
}

// -------------------------------------------------------------------------------------

function isSelected(array $entry, array $sel): bool
{
    $op = $entry['op'];
    $tags = array_map('strval', $op->tags ?? []);
    $oid = (string) ($op->operationId ?? '');
    foreach ($tags as $tag) {
        foreach ($sel['excludeTagsContaining'] ?? [] as $needle) {
            if (str_contains($tag, $needle)) {
                return false;
            }
        }
    }
    if (!empty($sel['excludePathPattern']) && preg_match('#' . $sel['excludePathPattern'] . '#', $entry['exportPath'])) {
        return false;
    }
    if (in_array($oid, $sel['excludeOperationIds'] ?? [], true)) {
        return false;
    }
    if (array_intersect($tags, $sel['tags'] ?? []) || in_array($oid, $sel['operationIds'] ?? [], true)) {
        return true;
    }
    foreach ($sel['operationIdPrefixes'] ?? [] as $prefix) {
        if (str_starts_with($oid, $prefix)) {
            return true;
        }
    }
    return false;
}

function groupOf(stdClass $op, array $groups): string
{
    $oid = (string) ($op->operationId ?? '');
    $tags = array_map('strval', $op->tags ?? []);
    foreach ($groups as $group) {
        foreach ($group['operationIdPrefixes'] ?? [] as $prefix) {
            if (str_starts_with($oid, $prefix)) {
                return $group['name'];
            }
        }
        if (array_intersect($tags, $group['tags'] ?? [])) {
            return $group['name'];
        }
    }
    foreach ($groups as $group) {
        if (!empty($group['default'])) {
            return $group['name'];
        }
    }
    return 'sonstige';
}

function describeParameter(stdClass $p): stdClass
{
    $out = new stdClass();
    $out->in = (string) ($p->in ?? '');
    $out->name = (string) ($p->name ?? '');
    $out->required = (bool) ($p->required ?? false);
    $description = firstLine((string) ($p->description ?? ''));
    if ($description !== '') {
        $out->description = $description;
    }
    $schema = $p->schema ?? new stdClass();
    foreach (['type', 'format', 'enum'] as $k) {
        if (isset($schema->{$k})) {
            $out->{$k} = $schema->{$k};
        }
    }
    foreach ([$p->example ?? null, $schema->example ?? null, $schema->default ?? null] as $example) {
        if ($example !== null && $example !== '' && !(is_string($example) && str_contains($example, '{{'))) {
            $out->example = $example;
            break;
        }
    }
    return $out;
}

function pickContent(mixed $content): ?stdClass
{
    if (!$content instanceof stdClass) {
        return null;
    }
    foreach (['application/json', '*/*'] as $type) {
        if (isset($content->{$type}) && $content->{$type} instanceof stdClass) {
            return $content->{$type};
        }
    }
    foreach (get_object_vars($content) as $value) {
        return $value instanceof stdClass ? $value : null;
    }
    return null;
}

/** @return list<array{name: string, value: mixed}> */
function collectExamples(?stdClass $content): array
{
    if ($content === null) {
        return [];
    }
    $out = [];
    if (isset($content->examples) && $content->examples instanceof stdClass) {
        foreach (get_object_vars($content->examples) as $key => $example) {
            if (!$example instanceof stdClass || !property_exists($example, 'value')) {
                continue;
            }
            $label = trim((string) ($example->summary ?? ($example->description ?? $key)));
            $out[] = ['name' => slug($label) ?: slug((string) $key) ?: 'beispiel', 'value' => $example->value];
        }
    }
    if (!$out && property_exists($content, 'example')) {
        $out[] = ['name' => 'beispiel', 'value' => $content->example];
    }
    return $out;
}

// Beispiele wie {"$ref": "https://…"} verweisen auf externe Dateien – dann aus den Properties erzeugen.
function usable(mixed $value): bool
{
    if ($value === null) {
        return false;
    }
    return !($value instanceof stdClass && array_keys(get_object_vars($value)) === ['$ref']);
}

function sampleSchema(mixed $schema, stdClass $oas, int $depth = 0, array $seen = []): mixed
{
    if (!$schema instanceof stdClass || $depth > 8) {
        return null;
    }
    // Geschwister von $ref (z. B. boTyp: {$ref: BOTyp, default: "ENERGIEMENGE"}) haben Vorrang
    if (property_exists($schema, 'example') && usable($schema->example)) {
        return $schema->example;
    }
    if (isset($schema->examples) && is_array($schema->examples) && $schema->examples && usable($schema->examples[0])) {
        return $schema->examples[0];
    }
    if (property_exists($schema, 'default') && usable($schema->default)) {
        return $schema->default;
    }
    if (isset($schema->{'$ref'})) {
        $ref = (string) $schema->{'$ref'};
        $name = rawurldecode(substr($ref, strrpos($ref, '/') + 1));
        if (!str_starts_with($ref, '#/') || isset($seen[$name])) {
            return null;
        }
        $target = $oas->components->schemas->{$name} ?? null;
        return sampleSchema($target, $oas, $depth + 1, $seen + [$name => true]);
    }
    if (isset($schema->enum) && is_array($schema->enum) && $schema->enum) {
        return $schema->enum[0];
    }
    if (isset($schema->allOf) && is_array($schema->allOf)) {
        $merged = new stdClass();
        foreach ($schema->allOf as $part) {
            $value = sampleSchema($part, $oas, $depth + 1, $seen);
            if ($value instanceof stdClass) {
                foreach (get_object_vars($value) as $k => $v) {
                    $merged->{$k} = $v;
                }
            }
        }
        return $merged;
    }
    foreach (['oneOf', 'anyOf'] as $k) {
        if (isset($schema->{$k}) && is_array($schema->{$k}) && $schema->{$k}) {
            return sampleSchema($schema->{$k}[0], $oas, $depth + 1, $seen);
        }
    }
    $type = $schema->type ?? (isset($schema->properties) ? 'object' : (isset($schema->items) ? 'array' : null));
    if (is_array($type)) {
        $type = array_values(array_diff($type, ['null']))[0] ?? null;
    }
    switch ($type) {
        case 'object':
            $out = new stdClass();
            foreach (get_object_vars($schema->properties ?? new stdClass()) as $k => $v) {
                $out->{$k} = sampleSchema($v, $oas, $depth + 1, $seen);
            }
            return $out;
        case 'array':
            return isset($schema->items) ? [sampleSchema($schema->items, $oas, $depth + 1, $seen)] : [];
        case 'string':
            return match ($schema->format ?? null) {
                'date-time' => '2024-06-28T12:18:00Z',
                'date' => '2024-06-28',
                'uuid' => '00000000-0000-0000-0000-000000000000',
                default => 'string',
            };
        case 'integer':
        case 'number':
            return $schema->minimum ?? 0;
        case 'boolean':
            return true;
        default:
            return null;
    }
}

/** @return list<string> */
function findMockJson(string $root): array
{
    $found = [];
    if (!is_dir($root)) {
        return $found;
    }
    $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    foreach ($items as $item) {
        if ($item->getFilename() === 'mock.json') {
            $found[] = substr($item->getPath(), strlen($root) + 1);
        }
    }
    sort($found);
    return $found;
}

function prettyJson(mixed $value): string
{
    $json = json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    // 2 statt 4 Leerzeichen einrücken
    return preg_replace_callback('/^(?: {4})+/m', static fn ($m) => str_repeat('  ', intdiv(strlen($m[0]), 4)), (string) $json) . "\n";
}

function firstLine(string $text): string
{
    foreach (explode("\n", $text) as $line) {
        $line = trim(str_replace(['`', '*', '#', '>'], '', $line));
        if ($line !== '') {
            return mb_substr($line, 0, 200);
        }
    }
    return '';
}

function slug(string $text): string
{
    $text = strtr(mb_strtolower($text), ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss']);
    return substr(trim((string) preg_replace('/[^a-z0-9]+/', '-', $text), '-'), 0, 60);
}

// -------------------------------------------------------------------------------------
// Mock-Erwartungen

/**
 * Das API-Tool prüft Erwartungen von oben nach unten (Feld ordering, bei Gleichstand die neuere zuerst).
 * Sammel-Erwartungen – nur „Parameter vorhanden“ (z. B. "Default mit Platzhaltern") oder ganz ohne
 * Bedingung – kommen hier ans Ende. Sonst verdecken sie konkrete Varianten weiter unten in der Liste.
 *
 * @param list<stdClass> $list
 * @param list<string> $takenFiles
 * @return array{0: list<array>, 1: array<string, mixed>, 2: list<string>}
 */
function convertExpectations(array $list, array $takenFiles): array
{
    usort($list, static fn ($a, $b) => [(int) ($a->ordering ?? 0), -(int) $a->id] <=> [(int) ($b->ordering ?? 0), -(int) $b->id]);
    $groups = [[], [], []]; // konkret, nur "vorhanden", ohne Bedingung
    foreach ($list as $expectation) {
        $conditions = is_array($expectation->conditions ?? null) ? $expectation->conditions : [];
        $onlyExists = !array_filter($conditions, static fn ($c) => ($c->comparison ?? '') !== 'exists');
        $groups[!$conditions ? 2 : ($onlyExists ? 1 : 0)][] = $expectation;
    }

    $rules = [];
    $files = [];
    $notes = [];
    $taken = array_flip($takenFiles);
    foreach (array_merge(...$groups) as $expectation) {
        $name = trim((string) ($expectation->name ?? '')) ?: 'Erwartung ' . $expectation->id;
        $when = new stdClass();
        $problems = [];
        foreach (is_array($expectation->conditions ?? null) ? $expectation->conditions : [] as $condition) {
            $mapped = mapExpectationCondition($condition);
            if (is_string($mapped)) {
                $problems[] = $mapped;
            } elseif (property_exists($when, $mapped[0])) {
                $problems[] = "Feld {$mapped[0]} doppelt";
            } else {
                $when->{$mapped[0]} = $mapped[1];
            }
        }
        if ($problems) {
            $notes[] = "„{$name}“ nicht übernommen (" . implode(', ', $problems) . ')';
            continue;
        }

        $response = $expectation->response ?? new stdClass();
        [$body, $repair] = lenientJson((string) ($response->bodyData ?? ''));
        if ($repair === null) {
            $notes[] = "„{$name}“ nicht übernommen (Antwort ist kein JSON)";
            continue;
        }
        if ($repair !== '') {
            $notes[] = "„{$name}“: Antwort repariert ({$repair}), bitte im API-Tool korrigieren";
        }
        $status = (int) ($response->code ?? 200);
        $status = $status >= 100 && $status <= 599 ? $status : 200;
        $file = uniqueFileName("{$status}-erwartung-" . (slug($name) ?: 'id-' . $expectation->id), $taken);
        $taken[$file] = true;
        $files[$file] = $body;

        $then = $file;
        $headers = new stdClass();
        foreach (is_array($response->headers ?? null) ? $response->headers : [] as $header) {
            if (isset($header->name) && trim((string) $header->name) !== '') {
                $headers->{trim((string) $header->name)} = (string) ($header->value ?? '');
            }
        }
        $delay = (int) ($response->delay ?? 0);
        if (get_object_vars($headers) || $delay > 0) {
            $then = ['file' => $file] + (get_object_vars($headers) ? ['headers' => $headers] : []) + ($delay > 0 ? ['delay' => $delay] : []);
        }
        $rules[] = ['name' => $name, 'id' => (int) $expectation->id, 'when' => $when, 'then' => $then];
    }
    return [$rules, $files, $notes];
}

/**
 * Bedingung aus dem Export → Schlüssel und Wert für "when". Body-Felder: "feld", "a.b[0].c" oder JSONPath "$.a.b".
 *
 * @return array{0: string, 1: mixed}|string Fehlertext, wenn nicht abbildbar
 */
function mapExpectationCondition(mixed $condition): array|string
{
    if (!$condition instanceof stdClass) {
        return 'Bedingung unlesbar';
    }
    $location = (string) ($condition->location ?? '');
    $name = trim((string) ($condition->name ?? ''));
    $value = is_scalar($condition->value ?? null) ? (string) $condition->value : '';
    $comparison = (string) ($condition->comparison ?? '');
    $source = match ($location) {
        'query', 'header', 'body', 'path' => $location,
        default => null,
    };
    if ($source === null) {
        return "Ort {$location} nicht unterstützt";
    }
    if ($name === '') {
        return 'Parametername fehlt';
    }
    if ($source === 'body') {
        $name = (string) preg_replace('/^\$\.?/', '', $name);
        if ($name === '' || str_contains($name, '*') || str_contains($name, '..') || str_contains($name, '?(')) {
            return "Body-Pfad {$condition->name} nicht unterstützt";
        }
    }
    $key = "{$source}.{$name}";
    return match ($comparison) {
        'equal' => [$key, $value],
        'notEqual' => [$key, ['not' => $value]],
        'exists' => [$key, ['exists' => true]],
        'notExists', 'notExist' => [$key, ['exists' => false]],
        'include', 'contains' => [$key, ['contains' => $value]],
        'notInclude', 'notContains' => [$key, ['notContains' => $value]],
        'greaterThan', 'greater' => [$key, ['gt' => (float) $value]],
        'greaterOrEqual', 'greaterThanOrEqual' => [$key, ['gte' => (float) $value]],
        'lessThan', 'less' => [$key, ['lt' => (float) $value]],
        'lessOrEqual', 'lessThanOrEqual' => [$key, ['lte' => (float) $value]],
        'regex', 'regExp', 'match' => [$key, ['regex' => $value]],
        default => "Vergleich {$comparison} nicht unterstützt",
    };
}

/** @return array<string, true> Dateien bestehender Erwartungen, die ein Import ohne --erwartungen stehen lässt */
function keptExpectationFiles(string $sourceDir): array
{
    $file = "{$sourceDir}/erwartungen.json";
    if (!is_file($file)) {
        return [];
    }
    $keep = ['erwartungen.json' => true];
    $data = json_decode((string) file_get_contents($file), false);
    foreach (is_array($data->erwartungen ?? null) ? $data->erwartungen : [] as $rule) {
        $then = $rule->then ?? null;
        $name = is_string($then) ? $then : (string) ($then->file ?? '');
        if ($name !== '') {
            $keep[str_ends_with($name, '.json') ? $name : $name . '.json'] = true;
        }
    }
    return $keep;
}

/** @param array<string, mixed> $taken */
function uniqueFileName(string $base, array $taken): string
{
    $name = "{$base}.json";
    for ($i = 2; isset($taken[$name]); $i++) {
        $name = "{$base}-{$i}.json";
    }
    return $name;
}

/**
 * Das API-Tool nimmt es mit JSON nicht genau (Kommentare, Komma am Ende, fehlendes Komma). Erst streng lesen,
 * dann repariert.
 *
 * @return array{0: mixed, 1: ?string} Wert und Reparatur ('' = keine, null = nicht lesbar)
 */
function lenientJson(string $text): array
{
    $value = json_decode($text, false);
    if (json_last_error() === JSON_ERROR_NONE && trim($text) !== '') {
        return [$value, ''];
    }
    $fixes = [];
    $value = json_decode(repairJson($text, $fixes), false);
    if (json_last_error() !== JSON_ERROR_NONE || trim($text) === '') {
        return [null, null];
    }
    return [$value, implode(', ', array_keys($fixes))];
}

/** Entfernt Kommentare und Kommas vor } oder ], ergänzt fehlende Kommas zwischen Werten. */
function repairJson(string $s, array &$fixes): string
{
    $out = '';
    $len = strlen($s);
    $inString = false;
    $last = ''; // letztes Zeichen außerhalb von Strings (ohne Leerraum)
    $skip = static function (int $i) use ($s, $len): int {
        // Leerraum und Kommentare überspringen, Index des nächsten Zeichens
        while ($i < $len) {
            if (ctype_space($s[$i])) {
                $i++;
            } elseif ($s[$i] === '/' && ($s[$i + 1] ?? '') === '/') {
                $end = strpos($s, "\n", $i);
                $i = $end === false ? $len : $end;
            } elseif ($s[$i] === '/' && ($s[$i + 1] ?? '') === '*') {
                $end = strpos($s, '*/', $i + 2);
                $i = $end === false ? $len : $end + 2;
            } else {
                break;
            }
        }
        return $i;
    };
    for ($i = 0; $i < $len; $i++) {
        $c = $s[$i];
        if ($inString) {
            $out .= $c;
            if ($c === '\\' && $i + 1 < $len) {
                $out .= $s[++$i];
            } elseif ($c === '"') {
                $inString = false;
                $last = '"';
            }
            continue;
        }
        if ($c === '/' && (($s[$i + 1] ?? '') === '/' || ($s[$i + 1] ?? '') === '*')) {
            $i = $skip($i) - 1;
            $fixes['Kommentare entfernt'] = true;
            continue;
        }
        if (ctype_space($c)) {
            $out .= $c;
            continue;
        }
        if ($c === ',') {
            $next = $skip($i + 1);
            if ($next < $len && ($s[$next] === '}' || $s[$next] === ']')) {
                $fixes['Komma am Ende entfernt'] = true;
                continue;
            }
        } elseif (($c === '"' || $c === '{' || $c === '[') && ($last === '"' || $last === '}' || $last === ']' || $last === 'e' || $last === 'l' || ctype_digit($last))) {
            $out .= ',';
            $fixes['fehlendes Komma ergänzt'] = true;
        }
        $out .= $c;
        if ($c === '"') {
            $inString = true;
        } else {
            $last = $c;
        }
    }
    return $out;
}

function parseArgs(array $list): array
{
    $out = ['_' => []];
    for ($i = 0; $i < count($list); $i++) {
        if (str_starts_with($list[$i], '--')) {
            $key = substr($list[$i], 2);
            $out[$key] = isset($list[$i + 1]) && !str_starts_with($list[$i + 1], '--') ? $list[++$i] : true;
        } else {
            $out['_'][] = $list[$i];
        }
    }
    return $out;
}
